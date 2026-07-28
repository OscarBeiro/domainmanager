<?php

/**
 * -------------------------------------------------------------------------
 * Domain Manager plugin for GLPI
 * Copyright (C) 2026 by the TICGAL Team.
 * https://www.tic.gal
 * -------------------------------------------------------------------------
 * LICENSE
 * This file is part of the Domain Manager plugin.
 * Domain Manager plugin is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 * Domain Manager plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with Domain Manager. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @package   domainmanager
 * @author    the TICGAL team
 * @copyright Copyright (c) 2026 TICGAL team
 * @license   AGPL License 3.0 or (at your option) any later version
 *            http://www.gnu.org/licenses/agpl-3.0-standalone.html
 * @link      https://www.tic.gal
 * @since     2026
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Domainmanager;

use CronTask;
use Domain;
use Dropdown;
use GlpiPlugin\Domainmanager\Dto\RdapLookupResult;
use GlpiPlugin\Domainmanager\Exception\DriverException;
use GlpiPlugin\Domainmanager\Service\PluginLogger;
use GlpiPlugin\Domainmanager\Service\RdapClient;
use GlpiPlugin\Domainmanager\Service\RdapGapChecker;
use GlpiPlugin\Domainmanager\Service\SyncEngine;
use GlpiPlugin\Domainmanager\Service\SyncLogger;
use Supplier;
use Throwable;

class Cron
{
    // §9 Phase 22: how many never-/oldest-checked candidates to scan per
    // tick looking for one with an actual gap (RdapGapChecker) before
    // giving up as a clean no-op — bounded so a portfolio consisting
    // entirely of fully-enriched domains doesn't force a full table scan
    // every 10 minutes.
    private const RDAP_CANDIDATE_SCAN_LIMIT = 50;

    /**
     * Describe the plugin automatic actions
     *
     * @param  string $name
     * @return array
     */
    public static function cronInfo(string $name): array
    {
        switch ($name) {
            case 'DomainSync':
                return [
                    'description' => __('Synchronize domain lifecycle and DNS zone records from provider APIs', 'domainmanager'),
                    'parameter'   => __('Number of domains to process per run', 'domainmanager'),
                ];
            case 'RdapEnrichment':
                return [
                    'description' => __('Fill registrar-reported gaps (dates, lock/DNSSEC status, pending flags) from RDAP', 'domainmanager'),
                ];
        }

        return [];
    }

    /**
     * Daily domain synchronization batch (§5): active, non-deleted,
     * non-template domains, least-recently-synced first, batch size from
     * the task parameter, per-domain isolation
     *
     * @param  CronTask $task
     * @return int >0 done with actions, 0 nothing to do, <0 to be run again
     */
    public static function cronDomainSync(CronTask $task): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $batch_size = max(1, (int) ($task->fields['param'] ?? 20));

        $iterator = $DB->request([
            'SELECT'    => ['glpi_domains.id', 'glpi_domains.entities_id'],
            'FROM'      => 'glpi_domains',
            'LEFT JOIN' => [
                DomainState::getTable() => [
                    'ON' => [
                        DomainState::getTable() => 'domains_id',
                        'glpi_domains'          => 'id',
                    ],
                ],
            ],
            'WHERE'     => [
                'glpi_domains.is_deleted'  => 0,
                'glpi_domains.is_template' => 0,
                'glpi_domains.is_active'   => 1,
            ],
            'ORDER'     => DomainState::getTable() . '.last_sync_date ASC',
            'LIMIT'     => $batch_size,
        ]);

        $engine    = new SyncEngine();
        $logger    = new SyncLogger();
        $processed = 0;
        $errors    = 0;

        // §9 Phase 24: same per-entity breakdown convention as core's own
        // cron tasks (e.g. "close tickets"), plus a per-registrar-supplier
        // breakdown on top — extra debugging value specific to this plugin,
        // since a bad sync run is as likely to be one misbehaving supplier's
        // API as an entity-wide problem.
        $per_entity   = [];
        $per_supplier = [];

        foreach ($iterator as $row) {
            $domains_id  = (int) $row['id'];
            $entities_id = (int) $row['entities_id'];
            $is_error    = false;

            try {
                $domain = new Domain();
                if (!$domain->getFromDB($domains_id)) {
                    continue;
                }
                $result = $engine->sync($domain);
                if (
                    $result['registrar_status'] === DomainState::STATUS_ERROR
                    || $result['dns_status'] === DomainState::STATUS_ERROR
                ) {
                    $errors++;
                    $is_error = true;
                }
            } catch (Throwable $e) {
                $errors++;
                $is_error = true;
                $logger->detail(
                    'Cron sync failed for domain #' . $domains_id . ': ' . $e::class . ': ' . $e->getMessage()
                );
            }

            self::tally($per_entity, $entities_id, $is_error);
            $state = DomainState::getForDomain($domains_id);
            if ($state !== null && (int) $state->fields['registrar_suppliers_id'] > 0) {
                self::tally($per_supplier, (int) $state->fields['registrar_suppliers_id'], $is_error);
            }

            $processed++;
            $task->addVolume(1);
        }

        if ($processed > 0) {
            $task->log(sprintf('Synchronized %d domain(s), %d with errors', $processed, $errors));
            foreach ($per_entity as $entities_id => $tally) {
                $task->log(sprintf(
                    '%s: %d domain(s) synchronized (%d error(s))',
                    Dropdown::getDropdownName('glpi_entities', $entities_id),
                    $tally['count'],
                    $tally['errors']
                ));
            }
            foreach ($per_supplier as $suppliers_id => $tally) {
                $task->log(sprintf(
                    'Registrar %s: %d domain(s) synchronized (%d error(s))',
                    Dropdown::getDropdownName(Supplier::getTable(), $suppliers_id),
                    $tally['count'],
                    $tally['errors']
                ));
            }
            return 1;
        }

        $task->log('No active domain to synchronize');
        return 0;
    }

    /**
     * @param  array<int, array{count:int, errors:int}> $tallies
     * @param  int                                       $key
     * @param  bool                                      $is_error
     * @return void
     */
    private static function tally(array &$tallies, int $key, bool $is_error): void
    {
        if (!isset($tallies[$key])) {
            $tallies[$key] = ['count' => 0, 'errors' => 0];
        }
        $tallies[$key]['count']++;
        if ($is_error) {
            $tallies[$key]['errors']++;
        }
    }

    /**
     * RDAP gap-fill batch (§9 Phase 22): exactly one domain per execution —
     * the cron design's own rate-limit defense against `rdap.org` (§9 Phase
     * 21 "Rate-limit rationale": 1-per-10-minutes is nowhere near its
     * 10-req/10s limit, no client-side throttling needed beyond this).
     * Scans candidates oldest-/never-checked-first, skipping any without an
     * actual field gap ({@see RdapGapChecker}), and stops at the first
     * genuine gap found.
     *
     * @param  CronTask $task
     * @return int >0 done with actions, 0 nothing to do, <0 to be run again
     */
    public static function cronRdapEnrichment(CronTask $task): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $today = date('Y-m-d');

        $iterator = $DB->request([
            'SELECT'    => ['glpi_domains.id', 'glpi_domains.name', 'glpi_domains.entities_id'],
            'FROM'      => 'glpi_domains',
            'LEFT JOIN' => [
                DomainState::getTable() => [
                    'ON' => [
                        DomainState::getTable() => 'domains_id',
                        'glpi_domains'          => 'id',
                    ],
                ],
            ],
            'WHERE'     => [
                'glpi_domains.is_deleted'  => 0,
                'glpi_domains.is_template' => 0,
                'OR'                       => [
                    [DomainState::getTable() . '.last_rdap_check_date' => null],
                    [DomainState::getTable() . '.last_rdap_check_date' => ['<', $today . ' 00:00:00']],
                ],
            ],
            'ORDER'     => DomainState::getTable() . '.last_rdap_check_date ASC',
            'LIMIT'     => self::RDAP_CANDIDATE_SCAN_LIMIT,
        ]);

        $client = new RdapClient();

        foreach ($iterator as $row) {
            $domains_id = (int) $row['id'];
            if (!RdapGapChecker::hasGap($domains_id)) {
                continue;
            }

            $outcome = 'no data (TLD/domain not covered)';
            try {
                $outcome = self::processRdapEnrichment($domains_id, (string) $row['name'], $client);
            } catch (Throwable $e) {
                $outcome = 'error: ' . $e::class . ': ' . $e->getMessage();
                PluginLogger::error(
                    'RDAP enrichment failed for domain #' . $domains_id,
                    $e::class . ': ' . $e->getMessage()
                );
            }

            // §9 Phase 24: same per-entity/per-registrar debugging
            // convention added to cronDomainSync — even though this task
            // only ever touches one domain per tick, naming its entity and
            // registrar here (rather than just an id) makes the automatic
            // action's log immediately useful without cross-referencing
            // the domain record.
            $state        = DomainState::getForDomain($domains_id);
            $supplier_name = ($state !== null && (int) $state->fields['registrar_suppliers_id'] > 0)
                ? Dropdown::getDropdownName(Supplier::getTable(), (int) $state->fields['registrar_suppliers_id'])
                : __('no registrar', 'domainmanager');

            $task->addVolume(1);
            $task->log(sprintf(
                '%s: domain #%d (%s), registrar %s — %s',
                Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
                $domains_id,
                $row['name'],
                $supplier_name,
                $outcome
            ));
            return 1;
        }

        $task->log('No domain eligible for RDAP enrichment');
        return 0;
    }

    /**
     * One domain's RDAP lookup + gap-fill (§9 Phase 22). A 404
     * ({@see RdapLookupResult::notFound()}) is the normal "no RDAP data for
     * this TLD/domain" outcome: `last_rdap_check_date` is still stamped so
     * the domain isn't retried until tomorrow, and nothing else is touched.
     *
     * @param  int         $domains_id
     * @param  string      $fqdn
     * @param  RdapClient  $client
     * @return string a short human-readable outcome, for the cron task's own log
     *         ({@see self::cronRdapEnrichment}) — distinct from PluginLogger's
     *         file-based activity log, which still gets its own line either way
     * @throws DriverException on a genuine lookup failure (network error,
     *         non-404 non-2xx, unparsable body) — caller logs to
     *         domainmanager-errors.log, distinct from the 404 no-data case
     */
    private static function processRdapEnrichment(int $domains_id, string $fqdn, RdapClient $client): string
    {
        $state    = DomainState::getForDomain($domains_id);
        $isFirst  = $state === null || $state->fields['last_rdap_check_date'] === null;
        $now      = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $result = $client->lookup($fqdn);

        if (!$result->found) {
            self::upsertState($domains_id, $state, ['last_rdap_check_date' => $now]);
            PluginLogger::activity('RDAP: no data for domain #' . $domains_id . ' (' . $fqdn . '), TLD/domain not covered');
            return 'no data (TLD/domain not covered)';
        }

        $gaps = RdapGapChecker::getGaps($domains_id);

        if (
            in_array(RdapGapChecker::GAP_REGISTRATION_DATE, $gaps, true)
            && $result->registrationDate !== null
        ) {
            $domain = new Domain();
            if ($domain->getFromDB($domains_id)) {
                $domain->update([
                    'id'                   => $domains_id,
                    'date_domaincreation'  => $result->registrationDate->format('Y-m-d H:i:s'),
                ]);
            }
        }
        if (
            in_array(RdapGapChecker::GAP_EXPIRATION_DATE, $gaps, true)
            && $result->expirationDate !== null
        ) {
            $domain = new Domain();
            if ($domain->getFromDB($domains_id)) {
                $domain->update([
                    'id'              => $domains_id,
                    'date_expiration' => $result->expirationDate->format('Y-m-d H:i:s'),
                ]);
            }
        }

        $state_input = ['last_rdap_check_date' => $now];

        if (in_array(RdapGapChecker::GAP_LAST_CHANGED, $gaps, true) && $result->lastChangedDate !== null) {
            $state_input['rdap_last_changed_date'] = $result->lastChangedDate->format('Y-m-d H:i:s');
        }
        if (in_array(RdapGapChecker::GAP_TRANSFER_DATE, $gaps, true) && $result->transferDate !== null) {
            $state_input['rdap_transfer_date'] = $result->transferDate->format('Y-m-d H:i:s');
        }
        if (in_array(RdapGapChecker::GAP_PENDING_DELETE, $gaps, true) && $result->pendingDelete !== null) {
            $state_input['rdap_pending_delete'] = (int) $result->pendingDelete;
        }
        if (in_array(RdapGapChecker::GAP_PENDING_TRANSFER, $gaps, true) && $result->pendingTransfer !== null) {
            $state_input['rdap_pending_transfer'] = (int) $result->pendingTransfer;
        }
        if (in_array(RdapGapChecker::GAP_DNSSEC, $gaps, true) && $result->dnssecSigned !== null) {
            $state_input['rdap_dnssec_signed'] = (int) $result->dnssecSigned;
        }
        if (in_array(RdapGapChecker::GAP_TRANSFER_LOCK, $gaps, true) && $result->transferLock !== null) {
            $state_input['rdap_transfer_lock'] = (int) $result->transferLock;
        }
        if (in_array(RdapGapChecker::GAP_DOMAIN_LOCK, $gaps, true) && $result->domainLock !== null) {
            $state_input['rdap_domain_lock'] = (int) $result->domainLock;
        }

        // §9 Phase 22 "Registrar-of-record and nameserver fields are
        // populated unconditionally" — never gap-gated (no driver reports
        // these today), but purely informational: never touches
        // Infocom::suppliers_id or DomainRecord/ImportLock.
        if ($result->registrarName !== null) {
            $state_input['rdap_registrar_name'] = $result->registrarName;
        }
        if ($result->registrarIanaId !== null) {
            $state_input['rdap_registrar_iana_id'] = $result->registrarIanaId;
        }
        if ($result->nameservers !== []) {
            $state_input['rdap_nameservers'] = json_encode($result->nameservers);
        }

        self::upsertState($domains_id, $state, $state_input);

        PluginLogger::activity('RDAP: enriched domain #' . $domains_id . ' (' . $fqdn . ') via RDAP');

        if ($isFirst && $result->notices !== []) {
            PluginLogger::activity(
                'RDAP notices for domain #' . $domains_id . ' (' . $fqdn . '): ' . implode(' | ', $result->notices)
            );
        }

        $filled = array_values(array_diff(array_keys($state_input), ['last_rdap_check_date']));

        return $filled === []
            ? 'no new data from RDAP'
            : 'filled ' . implode(', ', $filled);
    }

    /**
     * @param  int          $domains_id
     * @param  ?DomainState $state      existing state row, if any
     * @param  array        $input      fields to set/merge
     * @return void
     */
    private static function upsertState(int $domains_id, ?DomainState $state, array $input): void
    {
        if ($state !== null) {
            $state->update(['id' => $state->getID()] + $input);
        } else {
            (new DomainState())->add(['domains_id' => $domains_id] + $input);
        }
    }
}
