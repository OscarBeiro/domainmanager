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

use Domain;
use Glpi\Dashboard\Dashboard;
use Glpi\Dashboard\Item;
use Glpi\DBAL\QueryExpression;
use GlpiPlugin\Domainmanager\Service\DomainStatusResolver;
use Migration;
use Ramsey\Uuid\Uuid;
use Toolbox;

/**
 * Phase 80 proved the registration/drill-down/install mechanism with two
 * cards; Phase 81 (ARCHITECTURE.md §20) adds two more on top of it —
 * registrar status and domains expiring soon. Phases 82-83 continue from
 * there.
 */
class DashboardCards
{
    public const DASHBOARD_KEY = 'plugin_domainmanager_dashboard';

    /**
     * Shared across every Domain Manager card — same icon
     * `DomainState`/`SupplierConfig`/`Profile` already use for the plugin.
     */
    private const ICON = 'ti ti-world-cog';

    /** Native `Domain::rawSearchOptions()` id for `date_expiration`. */
    private const SO_DOMAIN_EXPIRATION_DATE = 6;

    /** Phase 81: fixed lookahead window for the "expiring soon" card. */
    private const EXPIRING_SOON_DAYS = 30;

    public static function dashboardCards($cards)
    {
        if ($cards === null) {
            $cards = [];
        }

        $cards['plugin_domainmanager_domains_by_registrar'] = [
            'widgettype' => ['pie', 'donut', 'multipleNumber', 'bar', 'hbar'],
            'itemtype'   => Domain::class,
            'group'      => __s('Domain Manager'),
            'label'      => __s('Managed domains per registrar', 'domainmanager'),
            'provider'   => self::class . '::domainsByRegistrar',
            'cache'      => false,
        ];

        $cards['plugin_domainmanager_sync_status'] = [
            'widgettype' => ['pie', 'donut', 'multipleNumber', 'bar', 'hbar'],
            'itemtype'   => Domain::class,
            'group'      => __s('Domain Manager'),
            'label'      => __s('Sync status', 'domainmanager'),
            'provider'   => self::class . '::syncStatusBreakdown',
            'cache'      => false,
        ];

        $cards['plugin_domainmanager_registrar_status'] = [
            'widgettype' => ['pie', 'donut', 'multipleNumber', 'bar', 'hbar'],
            'itemtype'   => Domain::class,
            'group'      => __s('Domain Manager'),
            'label'      => __s('Registrar status', 'domainmanager'),
            'provider'   => self::class . '::registrarStatusBreakdown',
            'cache'      => false,
        ];

        $cards['plugin_domainmanager_expiring_soon'] = [
            'widgettype' => ['bigNumber'],
            'itemtype'   => Domain::class,
            'group'      => __s('Domain Manager'),
            'label'      => __s('Number of Domains expiring soon (less than 30 days)', 'domainmanager'),
            'provider'   => self::class . '::domainsExpiringSoon',
            'cache'      => false,
        ];

        return $cards;
    }

