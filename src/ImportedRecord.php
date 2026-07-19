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
 * Ownership map of DNS records imported by the plugin, keyed by remote id or
 * content hash for idempotent reconciliation
 * (glpi_plugin_domainmanager_records, one row per imported glpi_domainrecords row)
 */
class ImportedRecord extends CommonDBTM
{
    public static $rightname = 'domain';

    /**
     * {@inheritDoc}
     */
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_domainmanager_records';
    }

    /**
     * {@inheritDoc}
     */
    public static function getTypeName($nb = 0)
    {
        return _n('Imported DNS record', 'Imported DNS records', $nb, 'domainmanager');
    }

    /**
     * {@inheritDoc}
     */
    public static function getIcon()
    {
        return 'ti ti-world-cog';
    }

    /**
     * Get the ownership row of a native domain record
     *
     * @param  int $domainrecords_id
     * @return self|null
     */
    public static function getForDomainRecord(int $domainrecords_id): ?self
    {
        $record = new self();
        if ($domainrecords_id > 0 && $record->getFromDBByCrit(['domainrecords_id' => $domainrecords_id])) {
            return $record;
        }

        return null;
    }

    /**
     * Whether a native domain record is owned (imported) by the plugin
     *
     * @param  int $domainrecords_id
     * @return bool
     */
    public static function isPluginOwned(int $domainrecords_id): bool
    {
        return self::getForDomainRecord($domainrecords_id) !== null;
    }
}
