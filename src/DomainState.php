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
