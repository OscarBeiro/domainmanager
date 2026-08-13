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
use DomainRecord;
use DomainRecordType;
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
 * registrar status and domains expiring soon. Phase 84 (not 82/83, which
 * are reserved backlog stubs — see ARCHITECTURE.md §20.4/docs/plans/)
 * continues from there: a "Domains per DNS provider" card, plus
 * `searchequalsonfield` drill-down fixes for the status cards.
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

        $cards['plugin_domainmanager_domains_by_dns_provider'] = [
            'widgettype' => ['pie', 'donut', 'multipleNumber', 'bar', 'hbar'],
            'itemtype'   => Domain::class,
            'group'      => __s('Domain Manager'),
            'label'      => __s('Managed domains per DNS provider', 'domainmanager'),
            'provider'   => self::class . '::domainsByDnsProvider',
            'cache'      => false,
        ];

        $cards['plugin_domainmanager_domains_by_tld'] = [
            'widgettype' => ['pie', 'donut', 'multipleNumber', 'bar', 'hbar'],
            'itemtype'   => Domain::class,
            'group'      => __s('Domain Manager'),
            'label'      => __s('Managed domains by TLD', 'domainmanager'),
            'provider'   => self::class . '::domainsByTld',
            'cache'      => false,
        ];

        $cards['plugin_domainmanager_domains_by_registrar_and_tld'] = [
            'widgettype' => ['bars', 'hBars', 'stackedbars', 'stackedHBars', 'lines'],
            'itemtype'   => Domain::class,
            'group'      => __s('Domain Manager'),
            'label'      => __s('Managed domains per registrar by TLD', 'domainmanager'),
            'provider'   => self::class . '::domainsByRegistrarAndTld',
            'cache'      => false,
        ];

        $cards['plugin_domainmanager_sync_status'] = [
            'widgettype' => ['pie', 'donut', 'multipleNumber', 'bar', 'hbar'],
            'itemtype'   => Domain::class,
            'group'      => __s('Domain Manager'),
            // Matches PLUGIN_DOMAINMANAGER_SO_DOMAIN_DNS_STATUS's own
            // search-option name ("DNS sync status") — the card id/gridstack
            // slot stays 'sync_status' (already installed on existing
            // dashboards), only the displayed picker title changed.
            'label'      => __s('DNS sync status', 'domainmanager'),
            'provider'   => self::class . '::syncStatusBreakdown',
            'cache'      => false,
        ];

        $cards['plugin_domainmanager_registrar_status'] = [
            'widgettype' => ['pie', 'donut', 'multipleNumber', 'bar', 'hbar'],
            'itemtype'   => Domain::class,
            'group'      => __s('Domain Manager'),
            // Matches PLUGIN_DOMAINMANAGER_SO_DOMAIN_REGISTRAR_STATUS's own
            // search-option name.
            'label'      => __s('Registrar sync status', 'domainmanager'),
            'provider'   => self::class . '::registrarStatusBreakdown',
            'cache'      => false,
        ];

        $cards['plugin_domainmanager_domains_count'] = [
            'widgettype' => ['bigNumber'],
            'itemtype'   => Domain::class,
            'group'      => __s('Domain Manager'),
            'label'      => __s('Number of Managed Domains', 'domainmanager'),
            'provider'   => self::class . '::domainsCount',
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

        $cards['plugin_domainmanager_records_count'] = [
            'widgettype' => ['bigNumber'],
            'itemtype'   => DomainRecord::class,
            'group'      => __s('Domain Manager'),
            'label'      => __s('Number of Managed Records', 'domainmanager'),
            'provider'   => self::class . '::recordsCount',
            'cache'      => false,
        ];

        $cards['plugin_domainmanager_records_by_type'] = [
            'widgettype' => ['pie', 'donut', 'multipleNumber', 'bar', 'hbar'],
            'itemtype'   => DomainRecord::class,
            'group'      => __s('Domain Manager'),
            'label'      => __s('Managed records by type', 'domainmanager'),
            'provider'   => self::class . '::recordsByType',
            'cache'      => false,
        ];

        $cards['plugin_domainmanager_records_by_dns_provider'] = [
            'widgettype' => ['pie', 'donut', 'multipleNumber', 'bar', 'hbar'],
            'itemtype'   => DomainRecord::class,
            'group'      => __s('Domain Manager'),
            'label'      => __s('Managed records by DNS provider', 'domainmanager'),
            'provider'   => self::class . '::recordsByDnsProvider',
            'cache'      => false,
        ];

        $cards['plugin_domainmanager_records_by_dns_provider_and_type'] = [
            'widgettype' => ['bars', 'hBars', 'stackedbars', 'stackedHBars', 'lines'],
            'itemtype'   => DomainRecord::class,
            'group'      => __s('Domain Manager'),
            'label'      => __s('Managed records per DNS provider by type', 'domainmanager'),
            'provider'   => self::class . '::recordsByDnsProviderAndType',
            'cache'      => false,
        ];

        $cards['plugin_domainmanager_proxied_records'] = [
            'widgettype' => ['pie', 'donut', 'multipleNumber', 'bar', 'hbar'],
            'itemtype'   => DomainRecord::class,
            'group'      => __s('Domain Manager'),
            'label'      => __s('Proxied records', 'domainmanager'),
            'provider'   => self::class . '::proxiedRecordsBreakdown',
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
        // is_managed = 1: this card only ever counts managed domains, same
        // scope domainsCount()'s "Number of Managed Domains" bigNumber uses
        // — the alt text below already claimed "Managed domains per
        // registrar" before this filter existed, which was misleading.
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
            'INNER JOIN' => [
                DomainState::getTable() => [
                    'ON' => [DomainState::getTable() => 'domains_id', 'glpi_domains' => 'id'],
                ],
            ],
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
                    'glpi_domains.is_deleted'                => 0,
                    'glpi_domains.is_template'                => 0,
                    DomainState::getTable() . '.is_managed'   => 1,
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
                    ],
                    ],
                    'reset'    => 'reset',
                ];

                return Domain::getSearchURL() . '?' . Toolbox::append_params($criteria);
            },
            __('Domains per registrar', 'domainmanager'),
            __('Managed domains per registrar', 'domainmanager'),
        );
    }

    /**
     * "Domains per DNS provider" card: groups Domain by
     * `DomainState.dns_suppliers_id` — the *matched* Supplier record for
     * the resolved DNS provider (same relationship the domain panel's own
     * "DNS Provider" badge and `DomainState::getDomainsForSupplier()` use),
     * not `detected_provider`'s free-text sync-resolved name, which may not
     * correspond to any Supplier configured in GLPI at all and (§ search
     * option 9407's own `searchequalsonfield` fix) isn't reliably
     * filterable via the Search UI to begin with. A domain with no match —
     * never synced, or synced to a provider with no linked Supplier —
     * folds into a single "Not matched to a supplier" bucket, same
     * reasoning as `domainsByRegistrar()`'s "No driver linked" catch-all.
     * Filtered to `DomainState.is_managed = 1`, same scope as
     * `domainsByRegistrar()`.
     */
    public static function domainsByDnsProvider(array $params = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Repeated below (SELECT + GROUPBY), never referenced by its SELECT
        // alias: DomainState has a real dns_suppliers_id column of its own,
        // which always wins GROUP BY resolution over a same-named SELECT
        // alias — the query ran fine on an install with only one row per
        // dns_suppliers_id value, but a real multi-domain install hits
        // MySQL's "column 'dns_suppliers_id' in GROUP BY is ambiguous"
        // (confirmed live against testing_glpi_1). Same "repeat the full
        // expression, don't rely on the alias" fix as domainsByRegistrar()'s
        // CASE expression and recordsByDnsProviderAndType()'s COALESCE.
        $dnsSuppliersIdExpr = 'COALESCE(' . $DB->quoteName(DomainState::getTable() . '.dns_suppliers_id') . ', 0)';

        $iterator = $DB->request([
            'SELECT'    => [
                new QueryExpression($dnsSuppliersIdExpr . ' AS ' . $DB->quoteName('dns_suppliers_id')),
                new QueryExpression('MAX(' . $DB->quoteName('supplier.name') . ') AS ' . $DB->quoteName('supplier_name')),
                'COUNT DISTINCT' => 'glpi_domains.id AS cpt',
            ],
            'FROM'      => 'glpi_domains',
            'INNER JOIN' => [
                DomainState::getTable() => [
                    'ON' => [DomainState::getTable() => 'domains_id', 'glpi_domains' => 'id'],
                ],
            ],
            'LEFT JOIN' => [
                'glpi_suppliers AS supplier' => [
                    'ON' => ['supplier' => 'id', DomainState::getTable() => 'dns_suppliers_id'],
                ],
            ],
            'WHERE'     => array_merge(
                [
                    'glpi_domains.is_deleted'              => 0,
                    'glpi_domains.is_template'              => 0,
                    DomainState::getTable() . '.is_managed' => 1,
                ],
                getEntitiesRestrictCriteria('glpi_domains', '', '', true),
            ),
            'GROUPBY'   => [new QueryExpression($dnsSuppliersIdExpr)],
            'ORDER'     => 'cpt DESC',
        ]);

        return self::toChartData(
            $iterator,
            'dns_suppliers_id',
            'supplier_name',
            'cpt',
            PLUGIN_DOMAINMANAGER_SO_DOMAIN_DNS_SUPPLIER,
            // __(), not __s() — see the escaping note inside toChartData().
            ['0' => __('Not matched to a supplier', 'domainmanager')],
            static function (array $row) {
                $suppliers_id = (int) ($row['dns_suppliers_id'] ?? 0);
                if ($suppliers_id <= 0) {
                    // No single supplier id represents this bucket, same
                    // reasoning as the registrar card's "no driver linked"
                    // catch-all.
                    return null;
                }

                $criteria = [
                    'criteria' => [[
                        'field'      => PLUGIN_DOMAINMANAGER_SO_DOMAIN_DNS_SUPPLIER,
                        'searchtype' => 'equals',
                        'value'      => $suppliers_id,
                    ],
                    ],
                    'reset'    => 'reset',
                ];

                return Domain::getSearchURL() . '?' . Toolbox::append_params($criteria);
            },
            __('Domains per DNS provider', 'domainmanager'),
            __('Managed domains per DNS provider', 'domainmanager'),
        );
    }

    /**
     * "Domains by TLD" card (Phase 83 "per-TLD dashboard breakdown"): groups
     * Domain by the cached `DomainState.tld` column (see `TldExtractor`,
     * `Installer::addTldColumn()`) — a `LIKE '%.com'` scan on every
     * dashboard render doesn't scale, hence the dedicated indexed column.
     * `tld` is a plain direct column (not a CASE/COALESCE expression like
     * `domainsByRegistrar()`'s registrar bucket), but a domain with no
     * state row yet (LEFT JOIN) still needs its NULL folded into the empty-
     * string "not yet computed" bucket, so the same "repeat the full
     * expression in GROUPBY, don't rely on the alias" pattern applies here
     * too — `DomainState.tld` is a real joined column with the same name as
     * the SELECT alias, and MySQL prefers the real (un-coalesced, possibly
     * NULL) column over the alias when resolving GROUP BY.
     *
     * Rows with no real TLD — no state row yet (NULL), never synced (empty
     * string), or `TldExtractor::extract()` deliberately returned '' for a
     * reserved/private-use suffix (`.internal`, `.local`, …) or a malformed,
     * dot-less domain name — are excluded outright rather than folded into
     * a "Not set" bucket: this is a breakdown of real internet TLDs, and a
     * GLPI test/inventory entry like `something.internal` has no place next
     * to `.com`/`.gal` in it. `tld <> ''` also excludes NULL rows: SQL's
     * three-valued logic means `NULL <> ''` evaluates to NULL, which WHERE
     * treats as false. Filtered to `DomainState.is_managed = 1`, same scope
     * as `domainsByRegistrar()`.
     */
    public static function domainsByTld(array $params = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'SELECT'    => [
                DomainState::getTable() . '.tld AS tld',
                'COUNT DISTINCT' => 'glpi_domains.id AS cpt',
            ],
            'FROM'      => 'glpi_domains',
            'INNER JOIN' => [
                DomainState::getTable() => [
                    'ON' => [DomainState::getTable() => 'domains_id', 'glpi_domains' => 'id'],
                ],
            ],
            'WHERE'     => array_merge(
                [
                    'glpi_domains.is_deleted'                => 0,
                    'glpi_domains.is_template'                => 0,
                    DomainState::getTable() . '.tld'          => ['<>', ''],
                    DomainState::getTable() . '.is_managed'   => 1,
                ],
                getEntitiesRestrictCriteria('glpi_domains', '', '', true),
            ),
            'GROUPBY'   => [DomainState::getTable() . '.tld'],
            'ORDER'     => 'cpt DESC',
        ]);

        return self::toChartData(
            $iterator,
            'tld',
            'tld',
            'cpt',
            PLUGIN_DOMAINMANAGER_SO_DOMAIN_TLD,
            null,
            null,
            __('Domains by TLD', 'domainmanager'),
            __('Managed domains by TLD', 'domainmanager'),
        );
    }

    /**
     * "Domains per registrar by TLD" card (Phase 83 follow-up, user-
     * requested "3-dimensional" view): a genuinely multi-dimensional card,
     * same shape/rationale as `recordsByDnsProviderAndType()` — "per
     * registrar" is the primary axis (one label per registrar Supplier,
     * same "no driver linked" bucketing as `domainsByRegistrar()`), broken
     * down by TLD (one series per TLD), mirroring
     * `recordsByDnsProviderAndType()`'s own "per DNS provider" (labels) "by
     * type" (series) axis assignment. Only
     * `bars`/`hBars`/`stackedbars`/`stackedHBars`/`lines` read this shape;
     * `pie`/`donut`/`bigNumber` would silently misrender it.
     *
     * Labels (registrars) sorted ascending by total domain count, same
     * "keep the tail" reasoning as `recordsByDnsProviderAndType()` — the
     * dashboard widget's client-side "limit" control trims from the front
     * of the labels array, so the biggest registrar buckets must be last
     * here to survive a limit smaller than the number of registrars
     * present.
     *
     * Same "exclude, don't bucket" treatment of a domain with no real TLD
     * as `domainsByTld()` — an INNER JOIN to the states table plus
     * `tld <> ''` (see that method's own doc comment for the NULL/empty
     * reasoning) rather than a LEFT JOIN + COALESCE + "Not set" series.
     * Filtered to `DomainState.is_managed = 1`, same scope as
     * `domainsByRegistrar()`.
     */
    public static function domainsByRegistrarAndTld(array $params = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Same CASE expression as domainsByRegistrar() — only a Supplier
        // with an active API driver linked counts as a real "registrar".
        $registrarSuppliersExpr = 'CASE WHEN supplier_config.id IS NOT NULL THEN infocom.suppliers_id ELSE 0 END';

        $iterator = $DB->request([
            'SELECT'    => [
                new QueryExpression($registrarSuppliersExpr . ' AS ' . $DB->quoteName('registrar_suppliers_id')),
                new QueryExpression('MAX(' . $DB->quoteName('supplier.name') . ') AS ' . $DB->quoteName('supplier_name')),
                DomainState::getTable() . '.tld AS tld',
                'COUNT DISTINCT' => 'glpi_domains.id AS cpt',
            ],
            'FROM'      => 'glpi_domains',
            'INNER JOIN' => [
                DomainState::getTable() => [
                    'ON' => [DomainState::getTable() => 'domains_id', 'glpi_domains' => 'id'],
                ],
            ],
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
                    'glpi_domains.is_deleted'                => 0,
                    'glpi_domains.is_template'                => 0,
                    DomainState::getTable() . '.tld'          => ['<>', ''],
                    DomainState::getTable() . '.is_managed'   => 1,
                ],
                getEntitiesRestrictCriteria('glpi_domains', '', '', true),
            ),
            'GROUPBY'   => [new QueryExpression($registrarSuppliersExpr), DomainState::getTable() . '.tld'],
        ]);

        $registrarTotals = [];
        $registrarNames  = [];
        $tldNames        = [];
        $matrix          = [];

        foreach ($iterator as $row) {
            $tld          = (string) ($row['tld'] ?? '');
            $suppliers_id = (int) ($row['registrar_suppliers_id'] ?? 0);
            $cpt          = (int) ($row['cpt'] ?? 0);

            $registrarTotals[$suppliers_id] = ($registrarTotals[$suppliers_id] ?? 0) + $cpt;
            $registrarNames[$suppliers_id]  = $row['supplier_name'] ?? null;
            $tldNames[$tld]                 = $tld !== '' ? $tld : __('Not set');
            $matrix[$suppliers_id][$tld]    = $cpt;
        }

        if (count($registrarTotals) === 0) {
            return [
                'data'  => ['nodata' => true],
                'label' => __('Domains per registrar by TLD', 'domainmanager'),
                'alt'   => __('Managed domains per registrar by TLD', 'domainmanager'),
                'icon'  => self::ICON,
            ];
        }

        // Ascending, deliberately — see this method's doc comment.
        asort($registrarTotals);

        $registrarLabelMap = ['0' => __('No driver linked', 'domainmanager')];

        $labels     = [];
        $seriesData = [];
        foreach ($registrarTotals as $suppliers_id => $total) {
            if (isset($registrarLabelMap[(string) $suppliers_id])) {
                $labels[] = $registrarLabelMap[(string) $suppliers_id];
            } else {
                $name     = $registrarNames[$suppliers_id] ?? null;
                $labels[] = ($name === null || $name === '') ? __('Not set') : $name;
            }

            foreach ($tldNames as $tld => $tldLabel) {
                // null, not 0 — a registrar/TLD combo that never occurred
                // shouldn't fabricate a data point (0-value stack segments
                // still show up in the tooltip/legend as real entries; every
                // other card in this file only ever emits rows that exist,
                // via toChartData()'s SQL-grouped iterator, so this matrix
                // build is the one place that must replicate that "absent,
                // not zero" behaviour by hand).
                $cpt = $matrix[$suppliers_id][$tld] ?? null;
                if ($cpt === null) {
                    $seriesData[$tld][] = null;
                    continue;
                }

                // No single supplier id represents the "no driver linked"
                // bucket (same reasoning as domainsByRegistrar()'s own
                // urlBuilder), so that segment gets a plain value with no
                // per-point drill-down instead of a meaningless "supplier 0"
                // search.
                if ($suppliers_id <= 0) {
                    $seriesData[$tld][] = $cpt;
                    continue;
                }

                $criteria = [
                    'criteria' => [
                        [
                            'field'      => 53,
                            'searchtype' => 'equals',
                            'value'      => $suppliers_id,
                        ],
                        [
                            'link'       => 'AND',
                            'field'      => PLUGIN_DOMAINMANAGER_SO_DOMAIN_TLD,
                            'searchtype' => 'equals',
                            'value'      => $tld,
                        ],
                    ],
                    'reset'    => 'reset',
                ];

                $seriesData[$tld][] = [
                    'value' => $cpt,
                    'url'   => Domain::getSearchURL() . '?' . Toolbox::append_params($criteria),
                ];
            }
        }

        $series = [];
        foreach ($tldNames as $tld => $tldLabel) {
            $series[] = [
                'name' => $tldLabel,
                'data' => $seriesData[$tld],
            ];
        }

        return [
            'data'  => [
                'labels' => $labels,
                'series' => $series,
            ],
            'label' => __('Domains per registrar by TLD', 'domainmanager'),
            'alt'   => __('Managed domains per registrar by TLD', 'domainmanager'),
            'icon'  => self::ICON,
        ];
    }

    /**
     * "DNS sync status" card (picker title/search option name;
     * "DNS status" on-widget): groups Domain by the existing
     * PLUGIN_DOMAINMANAGER_SO_DOMAIN_DNS_STATUS search option (9409) —
     * already registered and searchable, no new option allocated. That
     * option needed its own `searchequalsonfield` fix (Phase 84 follow-up)
     * for the same reason PLUGIN_DOMAINMANAGER_SO_DOMAIN_NS_PROVIDER did —
     * see that option's own comment in setup.php. Filtered to
     * `DomainState.is_managed = 1`, same scope as `domainsByRegistrar()` —
     * an unmanaged domain that was never synced (no state row) is excluded
     * outright rather than folding into the "Never synchronized" bucket.
     */
    public static function syncStatusBreakdown(array $params = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Repeated below (SELECT + GROUPBY), never referenced by its SELECT
        // alias: DomainState has a real dns_status column of its own, which
        // always wins GROUP BY resolution over a same-named SELECT alias —
        // confirmed live against testing_glpi_1 ("column 'dns_status' in
        // GROUP BY is ambiguous" on a real multi-domain install). Same
        // "repeat the full expression, don't rely on the alias" fix as
        // registrarStatusBreakdown()'s own CASE expression.
        $dnsStatusExpr = 'COALESCE(' . $DB->quoteName(DomainState::getTable() . '.dns_status')
            . ', \'' . DomainState::STATUS_NEVER . '\')';

        $iterator = $DB->request([
            'SELECT'    => [
                new QueryExpression($dnsStatusExpr . ' AS ' . $DB->quoteName('dns_status')),
                'COUNT DISTINCT' => 'glpi_domains.id AS cpt',
            ],
            'FROM'      => 'glpi_domains',
            'INNER JOIN' => [
                DomainState::getTable() => [
                    'ON' => [DomainState::getTable() => 'domains_id', 'glpi_domains' => 'id'],
                ],
            ],
            'WHERE'     => array_merge(
                [
                    'glpi_domains.is_deleted'              => 0,
                    'glpi_domains.is_template'              => 0,
                    DomainState::getTable() . '.is_managed' => 1,
                ],
                getEntitiesRestrictCriteria('glpi_domains', '', '', true),
            ),
            'GROUPBY'   => [new QueryExpression($dnsStatusExpr)],
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
            // Short on-widget label; 'alt' repeats the card's own picker
            // title (PLUGIN_DOMAINMANAGER_SO_DOMAIN_DNS_STATUS's search
            // option name), per the 3-name-slot convention.
            __('DNS status', 'domainmanager'),
            __('DNS sync status', 'domainmanager'),
        );
    }

    /**
     * "Registrar sync status" card (Phase 81; picker title/search option
     * name, "Registrar status" on-widget): groups Domain by the existing
     * PLUGIN_DOMAINMANAGER_SO_DOMAIN_REGISTRAR_STATUS search option
     * (9408) — mirrors the "DNS sync status" card's shape (same
     * `searchequalsonfield` fix applies here too, Phase 84 follow-up),
     * plus the same live-Infocom reconciliation
     * `DomainState::getDomainsForSupplier()` already does: a state row
     * whose `registrar_suppliers_id` mirror no longer matches the domain's
     * *current* Infocom supplier describes a stale, now-superseded
     * registrar relationship, so it's folded into `STATUS_NEVER` here
     * rather than shown as-is. Filtered to `DomainState.is_managed = 1`,
     * same scope as `domainsByRegistrar()`.
     */
    public static function registrarStatusBreakdown(array $params = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Repeated below (SELECT + GROUPBY), never referenced by its SELECT
        // alias: `DomainState` has a real `registrar_status` column of its
        // own, so a bare `GROUPBY => ['registrar_status']` resolves to that
        // raw column instead of this CASE expression's alias (a real column
        // always wins over a same-named alias in MySQL/MariaDB's GROUP BY
        // resolution) — rows whose raw column differs (NULL vs a stale
        // stored status) but whose CASE result is identically STATUS_NEVER
        // silently split into separate "Never synchronized" buckets. Same
        // hazard/fix as recordsByDnsProvider()'s dns_suppliers_id.
        //
        // Both sides of the comparison are COALESCE'd to 0: a domain with no
        // Infocom row at all (LEFT JOIN, `infocom.suppliers_id` is NULL) has
        // `registrar_suppliers_id = 0` in that case too, but `0 = NULL`
        // evaluates to NULL under SQL's three-valued logic — not true — so
        // without the COALESCE the CASE always fell through to its ELSE
        // branch for that domain, masking its real stored `registrar_status`
        // (typically `unconfigured`, set by SyncEngine when no registrar
        // Supplier is linked) behind a hardcoded `STATUS_NEVER` instead.
        $registrarStatusExpr = 'CASE WHEN COALESCE(' . $DB->quoteName(DomainState::getTable() . '.registrar_suppliers_id') . ', 0)'
            . ' = COALESCE(' . $DB->quoteName('infocom.suppliers_id') . ', 0)'
            . ' THEN COALESCE(' . $DB->quoteName(DomainState::getTable() . '.registrar_status')
            . ', \'' . DomainState::STATUS_NEVER . '\')'
            . ' ELSE \'' . DomainState::STATUS_NEVER . '\' END';

        $iterator = $DB->request([
            'SELECT'    => [
                new QueryExpression($registrarStatusExpr . ' AS ' . $DB->quoteName('registrar_status')),
                'COUNT DISTINCT' => 'glpi_domains.id AS cpt',
            ],
            'FROM'      => 'glpi_domains',
            'INNER JOIN' => [
                DomainState::getTable() => [
                    'ON' => [DomainState::getTable() => 'domains_id', 'glpi_domains' => 'id'],
                ],
            ],
            'LEFT JOIN' => [
                'glpi_infocoms AS infocom' => [
                    'ON' => [
                        'infocom'      => 'items_id',
                        'glpi_domains' => 'id',
                        ['AND' => ['infocom.itemtype' => Domain::class]],
                    ],
                ],
            ],
            'WHERE'     => array_merge(
                [
                    'glpi_domains.is_deleted'              => 0,
                    'glpi_domains.is_template'              => 0,
                    DomainState::getTable() . '.is_managed' => 1,
                ],
                getEntitiesRestrictCriteria('glpi_domains', '', '', true),
            ),
            'GROUPBY'   => [new QueryExpression($registrarStatusExpr)],
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
            // Short on-widget label; 'alt' repeats the card's own picker
            // title (PLUGIN_DOMAINMANAGER_SO_DOMAIN_REGISTRAR_STATUS's
            // search option name), per the 3-name-slot convention.
            __('Registrar status', 'domainmanager'),
            __('Registrar sync status', 'domainmanager'),
        );
    }

    /**
     * "Number of Managed Domains" bigNumber: count of `Domain` rows with
     * `DomainState.is_managed = 1` — the actual domain-level "Managed" flag
     * (search option `PLUGIN_DOMAINMANAGER_SO_DOMAIN_MANAGED`/9406,
     * §9 Phase 14) already surfaced on `domain.form.php`'s injected panel
     * (`DomainForm::injectDomain()`'s `$is_managed`) and search UI — not a
     * bare scope count (a first pass here mistakenly counted every
     * in-scope `Domain` row regardless of `is_managed`, conflating "domains
     * GLPI knows about" with "domains this plugin actually manages").
     * Distinct from both core's own generic "Number of Domain" card
     * (`bn_count_Domain`, no such filter at all) and `recordsCount()`'s
     * record-level "Managed Records" count (`ImportedRecord.is_managed`, a
     * different table/flag entirely).
     */
    public static function domainsCount(array $params = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = array_merge(
            [
                DomainState::getTable() . '.is_managed' => 1,
                'glpi_domains.is_deleted'                => 0,
                'glpi_domains.is_template'                => 0,
            ],
            getEntitiesRestrictCriteria('glpi_domains', '', '', true),
        );

        $iterator = $DB->request([
            'SELECT'    => ['COUNT DISTINCT' => 'glpi_domains.id AS cpt'],
            'FROM'      => 'glpi_domains',
            'INNER JOIN' => [
                DomainState::getTable() => [
                    'ON' => [DomainState::getTable() => 'domains_id', 'glpi_domains' => 'id'],
                ],
            ],
            'WHERE'     => $where,
        ]);
        $count = (int) ($iterator->current()['cpt'] ?? 0);

        $criteria = [
            'criteria' => [[
                'field'      => PLUGIN_DOMAINMANAGER_SO_DOMAIN_MANAGED,
                'searchtype' => 'equals',
                'value'      => 1,
            ],
            ],
            'reset'    => 'reset',
        ];

        return [
            'number' => $count,
            'url'    => Domain::getSearchURL() . '?' . Toolbox::append_params($criteria),
            'label'  => __('Managed domains', 'domainmanager'),
            'alt'    => __('Number of Managed Domains', 'domainmanager'),
            'icon'   => self::ICON,
        ];
    }

    /**
     * "Domains expiring soon" card (Phase 81): bigNumber count of Domain
     * rows whose native `date_expiration` falls within the next
     * self::EXPIRING_SOON_DAYS days — a fixed window, not the per-entity
     * `send_domains_alert_close_expiries_delay` config core's own
     * `Domain::closeExpiriesDomainsCriteria()` uses, since that method is
     * scoped to a single entity and this card spans the active
     * entity+children selection like every other card here. Filtered to
     * `DomainState.is_managed = 1`, same scope as `domainsCount()`'s
     * "Number of Managed Domains".
     */
    public static function domainsExpiringSoon(array $params = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = array_merge(
            [
                'glpi_domains.is_deleted'                => 0,
                'glpi_domains.is_template'                => 0,
                DomainState::getTable() . '.is_managed'   => 1,
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
            'SELECT'    => ['COUNT DISTINCT' => 'glpi_domains.id AS cpt'],
            'FROM'      => 'glpi_domains',
            'INNER JOIN' => [
                DomainState::getTable() => [
                    'ON' => [DomainState::getTable() => 'domains_id', 'glpi_domains' => 'id'],
                ],
            ],
            'WHERE'     => $where,
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
                    'field'      => PLUGIN_DOMAINMANAGER_SO_DOMAIN_MANAGED,
                    'searchtype' => 'equals',
                    'value'      => 1,
                ],
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
     * "Managed records" bigNumber (Phase 85): counts `ImportedRecord` rows
     * with `is_managed = 1` joined back to their native `DomainRecord`/
     * `Domain` for the deleted/template/entity filters every other card
     * here applies.
     */
    public static function recordsCount(array $params = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $recordsTable = ImportedRecord::getTable();

        $where = array_merge(
            [
                $recordsTable . '.is_managed' => 1,
                'glpi_domains.is_deleted'     => 0,
                'glpi_domains.is_template'    => 0,
            ],
            getEntitiesRestrictCriteria('glpi_domains', '', '', true),
        );

        $iterator = $DB->request([
            'SELECT'    => ['COUNT DISTINCT' => $recordsTable . '.id AS cpt'],
            // Bare (unaliased) FROM: DBmysqlIterator::buildQuery() runs
            // DBmysql::quoteName() on the *entire* 'FROM' string when it's
            // a plain scalar — `'<table> AS <alias>'` gets wrapped whole in
            // backticks as one broken identifier (silent SQL syntax error,
            // surfaced only as "Error rendering card!"). Every join alias
            // below ('... AS domainrecord' etc.) goes through the separate
            // join-builder code path instead, which does handle aliasing —
            // only the top-level FROM table can't be aliased this way.
            'FROM'      => $recordsTable,
            'INNER JOIN' => [
                DomainRecord::getTable() . ' AS domainrecord' => [
                    'ON' => [$recordsTable => 'domainrecords_id', 'domainrecord' => 'id'],
                ],
                'glpi_domains' => [
                    'ON' => ['domainrecord' => 'domains_id', 'glpi_domains' => 'id'],
                ],
            ],
            'WHERE'     => $where,
        ]);
        $count = (int) ($iterator->current()['cpt'] ?? 0);

        $criteria = [
            'criteria' => [[
                'field'      => PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_MANAGED,
                'searchtype' => 'equals',
                'value'      => 1,
            ],
            ],
            'reset'    => 'reset',
        ];

        return [
            'number' => $count,
            'url'    => DomainRecord::getSearchURL() . '?' . Toolbox::append_params($criteria),
            'label'  => __('Managed records', 'domainmanager'),
            'alt'    => __('Number of Managed Records', 'domainmanager'),
            'icon'   => self::ICON,
        ];
    }

    /**
     * "Managed records by type" (Phase 85): same ownership scope as
     * recordsCount(), grouped by `DomainRecordType` (native SO id 3 on
     * `DomainRecord`). Drill-down needs both the type and the "Managed"
     * flag, so `toChartData()`'s default single-field `equals` builder
     * can't be used — the same two-criteria pattern as
     * `domainsExpiringSoon()`'s date bracket.
     */
    public static function recordsByType(array $params = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $recordsTable = ImportedRecord::getTable();

        $iterator = $DB->request([
            'SELECT'    => [
                'domainrecordtype.id AS domainrecordtypes_id',
                new QueryExpression('MAX(' . $DB->quoteName('domainrecordtype.name') . ') AS ' . $DB->quoteName('type_name')),
                'COUNT DISTINCT' => $recordsTable . '.id AS cpt',
            ],
            // See recordsCount() for why FROM stays unaliased.
            'FROM'      => $recordsTable,
            'INNER JOIN' => [
                DomainRecord::getTable() . ' AS domainrecord' => [
                    'ON' => [$recordsTable => 'domainrecords_id', 'domainrecord' => 'id'],
                ],
                'glpi_domains' => [
                    'ON' => ['domainrecord' => 'domains_id', 'glpi_domains' => 'id'],
                ],
                DomainRecordType::getTable() . ' AS domainrecordtype' => [
                    'ON' => ['domainrecord' => 'domainrecordtypes_id', 'domainrecordtype' => 'id'],
                ],
            ],
            'WHERE'     => array_merge(
                [
                    $recordsTable . '.is_managed' => 1,
                    'glpi_domains.is_deleted'     => 0,
                    'glpi_domains.is_template'    => 0,
                ],
                getEntitiesRestrictCriteria('glpi_domains', '', '', true),
            ),
            'GROUPBY'   => ['domainrecordtype.id'],
            'ORDER'     => 'cpt DESC',
        ]);

        return self::toChartData(
            $iterator,
            'domainrecordtypes_id',
            'type_name',
            'cpt',
            3,
            null,
            static function (array $row) {
                $criteria = [
                    'criteria' => [
                        [
                            'field'      => 3,
                            'searchtype' => 'equals',
                            'value'      => (int) ($row['domainrecordtypes_id'] ?? 0),
                        ],
                        [
                            'link'       => 'AND',
                            'field'      => PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_MANAGED,
                            'searchtype' => 'equals',
                            'value'      => 1,
                        ],
                    ],
                    'reset'    => 'reset',
                ];

                return DomainRecord::getSearchURL() . '?' . Toolbox::append_params($criteria);
            },
            __('Records by type', 'domainmanager'),
            __('Managed records by type', 'domainmanager'),
        );
    }

    /**
     * "Managed records by DNS provider" (Phase 85): same
     * domain->`DomainState`->Supplier matching shape as
     * `domainsByDnsProvider()`, counted over records instead of domains.
     * Unlike that card, there is no search option exposing
     * `DomainState.dns_suppliers_id` under the `DomainRecord` itemtype
     * (`PLUGIN_DOMAINMANAGER_SO_DOMAIN_DNS_SUPPLIER` is only registered for
     * `Domain`), so the drill-down can only carry the "Managed" criterion —
     * a superset of each segment's exact scope, same accepted tradeoff as
     * `domainsByRegistrar()`'s "no driver" bucket / `proxiedRecordsBreakdown()`
     * below.
     */
    public static function recordsByDnsProvider(array $params = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $recordsTable = ImportedRecord::getTable();
        // Repeated below (SELECT + GROUPBY) rather than referenced by its
        // SELECT alias — `DomainState` has a real `dns_suppliers_id` column
        // of its own, so a bare `GROUPBY => ['dns_suppliers_id']` resolves
        // to that raw, non-coalesced column instead of the SELECT-list
        // alias (a real column always wins over a same-named alias in
        // MySQL's GROUP BY resolution), splitting NULL/0 into separate
        // "unmatched" buckets. See proxiedRecordsBreakdown()'s identical
        // fix for the same hazard.
        $dnsSuppliersIdExpr = 'COALESCE(' . $DB->quoteName(DomainState::getTable() . '.dns_suppliers_id') . ', 0)';

        $iterator = $DB->request([
            'SELECT'    => [
                new QueryExpression($dnsSuppliersIdExpr . ' AS ' . $DB->quoteName('dns_suppliers_id')),
                new QueryExpression('MAX(' . $DB->quoteName('supplier.name') . ') AS ' . $DB->quoteName('supplier_name')),
                'COUNT DISTINCT' => $recordsTable . '.id AS cpt',
            ],
            // See recordsCount() for why FROM stays unaliased.
            'FROM'      => $recordsTable,
            'INNER JOIN' => [
                DomainRecord::getTable() . ' AS domainrecord' => [
                    'ON' => [$recordsTable => 'domainrecords_id', 'domainrecord' => 'id'],
                ],
                'glpi_domains' => [
                    'ON' => ['domainrecord' => 'domains_id', 'glpi_domains' => 'id'],
                ],
            ],
            'LEFT JOIN' => [
                DomainState::getTable() => [
                    'ON' => [DomainState::getTable() => 'domains_id', 'glpi_domains' => 'id'],
                ],
                'glpi_suppliers AS supplier' => [
                    'ON' => ['supplier' => 'id', DomainState::getTable() => 'dns_suppliers_id'],
                ],
            ],
            'WHERE'     => array_merge(
                [
                    $recordsTable . '.is_managed' => 1,
                    'glpi_domains.is_deleted'     => 0,
                    'glpi_domains.is_template'    => 0,
                ],
                getEntitiesRestrictCriteria('glpi_domains', '', '', true),
            ),
            'GROUPBY'   => [new QueryExpression($dnsSuppliersIdExpr)],
            'ORDER'     => 'cpt DESC',
        ]);

        return self::toChartData(
            $iterator,
            'dns_suppliers_id',
            'supplier_name',
            'cpt',
            PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_MANAGED,
            ['0' => __('Not matched to a supplier', 'domainmanager')],
            static function (array $row) {
                $criteria = [
                    'criteria' => [[
                        'field'      => PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_MANAGED,
                        'searchtype' => 'equals',
                        'value'      => 1,
                    ],
                    ],
                    'reset'    => 'reset',
                ];

                return DomainRecord::getSearchURL() . '?' . Toolbox::append_params($criteria);
            },
            __('Records by DNS provider', 'domainmanager'),
            __('Managed records by DNS provider', 'domainmanager'),
        );
    }

    /**
     * "Managed records per DNS provider by type" (Phase 87): a genuinely
     * multi-dimensional card — mirrors core's own
     * `Provider::nbTicketsBySlaStatusAndTechnician()` shape
     * (`data: {labels: [], series: [{name, data: []}]}`, one series per
     * `DomainRecordType`, one label per DNS-provider bucket). Only
     * `bars`/`hBars`/`stackedbars`/`stackedHBars`/`lines` read this shape
     * (`Glpi\Dashboard\Widget::getBarsGraph()`/`getLinesGraph()` with
     * `'multiple' => true`) — `pie`/`donut`/`bigNumber`/single-series `bar`
     * would silently misrender it (see `toChartData()`'s doc comment for the
     * single-series equivalent of this trap).
     *
     * No SQL `LIMIT`/pre-ranking here: the dashboard editor's per-card
     * "limit" control (default 7, confirmed against `Glpi\Dashboard\Grid`)
     * never reaches the provider at all — it's applied purely client-side by
     * `Widget::getBarsGraph()`, which keeps the *last* N labels/series
     * entries (`array_splice($labels, 0, -$nb_labels)`), not the largest N.
     * So the providers here are sorted ascending by total record count
     * (`asort($providerTotals)`) precisely so that client-side "keep the
     * tail" trim retains the *biggest* buckets — matches the "top N by
     * volume" behaviour the user actually wants, without duplicating core's
     * slicing logic here.
     *
     * Each present data point carries a per-segment drill-down: `Widget`'s
     * echarts click handler reads `params.data.url`, so a data value can be
     * either a bare number (no click) or `{value, url}` (clickable) — see
     * domainsByRegistrarAndTld()'s matching treatment. Unlike that card,
     * there is no search option exposing `DomainState.dns_suppliers_id`
     * under the `DomainRecord` itemtype (`PLUGIN_DOMAINMANAGER_SO_DOMAIN_DNS_SUPPLIER`
     * is only registered for `Domain`), so every segment's url can only
     * carry the type + "Managed" criteria — a superset spanning all
     * providers for that type, same accepted tradeoff as
     * `recordsByDnsProvider()`'s own drill-down.
     */
    public static function recordsByDnsProviderAndType(array $params = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $recordsTable = ImportedRecord::getTable();
        // Same "repeat the expression, don't rely on the alias" fix as
        // recordsByDnsProvider() — DomainState has a real dns_suppliers_id
        // column that would otherwise win GROUP BY resolution over this
        // COALESCE'd alias.
        $dnsSuppliersIdExpr = 'COALESCE(' . $DB->quoteName(DomainState::getTable() . '.dns_suppliers_id') . ', 0)';

        $iterator = $DB->request([
            'SELECT'    => [
                new QueryExpression($dnsSuppliersIdExpr . ' AS ' . $DB->quoteName('dns_suppliers_id')),
                new QueryExpression('MAX(' . $DB->quoteName('supplier.name') . ') AS ' . $DB->quoteName('supplier_name')),
                'domainrecordtype.id AS domainrecordtypes_id',
                new QueryExpression('MAX(' . $DB->quoteName('domainrecordtype.name') . ') AS ' . $DB->quoteName('type_name')),
                'COUNT DISTINCT' => $recordsTable . '.id AS cpt',
            ],
            // See recordsCount() for why FROM stays unaliased.
            'FROM'      => $recordsTable,
            'INNER JOIN' => [
                DomainRecord::getTable() . ' AS domainrecord' => [
                    'ON' => [$recordsTable => 'domainrecords_id', 'domainrecord' => 'id'],
                ],
                'glpi_domains' => [
                    'ON' => ['domainrecord' => 'domains_id', 'glpi_domains' => 'id'],
                ],
                DomainRecordType::getTable() . ' AS domainrecordtype' => [
                    'ON' => ['domainrecord' => 'domainrecordtypes_id', 'domainrecordtype' => 'id'],
                ],
            ],
            'LEFT JOIN' => [
                DomainState::getTable() => [
                    'ON' => [DomainState::getTable() => 'domains_id', 'glpi_domains' => 'id'],
                ],
                'glpi_suppliers AS supplier' => [
                    'ON' => ['supplier' => 'id', DomainState::getTable() => 'dns_suppliers_id'],
                ],
            ],
            'WHERE'     => array_merge(
                [
                    $recordsTable . '.is_managed' => 1,
                    'glpi_domains.is_deleted'     => 0,
                    'glpi_domains.is_template'    => 0,
                ],
                getEntitiesRestrictCriteria('glpi_domains', '', '', true),
            ),
            'GROUPBY'   => [new QueryExpression($dnsSuppliersIdExpr), 'domainrecordtype.id'],
        ]);

        $providerTotals = [];
        $providerNames  = [];
        $typeNames      = [];
        $matrix         = [];

        foreach ($iterator as $row) {
            $grp    = (int) ($row['dns_suppliers_id'] ?? 0);
            $typeId = (int) ($row['domainrecordtypes_id'] ?? 0);
            $cpt    = (int) ($row['cpt'] ?? 0);

            $providerTotals[$grp] = ($providerTotals[$grp] ?? 0) + $cpt;
            $providerNames[$grp]  = $row['supplier_name'] ?? null;
            $typeNames[$typeId]   = $row['type_name'] ?? __('Not set');
            $matrix[$grp][$typeId] = $cpt;
        }

        if (count($providerTotals) === 0) {
            return [
                'data'  => ['nodata' => true],
                'label' => __('Records per provider by type', 'domainmanager'),
                'alt'   => __('Managed records per DNS provider by type', 'domainmanager'),
                'icon'  => self::ICON,
            ];
        }

        // Ascending, deliberately — see this method's doc comment: the
        // widget's own client-side "limit" trim keeps the *tail* of the
        // labels array, so the biggest buckets must be last here.
        asort($providerTotals);

        $labelMap = ['0' => __('Not matched to a supplier', 'domainmanager')];

        $labels     = [];
        $seriesData = [];
        foreach ($providerTotals as $grp => $total) {
            if (isset($labelMap[(string) $grp])) {
                $name = $labelMap[(string) $grp];
            } else {
                $name = $providerNames[$grp] ?? null;
                if ($name === null || $name === '') {
                    $name = __('Not set');
                }
            }
            $labels[] = $name;

            foreach ($typeNames as $typeId => $typeName) {
                // null, not 0 — see domainsByRegistrarAndTld()'s matching
                // comment: an absent provider/type combo shouldn't fabricate
                // a real (tooltip/legend-visible) data point.
                $cpt = $matrix[$grp][$typeId] ?? null;
                if ($cpt === null) {
                    $seriesData[$typeId][] = null;
                    continue;
                }

                $criteria = [
                    'criteria' => [
                        [
                            'field'      => 3,
                            'searchtype' => 'equals',
                            'value'      => $typeId,
                        ],
                        [
                            'link'       => 'AND',
                            'field'      => PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_MANAGED,
                            'searchtype' => 'equals',
                            'value'      => 1,
                        ],
                    ],
                    'reset'    => 'reset',
                ];

                $seriesData[$typeId][] = [
                    'value' => $cpt,
                    'url'   => DomainRecord::getSearchURL() . '?' . Toolbox::append_params($criteria),
                ];
            }
        }

        $series = [];
        foreach ($typeNames as $typeId => $typeName) {
            $series[] = [
                'name' => $typeName,
                'data' => $seriesData[$typeId],
            ];
        }

        return [
            'data'  => [
                'labels' => $labels,
                'series' => $series,
            ],
            'label' => __('Records per provider by type', 'domainmanager'),
            'alt'   => __('Managed records per DNS provider by type', 'domainmanager'),
            'icon'  => self::ICON,
        ];
    }

    /**
     * "Proxied records" (Phase 85): managed A/AAAA/CNAME records whose
     * domain's DNS provider resolves to a Cloudflare-driven Supplier —
     * mirrors `DnsRecordWriteback::PROXIABLE_TYPES` and the Cloudflare-only
     * proxy support already established. Drill-down only carries SO 9405
     * (proxy flag) — same superset tradeoff as `domainsByRegistrar()`'s "no
     * driver" bucket, since the type/Cloudflare scope isn't otherwise
     * expressible via a single search-option criterion.
     */
    public static function proxiedRecordsBreakdown(array $params = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $recordsTable = ImportedRecord::getTable();
        // Repeated below (SELECT + GROUPBY), never referenced by its SELECT
        // alias: `$recordsTable` (glpi_plugin_domainmanager_records) has a
        // real `is_proxied` column of its own, so a bare `GROUPBY =>
        // ['is_proxied']` resolves to that raw, non-coalesced column
        // instead of the SELECT-list alias (a real column always wins over
        // a same-named alias in MySQL's GROUP BY resolution) — NULL and 0
        // rows silently split into two "Not proxied" buckets instead of
        // one. Same "repeat the expression, don't rely on the alias"
        // pattern as domainsByRegistrar()'s CASE expression.
        $isProxiedExpr = 'COALESCE(' . $DB->quoteName($recordsTable . '.is_proxied') . ', 0)';

        $iterator = $DB->request([
            'SELECT'    => [
                new QueryExpression($isProxiedExpr . ' AS ' . $DB->quoteName('is_proxied')),
                'COUNT DISTINCT' => $recordsTable . '.id AS cpt',
            ],
            // See recordsCount() for why FROM stays unaliased.
            'FROM'      => $recordsTable,
            'INNER JOIN' => [
                DomainRecord::getTable() . ' AS domainrecord' => [
                    'ON' => [$recordsTable => 'domainrecords_id', 'domainrecord' => 'id'],
                ],
                'glpi_domains' => [
                    'ON' => ['domainrecord' => 'domains_id', 'glpi_domains' => 'id'],
                ],
                DomainRecordType::getTable() . ' AS domainrecordtype' => [
                    'ON' => ['domainrecord' => 'domainrecordtypes_id', 'domainrecordtype' => 'id'],
                ],
                DomainState::getTable() => [
                    'ON' => [DomainState::getTable() => 'domains_id', 'glpi_domains' => 'id'],
                ],
                'glpi_suppliers AS supplier' => [
                    'ON' => ['supplier' => 'id', DomainState::getTable() => 'dns_suppliers_id'],
                ],
                SupplierConfig::getTable() . ' AS supplier_config' => [
                    'ON' => ['supplier_config' => 'suppliers_id', 'supplier' => 'id'],
                ],
            ],
            'WHERE'     => array_merge(
                [
                    $recordsTable . '.is_managed' => 1,
                    'domainrecordtype.name'       => ['A', 'AAAA', 'CNAME'],
                    'supplier_config.api_driver'  => DriverRegistry::DRIVER_CLOUDFLARE,
                    'glpi_domains.is_deleted'      => 0,
                    'glpi_domains.is_template'     => 0,
                ],
                getEntitiesRestrictCriteria('glpi_domains', '', '', true),
            ),
            'GROUPBY'   => [new QueryExpression($isProxiedExpr)],
            'ORDER'     => 'cpt DESC',
        ]);

        return self::toChartData(
            $iterator,
            'is_proxied',
            'is_proxied',
            'cpt',
            PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_PROXY,
            ['1' => __('Proxied'), '0' => __('Not proxied')],
            static function (array $row) {
                $criteria = [
                    'criteria' => [[
                        'field'      => PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_PROXY,
                        'searchtype' => 'equals',
                        'value'      => (int) ($row['is_proxied'] ?? 0),
                    ],
                    ],
                    'reset'    => 'reset',
                ];

                return DomainRecord::getSearchURL() . '?' . Toolbox::append_params($criteria);
            },
            __('Proxied records', 'domainmanager'),
            __('Proxied records', 'domainmanager'),
        );
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
     * @param array<int|string, string>|null $labelMap   raw grouped value =>
     *     human label (e.g. `DomainStatusResolver::getStatusLabels()`);
     *     falls back to the raw value itself when a value has no entry.
     *     Keyed `int|string`, not just `string`: a numeric-looking string
     *     key like `'0'` is silently coerced to an int array key by PHP
     *     itself (canonical decimal integer string rule), so a literal
     *     `['0' => ...]` is `array<int, string>` at runtime regardless of
     *     how it's written.
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
                    ],
                    ],
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
     * Uses countElementsInTable() rather than Dashboard::getFromDBByCrit()
     * for the existence check — Dashboard overrides getIndexName()/
     * getFromDB() to key off `key` instead of `id`, which made the guard
     * unreliable at runtime and let a new dashboard get created on every
     * update.
     */
    public static function install(Migration $migration): void
    {
        if (countElementsInTable(Dashboard::getTable(), ['key' => self::DASHBOARD_KEY]) > 0) {
            return;
        }

        $dashboard     = new Dashboard();
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
                'gridstack_id' => 'bn_count_Domain_' . Uuid::uuid4(),
                'card_id'      => 'bn_count_Domain',
                'x'            => 0,
                'y'            => 0,
                'width'        => 3,
                'height'       => 2,
                'card_options' => [
                    'color'        => '#0b5394',
                    'widgettype'   => 'bigNumber',
                    'palette'      => 'tab10',
                    'use_gradient' => '0',
                    'point_labels' => '1',
                    'legend'       => '1',
                    'limit'        => '7',
                ],
            ],
            [
                'gridstack_id' => 'plugin_domainmanager_domains_count_' . Uuid::uuid4(),
                'card_id'      => 'plugin_domainmanager_domains_count',
                'x'            => 3,
                'y'            => 0,
                'width'        => 3,
                'height'       => 2,
                'card_options' => [
                    'color'        => '#cfe2f3',
                    'widgettype'   => 'bigNumber',
                    'palette'      => 'tab10',
                    'use_gradient' => '0',
                    'point_labels' => '1',
                    'legend'       => '1',
                    'limit'        => '7',
                ],
            ],
            [
                'gridstack_id' => 'plugin_domainmanager_domains_by_registrar_' . Uuid::uuid4(),
                'card_id'      => 'plugin_domainmanager_domains_by_registrar',
                'x'            => 6,
                'y'            => 0,
                'width'        => 10,
                'height'       => 4,
                'card_options' => [
                    'color'        => '#cfe2f3',
                    'widgettype'   => 'hbar',
                    'palette'      => '',
                    'use_gradient' => '1',
                    'point_labels' => '1',
                    'legend'       => '0',
                    'limit'        => '7',
                ],
            ],
            [
                'gridstack_id' => 'plugin_domainmanager_domains_by_dns_provider_' . Uuid::uuid4(),
                'card_id'      => 'plugin_domainmanager_domains_by_dns_provider',
                'x'            => 16,
                'y'            => 0,
                'width'        => 10,
                'height'       => 4,
                'card_options' => [
                    'color'        => '#cfe2f3',
                    'widgettype'   => 'hbar',
                    'palette'      => '',
                    'use_gradient' => '1',
                    'point_labels' => '1',
                    'legend'       => '0',
                    'limit'        => '7',
                ],
            ],
            [
                'gridstack_id' => 'plugin_domainmanager_expiring_soon_' . Uuid::uuid4(),
                'card_id'      => 'plugin_domainmanager_expiring_soon',
                'x'            => 0,
                'y'            => 2,
                'width'        => 3,
                'height'       => 2,
                'card_options' => [
                    'widgettype'   => 'bigNumber',
                    'color'        => '#f44336',
                    'palette'      => 'tab10',
                    'use_gradient' => '0',
                    'point_labels' => '1',
                    'legend'       => '1',
                    'limit'        => '7',
                ],
            ],
            [
                'gridstack_id' => 'plugin_domainmanager_records_count_' . Uuid::uuid4(),
                'card_id'      => 'plugin_domainmanager_records_count',
                'x'            => 3,
                'y'            => 2,
                'width'        => 3,
                'height'       => 2,
                'card_options' => [
                    'color'        => '#d0e0e3',
                    'widgettype'   => 'bigNumber',
                    'palette'      => 'tab10',
                    'use_gradient' => '0',
                    'point_labels' => '1',
                    'legend'       => '1',
                    'limit'        => '7',
                ],
            ],
            [
                'gridstack_id' => 'plugin_domainmanager_domains_by_tld_' . Uuid::uuid4(),
                'card_id'      => 'plugin_domainmanager_domains_by_tld',
                'x'            => 0,
                'y'            => 4,
                'width'        => 5,
                'height'       => 8,
                'card_options' => [
                    'color'        => '#cfe2f3',
                    'widgettype'   => 'hbar',
                    'palette'      => '',
                    'use_gradient' => '1',
                    'point_labels' => '1',
                    'legend'       => '0',
                    'limit'        => '7',
                ],
            ],
            [
                'gridstack_id' => 'plugin_domainmanager_domains_by_registrar_and_tld_' . Uuid::uuid4(),
                'card_id'      => 'plugin_domainmanager_domains_by_registrar_and_tld',
                'x'            => 5,
                'y'            => 4,
                'width'        => 16,
                'height'       => 4,
                'card_options' => [
                    'color'        => '#cfe2f3',
                    'widgettype'   => 'stackedHBars',
                    'palette'      => '',
                    'use_gradient' => '1',
                    'point_labels' => '1',
                    'legend'       => '0',
                    'limit'        => '7',
                ],
            ],
            [
                'gridstack_id' => 'plugin_domainmanager_registrar_status_' . Uuid::uuid4(),
                'card_id'      => 'plugin_domainmanager_registrar_status',
                'x'            => 21,
                'y'            => 4,
                'width'        => 5,
                'height'       => 4,
                'card_options' => [
                    'color'        => '#ffe599',
                    'widgettype'   => 'donut',
                    'palette'      => 'tab10',
                    'use_gradient' => '0',
                    'point_labels' => '1',
                    'legend'       => '0',
                    'limit'        => '7',
                ],
            ],
            [
                'gridstack_id' => 'plugin_domainmanager_records_by_type_' . Uuid::uuid4(),
                'card_id'      => 'plugin_domainmanager_records_by_type',
                'x'            => 5,
                'y'            => 8,
                'width'        => 5,
                'height'       => 4,
                'card_options' => [
                    'color'        => '#d0e0e3',
                    'widgettype'   => 'donut',
                    'palette'      => '',
                    'use_gradient' => '1',
                    'point_labels' => '1',
                    'legend'       => '0',
                    'limit'        => '10',
                ],
            ],
            [
                'gridstack_id' => 'plugin_domainmanager_records_by_dns_provider_' . Uuid::uuid4(),
                'card_id'      => 'plugin_domainmanager_records_by_dns_provider',
                'x'            => 10,
                'y'            => 8,
                'width'        => 5,
                'height'       => 4,
                'card_options' => [
                    'color'        => '#d0e0e3',
                    'widgettype'   => 'donut',
                    'palette'      => '',
                    'use_gradient' => '1',
                    'point_labels' => '1',
                    'legend'       => '0',
                    'limit'        => '7',
                ],
            ],
            [
                'gridstack_id' => 'plugin_domainmanager_proxied_records_' . Uuid::uuid4(),
                'card_id'      => 'plugin_domainmanager_proxied_records',
                'x'            => 15,
                'y'            => 8,
                'width'        => 6,
                'height'       => 4,
                'card_options' => [
                    'color'        => '#d0e0e3',
                    'widgettype'   => 'donut',
                    'palette'      => '',
                    'use_gradient' => '1',
                    'point_labels' => '1',
                    'legend'       => '0',
                    'limit'        => '7',
                ],
            ],
            [
                'gridstack_id' => 'plugin_domainmanager_sync_status_' . Uuid::uuid4(),
                'card_id'      => 'plugin_domainmanager_sync_status',
                'x'            => 21,
                'y'            => 8,
                'width'        => 5,
                'height'       => 4,
                'card_options' => [
                    'color'        => '#ffe599',
                    'widgettype'   => 'donut',
                    'palette'      => 'tab10',
                    'use_gradient' => '0',
                    'point_labels' => '1',
                    'legend'       => '0',
                    'limit'        => '7',
                ],
            ],
            [
                'gridstack_id' => 'plugin_domainmanager_records_by_dns_provider_and_type_' . Uuid::uuid4(),
                'card_id'      => 'plugin_domainmanager_records_by_dns_provider_and_type',
                'x'            => 0,
                'y'            => 12,
                'width'        => 26,
                'height'       => 4,
                'card_options' => [
                    'color'        => '#d0e0e3',
                    'widgettype'   => 'stackedHBars',
                    'palette'      => '',
                    'use_gradient' => '1',
                    'point_labels' => '1',
                    'legend'       => '1',
                    'limit'        => '7',
                ],
            ],
        ];

        foreach ($cards as $options) {
            $item = new Item();
            $item->addForDashboard($dashboards_id, [$options]);
        }
    }

    public static function uninstall(Migration $migration): void
    {
        if (countElementsInTable(Dashboard::getTable(), ['key' => self::DASHBOARD_KEY]) === 0) {
            return;
        }

        $dashboard = new Dashboard();
        if ($dashboard->getFromDBByCrit(['key' => self::DASHBOARD_KEY]) !== false) {
            $dashboard->deleteFromDB(true);
        }
    }
}