    /**
     * "Domains per registrar" card: groups Domain by native Infocom
     * suppliers_id (search option id 53), but only counts a supplier as a
     * real "registrar" once it has an active API driver linked via
     * SupplierConfig — an Infocom supplier used for unrelated
     * billing/vendor purposes, or no supplier at all, falls into a single
     * "No driver linked" bucket instead of being mixed in under its own
     * name.
     */
    public static function domainsByRegistrar(array $params = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Bare "suppliers_id" would collide with the real, unqualified
        // suppliers_id columns joined in from both glpi_infocoms and the
        // supplierconfigs table — MySQL rejects that as an ambiguous
        // GROUP BY column even though it also matches a SELECT alias of
        // the same name. Repeating the full CASE expression in GROUPBY
        // (rather than referencing the alias) sidesteps the ambiguity, and
        // the output alias is named distinctly from any joined column.
        $registrarSuppliersExpr = 'CASE WHEN supplier_config.id IS NOT NULL THEN infocom.suppliers_id ELSE 0 END';

        $iterator = $DB->request([
            'SELECT'    => [
                new QueryExpression($registrarSuppliersExpr . ' AS ' . $DB->quoteName('registrar_suppliers_id')),
                // MAX() rather than a bare column: with ONLY_FULL_GROUP_BY,
                // a column not itself in GROUPBY must be aggregated — every
                // row within the real-supplier buckets shares one name
                // anyway, and the "no driver" bucket's name is deliberately
                // discarded in favor of a fixed label (see toChartData()'s
                // label fallback below).
                new QueryExpression('MAX(' . $DB->quoteName('supplier.name') . ') AS ' . $DB->quoteName('supplier_name')),
                'COUNT DISTINCT' => 'glpi_domains.id AS cpt',
            ],
            'FROM'      => 'glpi_domains',
            'LEFT JOIN' => [
                'glpi_infocoms AS infocom' => [
                    'ON' => [
                        'infocom'      => 'items_id',
                        'glpi_domains' => 'id',
                        ['AND' => ['infocom.itemtype' => Domain::class]],
                    ],
                ],
                'glpi_suppliers AS supplier' => [
                    'ON' => ['supplier' => 'id', 'infocom' => 'suppliers_id'],
                ],
                SupplierConfig::getTable() . ' AS supplier_config' => [
                    'ON' => [
                        'supplier_config' => 'suppliers_id',
                        'infocom'         => 'suppliers_id',
                        ['AND' => ['supplier_config.api_driver' => ['<>', DriverRegistry::DRIVER_NONE]]],
                    ],
                ],
            ],
            'WHERE'     => array_merge(
                [
                    'glpi_domains.is_deleted'  => 0,
                    'glpi_domains.is_template' => 0,
                ],
                getEntitiesRestrictCriteria('glpi_domains', '', '', true),
            ),
            'GROUPBY'   => [new QueryExpression($registrarSuppliersExpr)],
            'ORDER'     => 'cpt DESC',
        ]);

        return self::toChartData(
            $iterator,
            'registrar_suppliers_id',
            'supplier_name',
            'cpt',
            53,
            // __(), not __s() — see the escaping note inside toChartData().
            ['0' => __('No driver linked', 'domainmanager')],
            static function (array $row) {
                $suppliers_id = (int) ($row['registrar_suppliers_id'] ?? 0);
                if ($suppliers_id <= 0) {
                    // No single supplier id represents this bucket — link to
                    // every domain whose registrar isn't one of the
                    // driver-linked suppliers instead of a meaningless
                    // "supplier 0" search.
                    return null;
                }

                $criteria = [
                    'criteria' => [[
                        'field'      => 53,
                        'searchtype' => 'equals',
                        'value'      => $suppliers_id,
                    ]],
                    'reset'    => 'reset',
                ];

                return Domain::getSearchURL() . '?' . Toolbox::append_params($criteria);
            },
            __('Domains per registrar', 'domainmanager'),
            __('Managed domains per registrar', 'domainmanager'),
        );
    }

