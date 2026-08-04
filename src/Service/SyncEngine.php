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

namespace GlpiPlugin\Domainmanager\Service;

use Domain;
use GlpiPlugin\Domainmanager\Contract\DnsRecordCommentSyncInterface;
use GlpiPlugin\Domainmanager\Contract\DnsRecordWriterInterface;
use GlpiPlugin\Domainmanager\Dto\LifecycleStatus;
use GlpiPlugin\Domainmanager\DomainState;
use GlpiPlugin\Domainmanager\DriverFactory;
use GlpiPlugin\Domainmanager\DriverRegistry;
use GlpiPlugin\Domainmanager\Exception\SyncSafetyExceededException;
use GlpiPlugin\Domainmanager\Exception\DriverException;
use GlpiPlugin\Domainmanager\ImportLock;
use GlpiPlugin\Domainmanager\LockEnforcer;
use GlpiPlugin\Domainmanager\NsProviderRegistry;
use GlpiPlugin\Domainmanager\SupplierConfig;
use Infocom;
use Throwable;

/**
 * Orchestrates the split registrar/DNS pipeline for one domain (§5).
 * The two legs are isolated: a failure in one never aborts the other.
 */
class SyncEngine
{
    public function __construct(
        private NsResolver $resolver = new NsResolver(),
        private SyncLogger $logger = new SyncLogger(),
        private RecordReconciler $reconciler = new RecordReconciler(),
    ) {
    }

