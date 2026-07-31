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

use CommonDBTM;
use Dropdown;
use GlpiPlugin\Domainmanager\Service\DomainStatusResolver;

/**
 * Per-domain sync state: registrar supplier, resolved DNS provider and
 * last pipeline outcomes (glpi_plugin_domainmanager_states, one row per domain)
 */
class DomainState extends CommonDBTM
{
    public static $rightname = 'domain';

    public const STATUS_NEVER             = 'never';
    public const STATUS_OK                = 'ok';
    public const STATUS_ERROR             = 'error';
    public const STATUS_UNCONFIGURED      = 'unconfigured';
    public const STATUS_UNSUPPORTED       = 'unsupported';
    public const STATUS_UNKNOWN           = 'unknown';
    // The resolved supplier for this role exists and is known, but its
    // native "Active" field is off — deliberately distinct from
    // STATUS_UNCONFIGURED (no supplier resolved at all) and STATUS_ERROR
    // (an API call was attempted and failed): nothing was attempted here,
    // on purpose (§addendum "Skip Inactive Suppliers").
    public const STATUS_SUPPLIER_INACTIVE = 'supplier_inactive';
    // The registrar (Infocom's Supplier) was just reassigned to a
    // *different*, already-known supplier, but no sync has run against
    // the new assignment yet — `registrar_message` (and whatever
    // `registrar_status` held before) described the *previous* supplier,
    // so showing it unchanged next to the new supplier's name would be
    // genuinely incorrect, not merely stale (§9 Phase 8 addendum, the
    // deferred "Domain form panel mismatch treatment"). Deliberately its
    // own status rather than reusing STATUS_NEVER: unlike a domain that
    // was never assigned a registrar at all, this domain *does* have
    // sync history — it's just history for the wrong supplier now.
    public const STATUS_REASSIGNED        = 'reassigned';

    // ARCHITECTURE.md §14.2 (Phase 47 write gate): the DNS leg resolved a
    // *different* driver-backed supplier than the one this domain's records
    // are currently managed under (`is_managed` was already true for the
    // previous supplier) — reconciliation is skipped entirely for this sync
    // (no upstream fetch, no trash/recreate of the previous supplier's
    // owned records) rather than silently migrating ownership. The new
    // supplier is still recorded on the state row so a second, deliberate
    // sync run confirms and applies the change — mirrors how a Domain's own
    // Registrar reassignment already needs a fresh sync to take effect
    // (STATUS_REASSIGNED above), just for the DNS leg instead.
    public const STATUS_SOURCE_CONFLICT   = 'source_conflict';

    // Per-domain DNS record write-editability state (ARCHITECTURE.md §12.3,
    // Phase 42) — independent of dns_status (the read-sync outcome above).
    // A domain never configured with a write-capable driver stays `manual`
    // forever; a write-capable driver starts every domain at
    // `managed_readonly` and only reaches `managed_editable` after a write
    // actually succeeds there. Learned from real writes, never probed.
    public const DNS_WRITE_MANUAL   = 'manual';
    public const DNS_WRITE_READONLY = 'managed_readonly';
    public const DNS_WRITE_EDITABLE = 'managed_editable';

