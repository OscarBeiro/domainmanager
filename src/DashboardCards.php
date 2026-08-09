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
            'label'      => __s('Domains per registrar', 'domainmanager'),
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
     * suppliers_id (search option id 53) — registrar assignment already
     * reuses that native field, no plugin-owned search option needed.
     */
    public static function domainsByRegistrar(array $params = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'SELECT'    => [
                'infocom.suppliers_id AS suppliers_id',
                'supplier.name AS supplier_name',
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
            ],
            'WHERE'     => array_merge(
                [
                    'glpi_domains.is_deleted'  => 0,
                    'glpi_domains.is_template' => 0,
                ],
                getEntitiesRestrictCriteria('glpi_domains', '', '', true),
            ),
            'GROUPBY'   => ['infocom.suppliers_id'],
            'ORDER'     => 'cpt DESC',
        ]);

        return self::toChartData($iterator, 'suppliers_id', 'supplier_name', 'cpt', 53);
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
                DomainState::getTable() . '.dns_status AS dns_status',
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
            'GROUPBY'   => [DomainState::getTable() . '.dns_status'],
            'ORDER'     => 'cpt DESC',
        ]);

        return self::toChartData($iterator, 'dns_status', 'dns_status', 'cpt', PLUGIN_DOMAINMANAGER_SO_DOMAIN_DNS_STATUS);
    }

    /**
     * "Registrar status" card (Phase 81): groups Domain by the existing
     * PLUGIN_DOMAINMANAGER_SO_DOMAIN_REGISTRAR_STATUS search option
     * (9408) — already registered and searchable, mirrors the "Sync
     * status" card's shape exactly.
     */
    public static function registrarStatusBreakdown(array $params = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'SELECT'    => [
                DomainState::getTable() . '.registrar_status AS registrar_status',
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
            'GROUPBY'   => [DomainState::getTable() . '.registrar_status'],
            'ORDER'     => 'cpt DESC',
        ]);

        return self::toChartData($iterator, 'registrar_status', 'registrar_status', 'cpt', PLUGIN_DOMAINMANAGER_SO_DOMAIN_REGISTRAR_STATUS);
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
            'label'  => __('Domains expiring <30 days', 'domainmanager'),
            'alt'    => __('Domains expiring <30 days', 'domainmanager'),
            'icon'   => Domain::getIcon(),
        ];
    }

    /**
     * Shared chart-shape builder: one series, drill-down `url` per point
     * built from the given search-option id + raw grouped value.
     */
    private static function toChartData(
        iterable $iterator,
        string $value_field,
        string $label_field,
        string $count_field,
        int $searchoption_id
    ): array {
        $labels = [];
        $data = [];

        foreach ($iterator as $row) {
            $value = $row[$value_field] ?? null;
            $label = $row[$label_field] ?? null;
            $label = ($label === null || $label === '') ? __s('Not set') : (string) $label;

            $criteria = [
                'criteria' => [[
                    'field'      => $searchoption_id,
                    'searchtype' => 'equals',
                    'value'      => $value ?? 0,
                ]],
                'reset'    => 'reset',
            ];
            $url = Domain::getSearchURL() . '?' . Toolbox::append_params($criteria);

            $labels[] = $label;
            $data[] = ['value' => (int) ($row[$count_field] ?? 0), 'url' => $url];
        }

        return [
            'data' => [
                'labels' => $labels,
                'series' => [[
                    'name' => '',
                    'data' => $data,
                ]],
            ],
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