    /**
     * Run both pipelines for a domain and persist the outcome in its state row
     *
     * @param  Domain $domain
     * @param  bool   $isImport ARCHITECTURE.md §14.2 (Phase 47): true only
     *                          when this call's state row (if newly created
     *                          here) should be marked `is_glpi_created = 0`
     *                          — set by `DomainImportController` for its
     *                          bulk-import path; every other caller (manual
     *                          Domain creation's first sync, cron,
     *                          MassiveActionHandler, SyncController) leaves
     *                          this false, so a newly-created state row
     *                          defaults to "Native". Ignored entirely when
     *                          the domain already has a state row — this
     *                          field is set once, at creation, never again.
     * @param  bool   $forceDnsReconcile ARCHITECTURE.md §15.3 Phase 55:
     *                          bypass the reconciliation sync safety guard
     *                          for this run. Only ever set true by an
     *                          explicit operator override of a run that
     *                          previously returned
     *                          `DomainState::STATUS_SYNC_SAFETY_GUARD` —
     *                          every other caller leaves this false.
     * @return array{registrar_status: string, dns_status: string,
     *               registrar_message: string, dns_message: string,
     *               detected_provider: string, last_sync_date: string,
     *               registrar_auth_info: ?string, registrar_privacy_enabled: ?int,
     *               registrar_domain_lock: ?int, registrar_transfer_lock: ?int,
     *               registrar_auto_renew: ?int, registrar_domain_type: ?string,
     *               registrar_dnssec_enabled: ?int}
     */
    public function sync(Domain $domain, bool $isImport = false, bool $forceDnsReconcile = false): array
    {
        $state = DomainState::getForDomain((int) $domain->getID());
        $fqdn  = (string) $domain->fields['name'];
        $now   = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        // §9: snapshot taken before syncRegistrarLeg()'s own replaceLocks()
        // call below can run — that call wipes and rewrites the Domain's
        // *entire* lock set with whatever this run's registrar leg itself
        // supplies, which would otherwise silently erase a
        // `date_domaincreation`/`date_expiration` lock that Cron's RDAP
        // gap-fill had set on a prior run for a driver that never reports
        // one of those itself (e.g. IONOS — see IonosDriver.php).
        $previously_locked = ImportLock::getLockedFieldNames(Domain::class, (int) $domain->getID());

        // Read live, not from the state row's own (possibly stale) mirror:
        // Infocom's "Supplier" field (§0.1) is the single source of truth
        // for the registrar, and every sync corrects the mirror to match
        // it below (state upsert) — otherwise a domain whose Infocom
        // assignment predates/missed HookHandler::infocomSaved()'s mirror
        // would stay permanently "unconfigured" no matter how many times
        // it's synced (§9 Phase 5.5).
        $registrar_id = 0;
        $infocom      = new Infocom();
        if ($infocom->getFromDBByCrit(['itemtype' => Domain::class, 'items_id' => (int) $domain->getID()])) {
            $registrar_id = (int) $infocom->fields['suppliers_id'];
        }

        $detected   = $state !== null ? (string) $state->fields['detected_provider'] : '';
        $dns_config = null;

        $result = [
            'registrar_status'  => DomainState::STATUS_UNCONFIGURED,
            'registrar_message' => '',
            'dns_status'        => DomainState::STATUS_ERROR,
            'dns_message'       => '',
            'detected_provider' => $detected,
            'last_sync_date'    => $now,
            // §9 Phase 7: null unless syncRegistrarLeg() below actually
            // fetches a fresh lifecycle — a leg that never ran (no
            // supplier, inactive supplier, error) has nothing to report,
            // same as every other registrar_* result field here.
            'registrar_auth_info'       => null,
            'registrar_privacy_enabled' => null,
            'registrar_domain_lock'     => null,
            'registrar_transfer_lock'   => null,
            'registrar_auto_renew'      => null,
            'registrar_domain_type'     => null,
            'registrar_dnssec_enabled'  => null,
        ];

        LockEnforcer::$sync_in_progress = true;
        try {
            // 1-2. NS detection → DNS pipeline candidate
            // Skipped entirely without a registrar: an unmanaged domain's
            // live public NS records (if it even resolves) belong to
            // whoever actually controls it, not to us, so reporting a
            // "detected provider" here would be meaningless noise.
            if ($registrar_id === 0) {
                $result['detected_provider'] = '';
                $result['dns_status']        = DomainState::STATUS_UNCONFIGURED;
                $result['dns_message']       = __('No registrar supplier configured', 'domainmanager');
            } else {
                $ns_hosts = $this->resolver->getNameservers($fqdn);
                if ($ns_hosts === []) {
                    $result['dns_status']  = DomainState::STATUS_ERROR;
                    $result['dns_message'] = __('NS lookup failed', 'domainmanager');
                } else {
                    $provider = NsProviderRegistry::match($ns_hosts);
                    if ($provider === null) {
                        $result['detected_provider'] = NsProviderRegistry::PROVIDER_UNKNOWN;
                        $result['dns_status']        = DomainState::STATUS_UNKNOWN;
                        $result['dns_message']       = sprintf(
                            __('DNS provider not recognized from nameservers: %s', 'domainmanager'),
                            implode(', ', array_slice($ns_hosts, 0, 4)),
                        );
                    } elseif (!isset($provider['driver'])) {
                        $result['detected_provider'] = $provider['name'];
                        $result['dns_status']        = DomainState::STATUS_UNSUPPORTED;
                        $result['dns_message']       = sprintf(
                            __('API integration for %s is not currently supported', 'domainmanager'),
                            $provider['name'],
                        );
                    } else {
                        $result['detected_provider'] = $provider['name'];
                        $dns_config = $this->findSupplierConfigForDriver($provider['driver']);
                        if ($dns_config === null) {
                            $result['dns_status']  = DomainState::STATUS_UNCONFIGURED;
                            $result['dns_message'] = sprintf(
                                __('No supplier is configured with the %s driver', 'domainmanager'),
                                $provider['name'],
                            );
                        }
                    }
                }
            }

            // 3. Registrar leg (isolated)
            $this->syncRegistrarLeg($domain, $registrar_id, $result);

            // 4. DNS leg (isolated)
            // §14.2 (Phase 47 write gate): if this domain's DNS records were
            // already managed (is_managed) under a *different* resolved
            // supplier than the one just detected, do not fetch/reconcile
            // at all this run — silently trashing the previous supplier's
            // owned records and recreating them under the new one would be
            // a real, unreviewed data migration. Block once; the new
            // supplier id is still persisted below, so a deliberate second
            // sync run (nothing else needs to change) confirms and applies
            // it, same "re-sync to confirm" pattern STATUS_REASSIGNED
            // already uses for a Registrar change.
            $previous_dns_suppliers_id = $state !== null ? (int) $state->fields['dns_suppliers_id'] : 0;
            $source_conflict = $dns_config !== null
                && $state !== null
                && (bool) $state->fields['is_managed']
                && $previous_dns_suppliers_id > 0
                && $previous_dns_suppliers_id !== (int) $dns_config->fields['suppliers_id'];

            if ($source_conflict) {
                $result['dns_status']  = DomainState::STATUS_SOURCE_CONFLICT;
                $result['dns_message'] = sprintf(
                    __('DNS provider changed from supplier #%1$d to #%2$d; records were left untouched. Re-run synchronization to confirm and apply this change.', 'domainmanager'),
                    $previous_dns_suppliers_id,
                    (int) $dns_config->fields['suppliers_id'],
                );
                $this->logger->skip(
                    (int) $domain->getID(),
                    "DNS sync skipped: resolved supplier changed from #$previous_dns_suppliers_id to #" . (int) $dns_config->fields['suppliers_id'] . ' (source conflict, pending confirmation)',
                );
            } elseif ($dns_config !== null) {
                $this->syncDnsLeg($domain, $dns_config, $result, $forceDnsReconcile);
            }
        } finally {
            LockEnforcer::$sync_in_progress = false;
        }

        // 5. State upsert
        // §9 Phase 14: "Managed" is true the moment either leg resolved to a
        // real, driver-backed, active supplier — STATUS_OK/STATUS_ERROR are
        // the only two outcomes reachable *after* a leg's pre-flight checks
        // passed (see syncRegistrarLeg()/syncDnsLeg() above and
        // DomainState::resolvesToActiveDriver()'s docblock), so this is
        // independent of whether the live API call itself then succeeded.
        $resolved_statuses = [DomainState::STATUS_OK, DomainState::STATUS_ERROR];
        $is_managed = in_array($result['registrar_status'], $resolved_statuses, true)
            || in_array($result['dns_status'], $resolved_statuses, true);

        // §9: `domaintypes_id` is set once by `DomainImportController` (only
        // when the admin configured a "domain type to apply to imported
        // domains"), independent of either sync leg — locked via `setLock()`
        // rather than folded into `syncRegistrarLeg()`'s own `replaceLocks()`
        // call, since that call wipes and rewrites the *entire* Domain lock
        // set and only runs when the registrar leg succeeds (a DNS-only
        // managed domain would never see it otherwise). Cleared the moment
        // the domain stops being managed or the field is left unset, so a
        // once-imported domain that later loses its provider isn't left
        // permanently locked out of hand-editing its type.
        $domaintypes_id = (int) ($domain->fields['domaintypes_id'] ?? 0);
        if ($is_managed && $domaintypes_id > 0) {
            ImportLock::setLock(Domain::class, (int) $domain->getID(), 'domaintypes_id', $domaintypes_id);
        } else {
            ImportLock::clearLock(Domain::class, (int) $domain->getID(), 'domaintypes_id');
        }

        // §9: restore an RDAP-set date lock this run's registrar leg wiped
        // (see $previously_locked's docblock above) — a no-op whenever the
        // registrar leg itself already relocked the field with the same
        // value, since setLock() is idempotent.
        foreach (['date_domaincreation', 'date_expiration'] as $date_field) {
            $value = $domain->fields[$date_field] ?? null;
            if ($is_managed && !empty($value) && in_array($date_field, $previously_locked, true)) {
                ImportLock::setLock(Domain::class, (int) $domain->getID(), $date_field, $value);
            }
        }

        $state_input = [
            'registrar_suppliers_id' => $registrar_id,
            'dns_suppliers_id'  => $dns_config !== null ? (int) $dns_config->fields['suppliers_id'] : 0,
            'detected_provider' => $result['detected_provider'],
            'registrar_status'  => $result['registrar_status'],
            'registrar_message' => $result['registrar_message'],
            'dns_status'        => $result['dns_status'],
            'dns_message'       => $result['dns_message'],
            'is_managed'        => (int) $is_managed,
            'last_sync_date'    => $now,
        ];

        // §9 Phase 27: only touch a registrar-metadata field when *this*
        // sync's registrar leg actually reported a value for it — leave it
        // untouched (not overwritten with null) otherwise. Previously these
        // were always included, unconditionally nulling any field the
        // current driver doesn't support on every single sync; harmless on
        // its own, but it would silently erase whatever RDAP's gap-fill
        // (`Cron::processRdapEnrichment()`) had just written into the same
        // column, since those two write to the exact same
        // registrar_transfer_lock/registrar_domain_lock/registrar_dnssec_enabled
        // fields RDAP fills the gap on (§9 Phase 26/27 — no more separate
        // rdap_* shadow columns for these three). Trade-off accepted: a
        // value from a previous registrar (or a stale RDAP fill) can now
        // persist display-wise until something actually overwrites it —
        // no code path currently resets these to null on registrar
        // reassignment/unlink either, so this was already the de facto
        // behavior for every field that isn't part of the reassignment's
        // own explicit reset logic.
        foreach (
            [
                'registrar_auth_info',
                'registrar_privacy_enabled',
                'registrar_domain_lock',
                'registrar_transfer_lock',
                'registrar_auto_renew',
                'registrar_domain_type',
                'registrar_dnssec_enabled',
            ] as $field
        ) {
            if ($result[$field] !== null) {
                $state_input[$field] = $result[$field];
            }
        }

        // §12.3 (Phase 42): `dns_write_status` starts at `managed_readonly`
        // the moment this sync recognizes a write-capable driver for the
        // domain, and resets to it again if the resolved DNS supplier
        // changes — a nameserver move can point the same domain at a
        // different account/zone the current write history says nothing
        // about. It is otherwise left untouched here: a successful *read*
        // is explicitly not a reset trigger (only a real write attempt,
        // via `DomainState::recordWriteOutcome()`, ever moves it to
        // `managed_editable` or back). A driver that isn't write-capable at
        // all (or no resolved DNS config) always reads as `manual`.
        $is_write_capable = false;
        if ($dns_config !== null) {
            try {
                $is_write_capable = DriverFactory::forDns($dns_config) instanceof DnsRecordWriterInterface;
            } catch (Throwable) {
                $is_write_capable = false;
            }
        }

        if (!$is_write_capable) {
            $state_input['dns_write_status']  = DomainState::DNS_WRITE_MANUAL;
            $state_input['dns_write_message'] = null;
        } else {
            $new_dns_suppliers_id = (int) $dns_config->fields['suppliers_id'];
            $previous_dns_suppliers_id = $state !== null ? (int) ($state->fields['dns_suppliers_id'] ?? 0) : 0;
            $previous_write_status = $state !== null
                ? (string) ($state->fields['dns_write_status'] ?? DomainState::DNS_WRITE_MANUAL)
                : DomainState::DNS_WRITE_MANUAL;

            if ($state === null || $previous_dns_suppliers_id !== $new_dns_suppliers_id || $previous_write_status === DomainState::DNS_WRITE_MANUAL) {
                $state_input['dns_write_status']  = DomainState::DNS_WRITE_READONLY;
                $state_input['dns_write_message'] = null;
            }
        }

        if ($state !== null) {
            $state->update(['id' => $state->getID()] + $state_input);
        } else {
            // §14.2 (Phase 47): set once, only on the row's first creation —
            // an update never carries this key, so an already-existing
            // state row's value is never touched by a later sync.
            $state_input['is_glpi_created'] = $isImport ? 0 : 1;
            (new DomainState())->add(['domains_id' => $domain->getID()] + $state_input);
        }

        return $result;
    }