    /**
     * "Sync status" card: groups Domain by the existing
     * PLUGIN_DOMAINMANAGER_SO_DOMAIN_DNS_STATUS search option (9409) —
     * already registered and searchable, no new option allocated.
     */
    public static function syncStatusBreakdown(array $params = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'SELECT'    => [
                new QueryExpression(
                    'COALESCE(' . $DB->quoteName(DomainState::getTable() . '.dns_status')
                    . ', \'' . DomainState::STATUS_NEVER . '\') AS ' . $DB->quoteName('dns_status'),
                ),
                'COUNT DISTINCT' => 'glpi_domains.id AS cpt',
            ],
            'FROM'      => 'glpi_domains',
            'LEFT JOIN' => [
                DomainState::getTable() => [
                    'ON' => [DomainState::getTable() => 'domains_id', 'glpi_domains' => 'id'],
                ],
            ],
            'WHERE'     => array_merge(
                [
                    'glpi_domains.is_deleted'  => 0,
                    'glpi_domains.is_template' => 0,
                ],
                getEntitiesRestrictCriteria('glpi_domains', '', '', true),
            ),
            'GROUPBY'   => ['dns_status'],
            'ORDER'     => 'cpt DESC',
        ]);

        return self::toChartData(
            $iterator,
            'dns_status',
            'dns_status',
            'cpt',
            PLUGIN_DOMAINMANAGER_SO_DOMAIN_DNS_STATUS,
            DomainStatusResolver::getStatusLabels(),
            null,
            __('Sync status', 'domainmanager'),
            __('Sync status', 'domainmanager'),
        );
    }

    /**
     * "Registrar status" card (Phase 81): groups Domain by the existing
     * PLUGIN_DOMAINMANAGER_SO_DOMAIN_REGISTRAR_STATUS search option
     * (9408) — mirrors the "Sync status" card's shape, plus the same
     * live-Infocom reconciliation `DomainState::getDomainsForSupplier()`
     * already does: a state row whose `registrar_suppliers_id` mirror no
     * longer matches the domain's *current* Infocom supplier describes a
     * stale, now-superseded registrar relationship, so it's folded into
     * `STATUS_NEVER` here rather than shown as-is.
     */
    public static function registrarStatusBreakdown(array $params = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'SELECT'    => [
                new QueryExpression(
                    'CASE WHEN ' . $DB->quoteName(DomainState::getTable() . '.registrar_suppliers_id')
                    . ' = ' . $DB->quoteName('infocom.suppliers_id')
                    . ' THEN COALESCE(' . $DB->quoteName(DomainState::getTable() . '.registrar_status')
                    . ', \'' . DomainState::STATUS_NEVER . '\')'
                    . ' ELSE \'' . DomainState::STATUS_NEVER . '\' END AS ' . $DB->quoteName('registrar_status'),
                ),
                'COUNT DISTINCT' => 'glpi_domains.id AS cpt',
            ],
            'FROM'      => 'glpi_domains',
            'LEFT JOIN' => [
                'glpi_infocoms AS infocom' => [
                    'ON' => [
                        'infocom'      => 'items_id',
                        'glpi_domains' => 'id',
                        ['AND' => ['infocom.itemtype' => Domain::class]],
                    ],
                ],
                DomainState::getTable() => [
                    'ON' => [DomainState::getTable() => 'domains_id', 'glpi_domains' => 'id'],
                ],
            ],
            'WHERE'     => array_merge(
                [
                    'glpi_domains.is_deleted'  => 0,
                    'glpi_domains.is_template' => 0,
                ],
                getEntitiesRestrictCriteria('glpi_domains', '', '', true),
            ),
            'GROUPBY'   => ['registrar_status'],
            'ORDER'     => 'cpt DESC',
        ]);

        return self::toChartData(
            $iterator,
            'registrar_status',
            'registrar_status',
            'cpt',
            PLUGIN_DOMAINMANAGER_SO_DOMAIN_REGISTRAR_STATUS,
            DomainStatusResolver::getStatusLabels(),
            null,
            __('Registrar status', 'domainmanager'),
            __('Registrar status', 'domainmanager'),
        );
    }

    /**
     * "Domains expiring soon" card (Phase 81): bigNumber count of Domain
     * rows whose native `date_expiration` falls within the next
     * self::EXPIRING_SOON_DAYS days — a fixed window, not the per-entity
     * `send_domains_alert_close_expiries_delay` config core's own
     * `Domain::closeExpiriesDomainsCriteria()` uses, since that method is
     * scoped to a single entity and this card spans the active
     * entity+children selection like every other card here.
     */
    public static function domainsExpiringSoon(array $params = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = array_merge(
            [
                'glpi_domains.is_deleted'  => 0,
                'glpi_domains.is_template' => 0,
                'NOT'                      => ['glpi_domains.date_expiration' => null],
                new QueryExpression('glpi_domains.date_expiration >= CURDATE()'),
                new QueryExpression(
                    'glpi_domains.date_expiration <= DATE_ADD(CURDATE(), INTERVAL '
                    . self::EXPIRING_SOON_DAYS . ' DAY)',
                ),
            ],
            getEntitiesRestrictCriteria('glpi_domains', '', '', true),
        );

        $iterator = $DB->request([
            'SELECT' => ['COUNT DISTINCT' => 'glpi_domains.id AS cpt'],
            'FROM'   => 'glpi_domains',
            'WHERE'  => $where,
        ]);
        $count = (int) ($iterator->current()['cpt'] ?? 0);

        // GLPI's date search UI only offers "specify a date" (no relative
        // "+30 days"/"+1 week" shortcut), and relative expressions like
        // '+30 days' are not parsed by the date searchtype comparisons
        // ('morethan'/'lessthan') the way they are for the criteria builder
        // shortcuts — so the drill-down link must carry concrete, computed
        // dates matching what the card itself just queried.
        // 'morethan'/'lessthan' are strict (>, <), so bracket the inclusive
        // [today, today+N days] window the count query used with one day of
        // slack on each side.
        $from = date('Y-m-d', strtotime('-1 day'));
        $until = date('Y-m-d', strtotime('+' . (self::EXPIRING_SOON_DAYS + 1) . ' days'));

        $criteria = [
            'criteria' => [
                [
                    'link'       => 'AND',
                    'field'      => self::SO_DOMAIN_EXPIRATION_DATE,
                    'searchtype' => 'morethan',
                    'value'      => $from,
                ],
                [
                    'link'       => 'AND',
                    'field'      => self::SO_DOMAIN_EXPIRATION_DATE,
                    'searchtype' => 'lessthan',
                    'value'      => $until,
                ],
            ],
            'reset'    => 'reset',
        ];

        return [
            'number' => $count,
            'url'    => Domain::getSearchURL() . '?' . Toolbox::append_params($criteria),
            // Widget::bigNumber() already runs htmlescape() on 'label'/'alt'
            // itself — pre-escaping with __s() here double-encodes "<" into
            // a literal "&lt;" on screen. Use the unescaped translator.
            // 'label' stays short (on-widget title); 'alt' (hover tooltip)
            // repeats the card's own full picker/dashboard title, per the
            // 3-name-slot convention (see references/dashboard-widgets.md).
            'label'  => __('Domains expiring <30 days', 'domainmanager'),
            'alt'    => __('Number of Domains expiring soon (less than 30 days)', 'domainmanager'),
            // Shared across every Domain Manager card — same icon
            // DomainState/SupplierConfig/Profile already use for the
            // plugin, not core's own Domain::getIcon().
            'icon'   => self::ICON,
        ];
    }

    /**
     * Shared chart-shape builder for single-series pie/donut/bar/hbar/
     * multipleNumber cards — matches the flat `{number, label, url}`-per-
     * entry shape `Glpi\Dashboard\Widget::pie()`/`simpleBar()`/
     * `multipleNumber()` actually read (see e.g. core's own
     * `Provider::itemsByFk()`, `Glpi\Dashboard\Provider.php` line ~916:
     * `$data[] = ['number' => ..., 'label' => ..., 'url' => ...]`), *not*
     * the `{labels:[], series:[[...]]}` nested shape those single-series
     * widgets never actually read — every one of `Widget`'s chart builders
     * does `array_merge($default_entry, $entry)` per top-level `$p['data']`
     * entry and reads `$entry['number']`/`$entry['label']`/`$entry['url']`
     * directly.
     *
     * @param array<string, string>|null $labelMap   raw grouped value =>
     *     human label (e.g. `DomainStatusResolver::getStatusLabels()`);
     *     falls back to the raw value itself when a value has no entry.
     * @param (callable(array<string, mixed>): (string|null))|null $urlBuilder
     *     overrides the default single-value `equals` drill-down when a
     *     bucket's URL can't be expressed that way (e.g. a "no driver
     *     linked" catch-all bucket) — return null/empty for no drill-down.
     * @param ?string $widgetLabel short, always-visible on-widget title
     *     (`Glpi\Dashboard\Widget`'s "main-label"/list-header span) — kept
     *     deliberately shorter than the card's own picker/dashboard title
     *     (`dashboardCards()`'s `label`), per the 3-name-slot convention
     *     (see `references/dashboard-widgets.md`).
     * @param ?string $widgetAlt hover-tooltip text (rendered as the
     *     widget's own `title=""` attribute) — only read by
     *     `multipleNumber`/`bigNumber`, silently ignored by
     *     pie/donut/bar/hbar. Convention: same text as the card's own
     *     picker/dashboard title.
     */
    private static function toChartData(
        iterable $iterator,
        string $value_field,
        string $label_field,
        string $count_field,
        int $searchoption_id,
        ?array $labelMap = null,
        ?callable $urlBuilder = null,
        ?string $widgetLabel = null,
        ?string $widgetAlt = null
    ): array {
        $data = [];

        foreach ($iterator as $row) {
            $value = $row[$value_field] ?? null;
            $label = $row[$label_field] ?? null;

            if ($labelMap !== null && isset($labelMap[(string) $value])) {
                // Keyed by the raw grouped value, not the display label —
                // matters when value_field and label_field differ (e.g. the
                // "no driver linked" bucket's value is a fixed 0 but its
                // label column otherwise carries a real supplier name).
                $label = $labelMap[(string) $value];
            } elseif ($label === null || $label === '') {
                // Widget::multipleNumber()/simpleBar() htmlescape() this
                // themselves (same double-encoding trap as bigNumber's
                // label/alt) — use the plain translator, never __s(), for
                // any string that ends up in a per-entry 'label' here.
                $label = __('Not set');
            } else {
                $label = (string) $label;
            }

            if ($urlBuilder !== null) {
                $url = $urlBuilder($row);
            } else {
                $criteria = [
                    'criteria' => [[
                        'field'      => $searchoption_id,
                        'searchtype' => 'equals',
                        'value'      => $value ?? 0,
                    ]],
                    'reset'    => 'reset',
                ];
                $url = Domain::getSearchURL() . '?' . Toolbox::append_params($criteria);
            }

            $data[] = [
                'number' => (int) ($row[$count_field] ?? 0),
                'label'  => $label,
                'url'    => $url ?? '',
            ];
        }

        if (count($data) === 0) {
            // Same "nodata" signal core's own providers use
            // (Glpi\Dashboard\Provider.php) when a query returns no rows.
            $data = ['nodata' => true];
        }

        return [
            'data'  => $data,
            'label' => $widgetLabel ?? '',
            'alt'   => $widgetAlt ?? '',
            // Shared across every Domain Manager dashboard card — same icon
            // DomainState/SupplierConfig/Profile already use for the
            // plugin, not core's own Domain::getIcon().
            'icon'  => self::ICON,
        ];
    }

    /**
     * Idempotent: an admin-edited/deleted dashboard is never recreated on
     * upgrade (matches CloudInventory\Dashboard::install()'s own guard).
     */
    public static function install(Migration $migration): void
    {
        $dashboard = new Dashboard();
        if ($dashboard->getFromDBByCrit(['key' => self::DASHBOARD_KEY]) !== false) {
            return;
        }

        $dashboards_id = $dashboard->add([
            'key'     => self::DASHBOARD_KEY,
            'name'    => 'Domain Manager',
            'context' => 'core',
        ]);

        if (!$dashboards_id) {
            return;
        }

        $cards = [
            [
                'gridstack_id' => 'plugin_domainmanager_domains_by_registrar_' . Uuid::uuid4(),
                'card_id'      => 'plugin_domainmanager_domains_by_registrar',
                'x'            => 0,
                'y'            => 0,
                'width'        => 4,
                'height'       => 3,
                'card_options' => ['widgettype' => 'donut'],
            ],
            [
                'gridstack_id' => 'plugin_domainmanager_sync_status_' . Uuid::uuid4(),
                'card_id'      => 'plugin_domainmanager_sync_status',
                'x'            => 4,
                'y'            => 0,
                'width'        => 4,
                'height'       => 3,
                'card_options' => ['widgettype' => 'donut'],
            ],
            [
                'gridstack_id' => 'plugin_domainmanager_registrar_status_' . Uuid::uuid4(),
                'card_id'      => 'plugin_domainmanager_registrar_status',
                'x'            => 8,
                'y'            => 0,
                'width'        => 4,
                'height'       => 3,
                'card_options' => ['widgettype' => 'donut'],
            ],
            [
                'gridstack_id' => 'plugin_domainmanager_expiring_soon_' . Uuid::uuid4(),
                'card_id'      => 'plugin_domainmanager_expiring_soon',
                'x'            => 0,
                'y'            => 3,
                'width'        => 3,
                'height'       => 2,
                'card_options' => ['widgettype' => 'bigNumber'],
            ],
        ];

        foreach ($cards as $options) {
            $item = new Item();
            $item->addForDashboard($dashboards_id, [$options]);
        }
    }

    public static function uninstall(Migration $migration): void
    {
        $dashboard = new Dashboard();
        if ($dashboard->getFromDBByCrit(['key' => self::DASHBOARD_KEY]) !== false) {
            $dashboard->deleteFromDB(true);
        }
    }
}
