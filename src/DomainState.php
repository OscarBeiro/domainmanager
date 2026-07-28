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
                getEntitiesRestrictCriteria('glpi_domains', '', '', true)
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
                [$field => $suppliers_id]
            );
        }
    }
}