    /**
     * @param  Domain $domain
     * @param  int    $registrar_id live Infocom suppliers_id, 0 if none
     * @param  array  $result
     * @return void
     */
    private function syncRegistrarLeg(Domain $domain, int $registrar_id, array &$result): void
    {
        try {
            if ($registrar_id > 0 && !SupplierConfig::isSupplierActive($registrar_id)) {
                $result['registrar_status']  = DomainState::STATUS_SUPPLIER_INACTIVE;
                $result['registrar_message'] = __('Registrar supplier is inactive; synchronization skipped', 'domainmanager');
                $this->logger->skip(
                    (int) $domain->getID(),
                    'Registrar sync skipped: supplier #' . $registrar_id . ' is inactive',
                );
                return;
            }

            $config = $registrar_id > 0 ? SupplierConfig::getForSupplier($registrar_id) : null;

            if (
                $config === null
                || $config->fields['api_driver'] === DriverRegistry::DRIVER_NONE
                || $config->getDecryptedCredentials() === []
            ) {
                $result['registrar_status']  = DomainState::STATUS_UNCONFIGURED;
                $result['registrar_message'] = __('No supplier with API access configured', 'domainmanager');
                return;
            }

            $lifecycle = DriverFactory::forRegistrar($config)->fetchLifecycle((string) $domain->fields['name']);
            $this->logger->activity(
                (int) $domain->getID(),
                'Registrar fetch succeeded, lifecycle status: ' . $lifecycle->status->value,
            );

            $updates = ['is_active' => $lifecycle->status === LifecycleStatus::Ok ? 1 : 0];
            if ($lifecycle->registrationDate !== null) {
                $updates['date_domaincreation'] = $lifecycle->registrationDate->format('Y-m-d H:i:s');
            }
            if ($lifecycle->expirationDate !== null) {
                $updates['date_expiration'] = $lifecycle->expirationDate->format('Y-m-d H:i:s');
            }

            $domain->update(['id' => $domain->getID()] + $updates);

            // Refresh the lock set with everything the sync owns (name confirmed as-is)
            ImportLock::replaceLocks(
                Domain::class,
                (int) $domain->getID(),
                ['name' => $domain->fields['name']] + $updates,
            );

            // §9 Phase 7: plugin-owned state columns, not native Domain
            // fields — no ImportLock entry needed (see Installer's
            // addRegistrarMetadataColumns() docblock). Cast nullable bools
            // to int (0/1)/null explicitly, matching this codebase's own
            // existing convention for every other tinyint column
            // (`is_active` just above, `is_managed` in Installer) — a raw
            // PHP `true`/`false` written straight into a CommonDBTM update
            // input was found live to be silently dropped (persisted as
            // NULL) instead of 1/0, while int/string/null values in the
            // same update() call persisted correctly.
            $result['registrar_auth_info']       = $lifecycle->authInfo;
            $result['registrar_privacy_enabled'] = self::toNullableInt($lifecycle->privacyEnabled);
            $result['registrar_domain_lock']     = self::toNullableInt($lifecycle->domainLock);
            $result['registrar_transfer_lock']   = self::toNullableInt($lifecycle->transferLock);
            $result['registrar_auto_renew']      = self::toNullableInt($lifecycle->autoRenew);
            $result['registrar_domain_type']     = $lifecycle->domainType;
            $result['registrar_dnssec_enabled']  = self::toNullableInt($lifecycle->dnsSecEnabled);

            $result['registrar_status']  = DomainState::STATUS_OK;
            $result['registrar_message'] = sprintf(
                __('Lifecycle synchronized (status: %s)', 'domainmanager'),
                $lifecycle->status->value,
            );
            $this->logger->milestone((int) $domain->getID(), 'Registrar sync OK');
        } catch (DriverException $e) {
            $result['registrar_status']  = DomainState::STATUS_ERROR;
            $result['registrar_message'] = $e->getMessage();
            $this->logger->detail('Registrar leg failed for domain #' . $domain->getID() . ': ' . $e->getMessage());
        } catch (Throwable $e) {
            $result['registrar_status']  = DomainState::STATUS_ERROR;
            $result['registrar_message'] = __('Unexpected registrar synchronization error', 'domainmanager');
            $this->logger->detail(
                'Registrar leg exception for domain #' . $domain->getID() . ': '
                . $e::class . ': ' . $e->getMessage(),
            );
        }
    }

