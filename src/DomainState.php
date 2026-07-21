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

/**
 * Per-domain sync state: registrar supplier, resolved DNS provider and
 * last pipeline outcomes (glpi_plugin_domainmanager_states, one row per domain)
 */
class DomainState extends CommonDBTM
{
    public static $rightname = 'domain';

    public const STATUS_NEVER        = 'never';
    public const STATUS_OK           = 'ok';
    public const STATUS_ERROR        = 'error';
    public const STATUS_UNCONFIGURED = 'unconfigured';
    public const STATUS_UNSUPPORTED  = 'unsupported';
    public const STATUS_UNKNOWN      = 'unknown';

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
     * @param  int $suppliers_id
     * @return array<int, array{domains_id:int, name:string, entities_id:int,
     *               registrar_suppliers_id:int, dns_suppliers_id:int,
     *               detected_provider:string, registrar_status:string,
     *               dns_status:string}>
     */
    public static function getDomainsForSupplier(int $suppliers_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($suppliers_id <= 0) {
            return [];
        }

        $iterator = $DB->request([
            'SELECT'     => [
                'glpi_domains.id AS domains_id',
                'glpi_domains.name AS name',
                'glpi_domains.entities_id AS entities_id',
                self::getTable() . '.registrar_suppliers_id AS registrar_suppliers_id',
                self::getTable() . '.dns_suppliers_id AS dns_suppliers_id',
                self::getTable() . '.detected_provider AS detected_provider',
                self::getTable() . '.registrar_status AS registrar_status',
                self::getTable() . '.dns_status AS dns_status',
            ],
            'FROM'       => 'glpi_domains',
            'INNER JOIN' => [
                self::getTable() => [
                    'ON' => [
                        self::getTable() => 'domains_id',
                        'glpi_domains'   => 'id',
                    ],
                ],
            ],
            'WHERE'      => array_merge(
                [
                    'glpi_domains.is_deleted'  => 0,
                    'glpi_domains.is_template' => 0,
                    'OR'                       => [
                        self::getTable() . '.registrar_suppliers_id' => $suppliers_id,
                        self::getTable() . '.dns_suppliers_id'       => $suppliers_id,
                    ],
                ],
                getEntitiesRestrictCriteria('glpi_domains', '', '', true)
            ),
            'ORDER'      => 'glpi_domains.name ASC',
        ]);

        $rows = [];
        foreach ($iterator as $row) {
            $rows[] = [
                'domains_id'             => (int) $row['domains_id'],
                'name'                   => (string) $row['name'],
                'entities_id'            => (int) $row['entities_id'],
                'registrar_suppliers_id' => (int) $row['registrar_suppliers_id'],
                'dns_suppliers_id'       => (int) $row['dns_suppliers_id'],
                'detected_provider'      => (string) $row['detected_provider'],
                'registrar_status'       => (string) $row['registrar_status'],
                'dns_status'             => (string) $row['dns_status'],
            ];
        }

        return $rows;
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
