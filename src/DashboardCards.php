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
use Migration;
use Ramsey\Uuid\Uuid;
use Toolbox;

/**
 * Phase 80: GLPI dashboard cards for Domain Manager. Proves the
 * registration/drill-down/install mechanism with two cards; phases 81-83
 * (ARCHITECTURE.md §20) add more cards on top once this is validated.
 */
class DashboardCards
{
    public const DASHBOARD_KEY = 'plugin_domainmanager_dashboard';

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