    /**
     * @param  Domain         $domain
     * @param  SupplierConfig $config
     * @param  array          $result
     * @return void
     */
    private function syncDnsLeg(Domain $domain, SupplierConfig $config, array &$result, bool $force = false): void
    {
        try {
            $dns_suppliers_id = (int) $config->fields['suppliers_id'];
            if (!SupplierConfig::isSupplierActive($dns_suppliers_id)) {
                $result['dns_status']  = DomainState::STATUS_SUPPLIER_INACTIVE;
                $result['dns_message'] = __('DNS supplier is inactive; synchronization skipped', 'domainmanager');
                $this->logger->skip(
                    (int) $domain->getID(),
                    'DNS sync skipped: supplier #' . $dns_suppliers_id . ' is inactive',
                );
                return;
            }

            // §0.4: detect the native manageable-record-types gate up front
            // instead of half-importing under a restricted web session
            $unmanageable = $this->reconciler->getUnmanageableTypeNames();
            if ($unmanageable !== []) {
                $result['dns_status']  = DomainState::STATUS_ERROR;
                $result['dns_message'] = sprintf(
                    __('Your profile cannot manage these record types: %s. Set "Manageable domain record types" accordingly.', 'domainmanager'),
                    implode(', ', $unmanageable),
                );
                return;
            }

            $driver  = DriverFactory::forDns($config);
            $records = $driver->fetchZoneRecords((string) $domain->fields['name']);
            $this->logger->activity(
                (int) $domain->getID(),
                'DNS fetch succeeded, returned ' . count($records) . ' record(s) from the provider',
            );
            $commentDriver = $driver instanceof DnsRecordCommentSyncInterface ? $driver : null;
            $stats = $this->reconciler->reconcile($domain, $records, $commentDriver, $force);

            $result['dns_status']  = DomainState::STATUS_OK;
            $result['dns_message'] = sprintf(
                __('%1$d added, %2$d updated, %3$d restored, %4$d trashed, %5$d unchanged', 'domainmanager'),
                $stats['added'],
                $stats['updated'],
                $stats['restored'],
                $stats['trashed'],
                $stats['unchanged'],
            );
            $this->logger->milestone((int) $domain->getID(), 'DNS sync OK: ' . $result['dns_message']);
        } catch (SyncSafetyExceededException $e) {
            $result['dns_status']  = DomainState::STATUS_SYNC_SAFETY_GUARD;
            $result['dns_message'] = $e->getMessage();
            $this->logger->detail(
                'DNS leg refused for domain #' . $domain->getID() . ' (sync safety guard): '
                . $e->wouldTrash . ' of ' . $e->totalOwned . ' owned record(s) would be trashed',
            );
        } catch (DriverException $e) {
            $result['dns_status']  = DomainState::STATUS_ERROR;
            $result['dns_message'] = $e->getMessage();
            $this->logger->detail('DNS leg failed for domain #' . $domain->getID() . ': ' . $e->getMessage());
        } catch (Throwable $e) {
            $result['dns_status']  = DomainState::STATUS_ERROR;
            $result['dns_message'] = __('Unexpected DNS synchronization error', 'domainmanager');
            $this->logger->detail(
                'DNS leg exception for domain #' . $domain->getID() . ': '
                . $e::class . ': ' . $e->getMessage(),
            );
        }
    }

    /**
     * @param  bool|null $value
     * @return int|null
     */
    private static function toNullableInt(?bool $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * Deterministic supplier for a driver key: lowest suppliers_id wins (§4)
     *
     * @param  string $driver
     * @return SupplierConfig|null
     */
    private function findSupplierConfigForDriver(string $driver): ?SupplierConfig
    {
        /** @var \DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'SELECT' => 'id',
            'FROM'   => SupplierConfig::getTable(),
            'WHERE'  => ['api_driver' => $driver],
            'ORDER'  => 'suppliers_id ASC',
            'LIMIT'  => 1,
        ]);

        foreach ($iterator as $row) {
            $config = new SupplierConfig();
            if ($config->getFromDB((int) $row['id']) && $config->getDecryptedCredentials() !== []) {
                return $config;
            }
        }

        return null;
    }
}
