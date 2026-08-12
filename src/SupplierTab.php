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

use Ajax;
use CommonGLPI;
use Domain;
use Dropdown;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Domainmanager\Service\DomainStatusResolver;
use Session;
use Supplier;
use Toolbox;

/**
 * "Domain Manager" tab on Supplier: API driver + credentials form
 */
class SupplierTab extends CommonGLPI
{
    /**
     * {@inheritDoc}
     */
    public static function getTypeName($nb = 0)
    {
        return __('Domain Manager', 'domainmanager');
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
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string|array
    {
        if (
            $item instanceof Supplier
            && !$withtemplate
            && $item->getID() > 0
            && $item->can($item->getID(), READ)
        ) {
            // Same union query the panel itself renders from
            // (DomainState::getDomainsForSupplier()) — deliberately not a
            // separate/simpler count, so this badge can never drift from
            // what the tab actually shows (§9 Phase 5.5). Gated behind the
            // same session preference core's own tab-count badges use
            // (e.g. Document_Item::getTabNameForItem()) to avoid an
            // unconditional extra query on every tab-list render.
            $count = 0;
            if (($_SESSION['glpishow_count_on_tabs'] ?? true) && Session::haveRight('domain', READ)) {
                $count = count(DomainState::getDomainsForSupplier((int) $item->getID()));
            }

            return self::createTabEntry(self::getTypeName(1), $count);
        }

        return '';
    }

    /**
     * {@inheritDoc}
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof Supplier && $item->can($item->getID(), READ)) {
            return self::showForSupplier($item);
        }

        return false;
    }

    /**
     * Render the driver + credentials form
     *
     * @param  Supplier $supplier
     * @return bool
     */
    private static function showForSupplier(Supplier $supplier): bool
    {
        $config = SupplierConfig::getForSupplier((int) $supplier->getID());

        $current_driver = $config !== null
            ? (string) $config->fields['api_driver']
            : DriverRegistry::DRIVER_NONE;

        // Never echo secrets back: secret fields only get a saved/empty flag,
        // non-secret fields (e.g. username) are prefilled normally
        $saved  = [];
        $values = [];
        if ($config !== null) {
            $saved       = $config->getSavedCredentialFlags();
            $credentials = $config->getDecryptedCredentials();
            foreach (DriverRegistry::getCredentialFields($current_driver) as $name => $meta) {
                if (!$meta['secret'] && isset($credentials[$name])) {
                    $values[$name] = $credentials[$name];
                }
            }
        }

        // A Cloudflare config saved before the Account ID field existed has
        // a token but nothing under 'account_id' in its stored JSON — no
        // schema migration needed for this (the field lives in the same
        // encrypted blob as every other credential field), just a clear
        // notice so the gap doesn't silently break sync or get mistaken for
        // an unrelated failure later (§addendum "Switch Cloudflare Driver
        // to Account-Scoped API Tokens").
        $cloudflare_missing_account_id = $current_driver === DriverRegistry::DRIVER_CLOUDFLARE
            && ($saved['token'] ?? false)
            && !($saved['account_id'] ?? false);

        $connection_test = $config !== null
            ? $config->getConnectionTestSummary()
            : [
                'registrar' => ['status' => 'never', 'message' => '', 'http_code' => null, 'date' => null],
                'dns'       => ['status' => 'never', 'message' => '', 'http_code' => null, 'date' => null],
            ];

        // "Import Domains" button (§9 Phase 8): shown purely via a real
        // instanceof DomainDiscoveryInterface check on the actually
        // configured driver, never a per-driver allowlist — appears
        // automatically for whichever driver supports it (only IonosDriver
        // today).
        $discovery_supported = $config !== null && DriverFactory::forDiscovery($config) !== null;

        // Built with core's own Ajax::createModalWindow() (matches every
        // other AJAX-loaded modal in GLPI, e.g. massive actions) rather than
        // a hand-rolled fetch()+innerHTML modal: it renders the real
        // components/modal.html.twig chrome (so this actually looks like a
        // GLPI modal) and, critically, loads the URL via jQuery's .load(),
        // which — unlike innerHTML assignment — actually executes the
        // <script> tags in the response. Without that, neither the entity
        // dropdown's own select2/AJAX-search init script nor this modal's
        // own reassign-button script would ever run.
        $import_modal_script = $discovery_supported
            ? Ajax::createModalWindow(
                'domainmanager_import_modal',
                '/plugins/domainmanager/domaindiscovery/' . (int) $supplier->getID(),
                [
                    'title'       => sprintf(__('Import Domains — %s', 'domainmanager'), $supplier->getName()),
                    'modal_class' => 'modal-lg modal-dialog-scrollable',
                    'display'     => false,
                ],
            )
            : '';

        $domains_raw        = Session::haveRight('domain', READ)
            ? DomainState::getDomainsForSupplier((int) $supplier->getID())
            : [];
        $never_synced_count = self::countNeverSyncedRegistrarLinks($domains_raw);
        $datatable_params    = self::buildDomainsDatatableParams($domains_raw, (int) $supplier->getID());

        TemplateRenderer::getInstance()->display('@domainmanager/supplier_tab.html.twig', [
            'suppliers_id'         => (int) $supplier->getID(),
            'config_id'            => $config !== null ? (int) $config->getID() : 0,
            'form_url'             => SupplierConfig::getFormURL(),
            'can_edit'             => $supplier->can((int) $supplier->getID(), UPDATE),
            'supplier_active'      => (bool) $supplier->fields['is_active'],
            'cloudflare_missing_account_id' => $cloudflare_missing_account_id,
            'current_driver'       => $current_driver,
            'driver_labels'        => self::getDriverOptions((int) $supplier->getID(), $current_driver),
            'credential_fields'    => DriverRegistry::getAllCredentialFields(),
            'saved'                => $saved,
            'values'               => $values,
            'connection_test'      => $connection_test,
            'testable_capabilities' => DriverRegistry::getTestableCapabilities($current_driver),
            'all_testable_capabilities' => array_combine(
                DriverRegistry::getAvailableDrivers(),
                array_map([DriverRegistry::class, 'getTestableCapabilities'], DriverRegistry::getAvailableDrivers()),
            ),
            'primary_capability'   => DriverRegistry::getPrimaryTestableCapability($current_driver),
            'all_primary_capabilities' => array_combine(
                DriverRegistry::getAvailableDrivers(),
                array_map([DriverRegistry::class, 'getPrimaryTestableCapability'], DriverRegistry::getAvailableDrivers()),
            ),
            'datatable_params'   => $datatable_params,
            'never_synced_count' => $never_synced_count,
            'rdap_registrar_info' => DomainState::getRdapRegistrarInfo((int) $supplier->getID()),
            'domains_search_url' => self::getDomainsSearchUrl((int) $supplier->getID()),
            'discovery_supported'  => $discovery_supported,
            'import_modal_script'  => $import_modal_script,
        ]);

        return true;
    }

    /**
     * API driver dropdown options for this supplier: a driver already
     * claimed by a *different* supplier (SupplierConfig::
     * getDriversClaimedByOtherSuppliers()) is excluded — each driver may
     * only ever be assigned to one supplier at a time — while 'none' and
     * this supplier's own current driver (if any) always remain available.
     * Sorted alphabetically by display label, 'none' pinned first.
     *
     * @param  int    $suppliers_id
     * @param  string $current_driver
     * @return array<string, string>
     */
    private static function getDriverOptions(int $suppliers_id, string $current_driver): array
    {
        $claimed_elsewhere = SupplierConfig::getDriversClaimedByOtherSuppliers($suppliers_id);

        $options = [];
        foreach (DriverRegistry::getDriverLabels() as $driver => $label) {
            if ($driver !== $current_driver && in_array($driver, $claimed_elsewhere, true)) {
                continue;
            }
            $options[$driver] = $label;
        }

        $none_label = $options[DriverRegistry::DRIVER_NONE] ?? null;
        unset($options[DriverRegistry::DRIVER_NONE]);
        uasort($options, static fn(string $a, string $b): int => strcasecmp($a, $b));

        if ($none_label !== null) {
            $options = [DriverRegistry::DRIVER_NONE => $none_label] + $options;
        }

        return $options;
    }

    /**
     * Sortable column keys for the Domains datatable — matches the
     * `columns` keys returned by buildDomainsDatatableParams(), validated
     * the same way ProjectTask's own task-list tab validates `$_GET['sort']`
     * before trusting it.
     */
    private const DOMAINS_SORT_COLUMNS = ['name', 'registrar', 'registrar_status', 'dns', 'dns_status', 'entity'];

    /**
     * Builds the full `components/datatable.html.twig` param array for the
     * Domains list (Phase 95): reads `$_GET['sort']`/`order`/`filters`/
     * `start` (same validated-`$_GET` pattern as core's
     * `ProjectTask`'s task-list tab and `Location::showItems()`), applies
     * filtering/sorting/pagination on top of
     * `DomainState::getDomainsForSupplier()`'s raw rows in PHP, and renders
     * each visible row's cells as ready-made `raw_html` strings so the
     * template needs zero branching (same approach as core's
     * `Domain_Item::showForDomain()`).
     *
     * Sorting on `registrar`/`dns`/`entity` has to happen after resolving
     * their display names, since those are FK lookups, not literal columns
     * on the raw row.
     *
     * @param  array<int, array{domains_id:int, name:string, entities_id:int,
     *                registrar_suppliers_id:int, dns_suppliers_id:int,
     *                detected_provider:string, registrar_status:string,
     *                dns_status:string, registrar_verified:bool}> $domains
     * @param  int $suppliers_id
     * @return array<string, mixed>
     */
    private static function buildDomainsDatatableParams(array $domains, int $suppliers_id): array
    {
        $sort = (string) ($_GET['sort'] ?? '');
        if (!in_array($sort, self::DOMAINS_SORT_COLUMNS, true)) {
            $sort = 'name';
        }
        $order = strtoupper((string) ($_GET['order'] ?? ''));
        $order = $order === 'DESC' ? 'DESC' : 'ASC';

        $filters = is_array($_GET['filters'] ?? null) ? $_GET['filters'] : [];
        $name_filter              = trim((string) ($filters['name'] ?? ''));
        $registrar_filter         = array_values(array_filter((array) ($filters['registrar'] ?? [])));
        $registrar_status_filter = array_values(array_filter((array) ($filters['registrar_status'] ?? [])));
        $dns_filter               = array_values(array_filter((array) ($filters['dns'] ?? [])));
        $dns_kind_filter          = array_values(array_filter((array) ($filters['dns_status'] ?? [])));

        $total_number = count($domains);

        $status_labels    = DomainStatusResolver::getStatusLabels();
        $status_classes   = DomainStatusResolver::getStatusClasses();
        $dns_kind_labels  = self::getDnsKindLabels();
        $dns_kind_classes = self::getDnsKindClasses();

        $rows = array_map(static function (array $domain): array {
            $domain['_dns']       = self::describeDnsProvider($domain);
            $domain['_registrar'] = self::describeSupplierRole($domain['registrar_suppliers_id']);

            return $domain;
        }, $domains);

        // Dropdown options are the actual registrar/DNS-provider identities
        // present in *this* supplier's own domain list — small and bounded
        // by construction, so a real "pick one of these" dropdown (same as
        // the status columns) reads better than free text. Keyed by
        // resolveRegistrarOptionKey()/resolveDnsOptionKey() rather than by
        // display name, since two different providers could share a name
        // and a Supplier link is the real identity.
        $registrar_options = [];
        $dns_options       = [];
        foreach ($rows as $domain) {
            if ($domain['_registrar'] !== null) {
                $registrar_options[self::resolveRegistrarOptionKey($domain)] = $domain['_registrar']['name'];
            }
            $dns_options[self::resolveDnsOptionKey($domain)] = $domain['_dns']['name'];
        }
        asort($registrar_options);
        asort($dns_options);

        if ($name_filter !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $d): bool => stripos($d['name'], $name_filter) !== false,
            ));
        }
        if ($registrar_filter !== []) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $d): bool => $d['_registrar'] !== null
                    && in_array(self::resolveRegistrarOptionKey($d), $registrar_filter, true),
            ));
        }
        if ($registrar_status_filter !== []) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $d): bool => in_array($d['registrar_status'], $registrar_status_filter, true),
            ));
        }
        if ($dns_filter !== []) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $d): bool => in_array(self::resolveDnsOptionKey($d), $dns_filter, true),
            ));
        }
        if ($dns_kind_filter !== []) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $d): bool => in_array($d['_dns']['kind'], $dns_kind_filter, true),
            ));
        }

        $filtered_number = count($rows);

        $entity_names = [];
        $sort_key      = static function (array $domain) use ($sort, $status_labels, $dns_kind_labels, &$entity_names): string {
            switch ($sort) {
                case 'registrar':
                    return $domain['_registrar']['name'] ?? '';
                case 'registrar_status':
                    return $status_labels[$domain['registrar_status']] ?? $domain['registrar_status'];
                case 'dns':
                    return $domain['_dns']['name'];
                case 'dns_status':
                    return $dns_kind_labels[$domain['_dns']['kind']] ?? $domain['_dns']['kind'];
                case 'entity':
                    $entities_id = $domain['entities_id'];
                    if (!isset($entity_names[$entities_id])) {
                        $entity_names[$entities_id] = Dropdown::getDropdownName(Entity::getTable(), $entities_id);
                    }

                    return $entity_names[$entities_id];
                default:
                    return $domain['name'];
            }
        };

        usort($rows, static function (array $a, array $b) use ($sort_key, $order): int {
            $cmp = strcasecmp($sort_key($a), $sort_key($b));

            return $order === 'DESC' ? -$cmp : $cmp;
        });

        $start = max(0, (int) ($_GET['start'] ?? 0));
        $limit = max(1, (int) ($_SESSION['glpilist_limit'] ?? 25));
        $page  = array_slice($rows, $start, $limit);

        $entries = array_map(static function (array $domain) use ($status_labels, $status_classes, $dns_kind_labels, $dns_kind_classes): array {
            return [
                'name'             => self::renderDomainNameCell($domain),
                'registrar'        => self::renderRegistrarNameCell($domain),
                'registrar_status' => self::renderStatusBadgeCell(
                    $status_labels[$domain['registrar_status']] ?? $domain['registrar_status'],
                    $status_classes[$domain['registrar_status']] ?? 'text-bg-secondary',
                ),
                'dns'              => self::renderDnsNameCell($domain),
                'dns_status'       => self::renderStatusBadgeCell(
                    $dns_kind_labels[$domain['_dns']['kind']] ?? $domain['_dns']['kind'],
                    self::resolveDnsStatusClass($domain, $dns_kind_classes),
                ),
                'entity'           => self::describeEntity($domain['entities_id']),
            ];
        }, $page);

        return [
            'is_tab'          => true,
            'items_id'        => $suppliers_id,
            'sort'            => $sort,
            'order'           => $order,
            'filters'         => $filters,
            'columns'         => [
                'name'             => ['label' => _n('Domain', 'Domains', 1)],
                'registrar'        => [
                    'label'            => __('Registrar', 'domainmanager'),
                    'filter_formatter' => 'array',
                ],
                'registrar_status' => [
                    'label'            => __('Registrar status', 'domainmanager'),
                    'filter_formatter' => 'array',
                ],
                'dns'              => [
                    'label'            => __('DNS Provider', 'domainmanager'),
                    'filter_formatter' => 'array',
                ],
                'dns_status'       => [
                    'label'            => __('DNS status', 'domainmanager'),
                    'filter_formatter' => 'array',
                ],
                'entity'           => [
                    'label'     => _n('Entity', 'Entities', 1),
                    'no_filter' => true,
                ],
            ],
            'columns_values'  => [
                'registrar'        => $registrar_options,
                'registrar_status' => $status_labels,
                'dns'              => $dns_options,
                'dns_status'       => $dns_kind_labels,
            ],
            'formatters'      => [
                'name'             => 'raw_html',
                'registrar'        => 'raw_html',
                'registrar_status' => 'raw_html',
                'dns'              => 'raw_html',
                'dns_status'       => 'raw_html',
                'entity'           => 'raw_html',
            ],
            'entries'         => $entries,
            'total_number'    => $total_number,
            'filtered_number' => $filtered_number,
            'start'           => $start,
            'limit'           => $limit,
            'showmassiveactions' => false,
        ];
    }

    /**
     * @param  array{domains_id:int, name:string} $domain
     * @return string
     */
    private static function renderDomainNameCell(array $domain): string
    {
        return sprintf(
            '<a href="%s">%s</a>',
            htmlspecialchars(Domain::getFormURLWithID($domain['domains_id']), ENT_QUOTES),
            htmlspecialchars($domain['name'], ENT_QUOTES),
        );
    }

    /**
     * Registrar column: the linked Supplier's name/link only — no status
     * badge, so filtering this column's own "Registrar status" companion
     * column isn't confused with filtering by which registrar it is
     * (§ Phase 95 user-caught fix: the two used to be one combined cell,
     * which read as "filter by registrar" while actually filtering by
     * status).
     *
     * @param  array{_registrar:?array{name:string, url:string}} $domain
     * @return string
     */
    private static function renderRegistrarNameCell(array $domain): string
    {
        if ($domain['_registrar'] === null) {
            return '<span class="text-muted">' . __('None') . '</span>';
        }

        return sprintf(
            '<a href="%s">%s</a>',
            htmlspecialchars($domain['_registrar']['url'], ENT_QUOTES),
            htmlspecialchars($domain['_registrar']['name'], ENT_QUOTES),
        );
    }

    /**
     * DNS Provider column: the resolved provider's name/link only — its own
     * "DNS status" companion column carries the badge. See
     * renderRegistrarNameCell()'s docblock for why these are separate.
     *
     * @param  array{_dns:array{kind:string, name:string, url:?string}} $domain
     * @return string
     */
    private static function renderDnsNameCell(array $domain): string
    {
        $dns = $domain['_dns'];

        return $dns['url'] !== null
            ? sprintf('<a href="%s">%s</a>', htmlspecialchars($dns['url'], ENT_QUOTES), htmlspecialchars($dns['name'], ENT_QUOTES))
            : htmlspecialchars($dns['name'], ENT_QUOTES);
    }

    /**
     * @param  string $label
     * @param  string $class
     * @return string
     */
    private static function renderStatusBadgeCell(string $label, string $class): string
    {
        return sprintf(
            '<span class="badge %s">%s</span>',
            htmlspecialchars($class, ENT_QUOTES),
            htmlspecialchars($label, ENT_QUOTES),
        );
    }

    /**
     * A plugin-managed DNS provider currently in error reads as more
     * alarming than the neutral "Plugin managed" badge alone would suggest
     * — same override the original hand-rolled table markup applied.
     *
     * @param  array{dns_status:string, _dns:array{kind:string}} $domain
     * @param  array<string, string> $dns_kind_classes
     * @return string
     */
    private static function resolveDnsStatusClass(array $domain, array $dns_kind_classes): string
    {
        if ($domain['_dns']['kind'] === 'managed' && $domain['dns_status'] === DomainState::STATUS_ERROR) {
            return 'text-bg-danger';
        }

        return $dns_kind_classes[$domain['_dns']['kind']] ?? 'text-bg-secondary';
    }

    /**
     * @return array<string, string>
     */
    private static function getDnsKindLabels(): array
    {
        return [
            'managed'   => __('Plugin managed', 'domainmanager'),
            'unmanaged' => __('Known, unmanaged (yet)', 'domainmanager'),
            'unknown'   => __('Unknown'),
            'never'     => __('Not yet checked', 'domainmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function getDnsKindClasses(): array
    {
        return [
            'managed'   => 'text-bg-success',
            'unmanaged' => 'text-bg-warning',
            'unknown'   => 'text-bg-secondary',
            'never'     => 'text-bg-secondary',
        ];
    }

    /**
     * Entity display for the Domains list, in GLPI's own tree/breadcrumb
     * badge convention (`Entity::badgeCompletenameLinkById()`/
     * `badgeCompletenameById()`, the same pair core itself uses for a
     * read-only Entity field, e.g.
     * `templates/components/itilobject/fields_panel.html.twig`), rather
     * than a bespoke plain-name display.
     *
     * @param  int $entities_id
     * @return string
     */
    private static function describeEntity(int $entities_id): string
    {
        $html = Session::haveRight(Entity::$rightname, READ)
            ? Entity::badgeCompletenameLinkById($entities_id)
            : Entity::badgeCompletenameById($entities_id);

        return $html ?? '';
    }

    /**
     * How many rows are registrar-linked (a real Supplier via Infocom, §0.1)
     * but that link has never actually been verified by a sync — surfaced
     * as a recommendation banner rather than a silent gap (§9 Phase 5.5).
     *
     * @param  array<int, array{registrar_suppliers_id:int, registrar_verified:bool}> $domains
     * @return int
     */
    private static function countNeverSyncedRegistrarLinks(array $domains): int
    {
        $count = 0;
        foreach ($domains as $domain) {
            if ($domain['registrar_suppliers_id'] > 0 && !$domain['registrar_verified']) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Deep link to Domain's native search, pre-filtered to domains whose
     * Infocom Supplier is this one — reuses core's own search/criteria
     * mechanism rather than a bespoke filtered view, per §9 Phase 5.5's
     * recommendation banner.
     *
     * Search option id **53** is core's own, already-native
     * `glpi_suppliers.name` field under "Financial and administrative
     * information" (`Infocom::rawSearchOptionsToAdd()`, confirmed live
     * against a running GLPI 11 instance) — exposed automatically for any
     * Infocom-bearing itemtype, Domain included. This plugin previously
     * duplicated it with its own `PLUGIN_DOMAINMANAGER_SO_DOMAIN_REGISTRAR`
     * search option (dropped, §9 Phase 14 addendum "Search UI cleanup") —
     * reuse the native one instead of maintaining a redundant copy.
     *
     * @param  int $suppliers_id
     * @return string
     */
    private static function getDomainsSearchUrl(int $suppliers_id): string
    {
        $params = [
            'criteria' => [
                [
                    'field'      => 53,
                    'searchtype' => 'equals',
                    'value'      => $suppliers_id,
                ],
            ],
        ];

        return Domain::getSearchURL() . '?' . Toolbox::append_params($params);
    }

    /**
     * @param  int $suppliers_id
     * @return ?array{name:string, url:string}
     */
    private static function describeSupplierRole(int $suppliers_id): ?array
    {
        if ($suppliers_id <= 0) {
            return null;
        }

        return [
            'name' => Dropdown::getDropdownName(Supplier::getTable(), $suppliers_id),
            'url'  => Supplier::getFormURLWithID($suppliers_id),
        ];
    }

    /**
     * NS provider name + link, plus the plugin-managed/known-unmanaged/
     * unknown/never classification for the NS column's badge. Phase 16
     * originally replaced this "kind" classification with a badge driven
     * directly by `dns_status` (the same vocabulary the Registrar column and
     * Domain form use), on the theory that `dns_status` alone already
     * distinguishes every case this needs. That turned out to be a real
     * regression in practice — reverted back to this classification, which
     * is the one actually verified working. Kept as its own thing rather
     * than reusing `DomainStatusResolver::getStatusLabels()`/`getStatusClasses()`.
     *
     * @param  array{dns_suppliers_id:int, detected_provider:string, dns_status:string} $domain
     * @return array{kind:string, name:string, url:?string}
     */
    private static function describeDnsProvider(array $domain): array
    {
        if ($domain['dns_suppliers_id'] > 0) {
            return [
                'kind' => 'managed',
                'name' => Dropdown::getDropdownName(Supplier::getTable(), $domain['dns_suppliers_id']),
                'url'  => Supplier::getFormURLWithID($domain['dns_suppliers_id']),
            ];
        }

        if (
            $domain['dns_status'] === DomainState::STATUS_UNSUPPORTED
            || $domain['dns_status'] === DomainState::STATUS_UNCONFIGURED
        ) {
            // A real provider was detected either way — 'unsupported' means
            // no driver exists for it yet, 'unconfigured' means a driver
            // exists but no Supplier has been set up with it. Both read as
            // "known, not actively managed yet" from this list's point of
            // view (§9 Phase 5.5).
            return ['kind' => 'unmanaged', 'name' => $domain['detected_provider'], 'url' => null];
        }

        if ($domain['dns_status'] === DomainState::STATUS_UNKNOWN) {
            return ['kind' => 'unknown', 'name' => __('Unknown'), 'url' => null];
        }

        return ['kind' => 'never', 'name' => __('Not yet checked', 'domainmanager'), 'url' => null];
    }

    /**
     * Stable option/filter key for the Registrar column's dropdown: the
     * linked Supplier's own id — a name-based key would silently merge two
     * differently-configured Suppliers that happen to share a display name.
     *
     * @param  array{registrar_suppliers_id:int} $domain
     * @return string
     */
    private static function resolveRegistrarOptionKey(array $domain): string
    {
        return (string) $domain['registrar_suppliers_id'];
    }

    /**
     * Stable option/filter key for the DNS Provider column's dropdown: a
     * plugin-managed provider is keyed by its Supplier id (same reasoning as
     * resolveRegistrarOptionKey()); an unmanaged/unknown/never-checked one
     * has no Supplier to key on, so falls back to its resolved display name
     * (`detected_provider` or the generic "Unknown"/"Not yet checked" text).
     *
     * @param  array{dns_suppliers_id:int, _dns:array{name:string}} $domain
     * @return string
     */
    private static function resolveDnsOptionKey(array $domain): string
    {
        return $domain['dns_suppliers_id'] > 0
            ? 's' . $domain['dns_suppliers_id']
            : 'n:' . strtolower($domain['_dns']['name']);
    }
}