    /**
     * {@inheritDoc}
     */
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_domainmanager_states';
    }

    /**
     * {@inheritDoc}
     */
    public static function getTypeName($nb = 0)
    {
        return __('Domain sync state', 'domainmanager');
    }

    /**
     * {@inheritDoc}
     */
    public static function getIcon()
    {
        return 'ti ti-world-cog';
    }

    /**
     * {@inheritDoc}
     *
     * Renders `registrar_status`/`dns_status`/`detected_provider` for the
     * Domain-level "Registrar sync status"/"DNS sync status"/"NS Provider"
     * search options (§9 Phase 15 addendum "Searchable fields") — dispatched
     * here, not on `Domain`, because `Glpi\Search\Provider\SQLProvider`
     * resolves the display callback from the search option's own `table`
     * (`getItemTypeForTable($table)`, or the explicit `'itemtype'` key this
     * plugin's search options set), not from the itemtype under search.
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        switch ($field) {
            case 'registrar_status':
            case 'dns_status':
                $value  = (string) ($values[$field] ?? '');
                $labels = DomainStatusResolver::getStatusLabels();
                return \htmlescape($labels[$value] ?? $value);

            case 'dns_write_status':
                $value  = (string) ($values[$field] ?? self::DNS_WRITE_MANUAL);
                $labels = self::getDnsWriteStatusLabels();
                return \htmlescape($labels[$value] ?? $value);

            case 'detected_provider':
                $value = (string) ($values[$field] ?? '');
                return $value === ''
                    ? \htmlescape(__('Never synchronized', 'domainmanager'))
                    : \htmlescape($value);

                // Never render the actual credential in a search results
                // column, same "On file"/"Not on file" masking as the domain
                // panel's own badge (domain_panel.html.twig) — only whether a
                // code is on file is ever shown here.
            case 'registrar_auth_info':
                $value = (string) ($values[$field] ?? '');
                return $value === ''
                    ? \htmlescape(__('Not on file', 'domainmanager'))
                    : \htmlescape(__('On file', 'domainmanager'));
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * {@inheritDoc}
     *
     * Dropdown widget for the search criteria input on the same three
     * fields as {@see self::getSpecificValueToDisplay()} — same dispatch
     * reasoning.
     */
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;

        switch ($field) {
            case 'registrar_status':
            case 'dns_status':
                $options['value'] = $values[$field] ?? '';
                return Dropdown::showFromArray($name, DomainStatusResolver::getStatusLabels(), $options);

            case 'detected_provider':
                $choices = [];
                foreach (NsProviderRegistry::getProviders() as $provider) {
                    $choices[$provider['name']] = $provider['name'];
                }
                asort($choices);
                // Appended after sorting so it reads as a distinct,
                // catch-all last choice rather than alphabetized among
                // real provider names.
                $choices[NsProviderRegistry::PROVIDER_UNKNOWN] = __('Unknown', 'domainmanager');

                $options['value'] = $values[$field] ?? '';
                return Dropdown::showFromArray($name, $choices, $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /**
     * Get the state row of a domain
     *
     * @param  int $domains_id
     * @return self|null
     */
    public static function getForDomain(int $domains_id): ?self
    {
        $state = new self();
        if ($domains_id > 0 && $state->getFromDBByCrit(['domains_id' => $domains_id])) {
            return $state;
        }

        return null;
    }

    /**
     * Every non-deleted, non-template Domain where this supplier is the
     * registrar and/or the resolved DNS provider — read-only "Domains" list
     * shown on the Supplier's Domain Manager tab. Restricted to entities
     * visible to the current session, same as any other asset listing.
     *
     * Union of two independent sources, exactly like the underlying
     * relationship works (§9 Phase 5.5, revised):
     * - **Registrar**: `glpi_infocoms.suppliers_id` (itemtype=Domain),
     *   read live and directly — this is GLPI's own native, immediately
     *   authoritative link (the same one that makes a domain show up
     *   under a Supplier's native "Items" tab) and requires no sync/state
     *   row to exist at all. Never gate a domain's presence in this list
     *   on a state row existing just because the registrar link is real.
     * - **NS provider**: `states.dns_suppliers_id`, which genuinely
     *   cannot be known without at least one real sync — this half stays
     *   sync-dependent, it just must not suppress a row the registrar
     *   side already justifies.
     * `registrar_status` only comes from the state row when that row's own
     * `registrar_suppliers_id` mirror actually agrees with the row's own
     * live Infocom value queried here (`registrar_suppliers_id`, from the
     * `infocom` join) — otherwise it falls back to `STATUS_NEVER` ("not yet
     * checked"). This check is intentionally independent of `$suppliers_id`
     * (which supplier's tab is being viewed): the mirror either matches the
     * domain's real registrar or it doesn't, regardless of who's looking.
     * This matters even when a state row genuinely exists: a domain can
     * have been synced (DNS side populated) while its Infocom registrar
     * assignment predates or otherwise missed
     * `HookHandler::infocomSaved()`'s mirror (§0.1) — in that case the
     * row's `registrar_status` describes some *other*, now-stale registrar
     * relationship, not the domain's current one, and showing it would be
     * actively misleading rather than merely stale. `dns_status` has no
     * equivalent problem: a row only matches the DNS half of the union at
     * all when `dns_suppliers_id` already equals `$suppliers_id`, written
     * directly by the most recent real sync.
     *
     * @param  int $suppliers_id
     * @return array<int, array{domains_id:int, name:string, entities_id:int,
     *               registrar_suppliers_id:int, dns_suppliers_id:int,
     *               detected_provider:string, registrar_status:string,
     *               dns_status:string, registrar_verified:bool}>
     */
    public static function getDomainsForSupplier(int $suppliers_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($suppliers_id <= 0) {
            return [];
        }

        $iterator = $DB->request([
            'SELECT'    => [
                'glpi_domains.id AS domains_id',
                'glpi_domains.name AS name',
                'glpi_domains.entities_id AS entities_id',
                'infocom.suppliers_id AS registrar_suppliers_id',
                self::getTable() . '.registrar_suppliers_id AS state_registrar_suppliers_id',
                self::getTable() . '.dns_suppliers_id AS dns_suppliers_id',
                self::getTable() . '.detected_provider AS detected_provider',
                self::getTable() . '.registrar_status AS registrar_status',
                self::getTable() . '.dns_status AS dns_status',
            ],
            'FROM'      => 'glpi_domains',
            'LEFT JOIN' => [
                'glpi_infocoms AS infocom' => [
                    'ON' => [
                        'infocom'      => 'items_id',
                        'glpi_domains' => 'id',
                        [
                            'AND' => ['infocom.itemtype' => 'Domain'],
                        ],
                    ],
                ],
                self::getTable() => [
                    'ON' => [
                        self::getTable() => 'domains_id',
                        'glpi_domains'   => 'id',
                    ],
                ],
            ],
            'WHERE'     => array_merge(
                [
                    'glpi_domains.is_deleted'  => 0,
                    'glpi_domains.is_template' => 0,
                    'OR'                       => [
                        'infocom.suppliers_id'                 => $suppliers_id,
                        self::getTable() . '.dns_suppliers_id' => $suppliers_id,
                    ],
                ],
                getEntitiesRestrictCriteria('glpi_domains', '', '', true),
            ),
            'ORDER'     => 'glpi_domains.name ASC',
        ]);

        $rows = [];
        foreach ($iterator as $row) {
            $registrar_verified = (int) ($row['state_registrar_suppliers_id'] ?? 0) === (int) ($row['registrar_suppliers_id'] ?? 0);

            $rows[] = [
                'domains_id'             => (int) $row['domains_id'],
                'name'                   => (string) $row['name'],
                'entities_id'            => (int) $row['entities_id'],
                'registrar_suppliers_id' => (int) ($row['registrar_suppliers_id'] ?? 0),
                'dns_suppliers_id'       => (int) ($row['dns_suppliers_id'] ?? 0),
                'detected_provider'      => (string) ($row['detected_provider'] ?? ''),
                'registrar_status'       => $registrar_verified ? (string) $row['registrar_status'] : self::STATUS_NEVER,
                'dns_status'             => $row['dns_status'] !== null ? (string) $row['dns_status'] : self::STATUS_NEVER,
                'registrar_verified'     => $registrar_verified,
            ];
        }

        return $rows;
    }

    /**
     * Whether $suppliers_id currently resolves to a real, driver-backed,
     * active supplier — the same pre-flight check `SyncEngine::
     * syncRegistrarLeg()`/`syncDnsLeg()` perform before attempting a live
     * API call (§9 Phase 14 "Domain-level Managed field"): the supplier
     * exists, is active (`SupplierConfig::isSupplierActive()`), has a real
     * driver configured (not `DriverRegistry::DRIVER_NONE`), and has
     * decrypted credentials on file. Deliberately independent of whether a
     * sync actually ran or what it found — a domain counts as "managed" the
     * moment a real driver is behind it, even before the first sync.
     *
     * @param  int $suppliers_id
     * @return bool
     */
    public static function resolvesToActiveDriver(int $suppliers_id): bool
    {
        if ($suppliers_id <= 0 || !SupplierConfig::isSupplierActive($suppliers_id)) {
            return false;
        }

        $config = SupplierConfig::getForSupplier($suppliers_id);

        return $config !== null
            && $config->fields['api_driver'] !== DriverRegistry::DRIVER_NONE
            && $config->getDecryptedCredentials() !== [];
    }

    /**
     * RDAP enrichment cron status for the config page (§9 Phase 23): how many
     * non-deleted, non-template domains are still eligible for a check today
     * ({@see Cron::cronRdapEnrichment}'s own date-based eligibility, without
     * the additional per-domain {@see \GlpiPlugin\Domainmanager\Service\RdapGapChecker}
     * filter — cheap to compute here, and "still due for a look" is the
     * honest reading of this line for an admin), plus the most recent
     * `last_rdap_check_date` across all domains.
     *
     * @return array{pending:int, last_processed:?string}
     */
    public static function getRdapEnrichmentStatus(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $today = date('Y-m-d');
        $table = self::getTable();

        $pending = 0;
        foreach (
            $DB->request([
                'SELECT'    => ['glpi_domains.id'],
                'FROM'      => 'glpi_domains',
                'LEFT JOIN' => [
                    $table => [
                        'ON' => [
                            $table         => 'domains_id',
                            'glpi_domains' => 'id',
                        ],
                    ],
                ],
                'WHERE'     => [
                    'glpi_domains.is_deleted'  => 0,
                    'glpi_domains.is_template' => 0,
                    'OR'                       => [
                        [$table . '.last_rdap_check_date' => null],
                        [$table . '.last_rdap_check_date' => ['<', $today . ' 00:00:00']],
                    ],
                ],
            ]) as $row
        ) {
            $pending++;
        }

        $last_processed = null;
        foreach ($DB->request(['SELECT' => 'last_rdap_check_date', 'FROM' => $table]) as $row) {
            $value = $row['last_rdap_check_date'] ?? null;
            if ($value !== null && ($last_processed === null || $value > $last_processed)) {
                $last_processed = $value;
            }
        }

        return [
            'pending'        => $pending,
            'last_processed' => $last_processed,
        ];
    }

    /**
     * §9 Phase 28: the most recently RDAP-checked, still-populated
     * registrar-of-record (name + IANA id) among this Supplier's own
     * registrar-linked domains — surfaced read-only on the Supplier's
     * Domain Manager tab so an admin can confirm/record it once per
     * Supplier, rather than only ever seeing it on an individual Domain
     * form's cross-check panel (§6.2). Purely informational, same
     * "never a source of truth" rule as that panel (§9 Phase 21
     * "Registrar-of-record note") — this is a read, never a write path.
     *
     * @param  int $suppliers_id
     * @return array{name: string, iana_id: ?string}|null
     */
    public static function getRdapRegistrarInfo(int $suppliers_id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($suppliers_id <= 0) {
            return null;
        }

        $row = $DB->request([
            'SELECT'    => [self::getTable() . '.rdap_registrar_name', self::getTable() . '.rdap_registrar_iana_id'],
            'FROM'      => self::getTable(),
            'LEFT JOIN' => [
                'glpi_infocoms' => [
                    'ON' => [
                        'glpi_infocoms'  => 'items_id',
                        self::getTable() => 'domains_id',
                        ['AND' => ['glpi_infocoms.itemtype' => 'Domain']],
                    ],
                ],
            ],
            'WHERE'     => [
                'glpi_infocoms.suppliers_id'            => $suppliers_id,
                self::getTable() . '.rdap_registrar_name' => ['<>', ''],
            ],
            'ORDER'     => self::getTable() . '.last_rdap_check_date DESC',
            'LIMIT'     => 1,
        ])->current();

        if ($row === null || empty($row['rdap_registrar_name'])) {
            return null;
        }

        return [
            'name'    => (string) $row['rdap_registrar_name'],
            'iana_id' => $row['rdap_registrar_iana_id'] !== null ? (string) $row['rdap_registrar_iana_id'] : null,
        ];
    }

    /**
     * Human-readable labels for `dns_write_status` (§12.3 "settled, not to
     * be re-opened" UI terminology) — used by the domain panel badge and by
     * search rendering, kept here rather than duplicated at each call site.
     *
     * @return array<string, string>
     */
    public static function getDnsWriteStatusLabels(): array
    {
        return [
            self::DNS_WRITE_MANUAL   => __('Manual', 'domainmanager'),
            self::DNS_WRITE_READONLY => __('Managed — read-only', 'domainmanager'),
            self::DNS_WRITE_EDITABLE => __('Managed — editable', 'domainmanager'),
        ];
    }

    /**
     * Record the outcome of a real DNS record write attempt against this
     * domain's configured driver (§12.3) — the only way `dns_write_status`
     * ever changes outside of `SyncEngine::sync()`'s own reset-on-recognition
     * logic. Never called for a transient failure (network/5xx), an
     * idempotent-already-gone outcome, or a validation error: none of those
     * are evidence about write *permission*, so none of them change stored
     * state (§12.7) — callers must only invoke this for a genuine success or
     * a genuine permission failure.
     *
     * @param  int         $domains_id
     * @param  bool        $success
     * @param  string|null $permission_message reason text when $success is false
     * @return void
     */
    public static function recordWriteOutcome(int $domains_id, bool $success, ?string $permission_message = null): void
    {
        $state = self::getForDomain($domains_id);
        if ($state === null) {
            return;
        }

        $status = $success ? self::DNS_WRITE_EDITABLE : self::DNS_WRITE_READONLY;
        if ($state->fields['dns_write_status'] === $status && (string) $state->fields['dns_write_message'] === (string) $permission_message) {
            return;
        }

        $state->update([
            'id'                => $state->getID(),
            'dns_write_status'  => $status,
            'dns_write_message' => $success ? null : $permission_message,
        ]);
    }

    /**
     * Detach a purged supplier from every state row referencing it
     *
     * @param  int $suppliers_id
     * @return void
     */
    public static function onSupplierPurge(int $suppliers_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($suppliers_id <= 0) {
            return;
        }

        foreach (['registrar_suppliers_id', 'dns_suppliers_id'] as $field) {
            $DB->update(
                self::getTable(),
                [$field => 0],
                [$field => $suppliers_id],
            );
        }
    }
}
