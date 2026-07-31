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
 * Plugin-owned field locks written after each successful sync, enforced by
 * LockEnforcer against users lacking the unlock right
 * (glpi_plugin_domainmanager_locks, unicity on itemtype/items_id/field; §0.3)
 */
class ImportLock extends CommonDBTM
{
    public static $rightname = 'domain';

    /**
     * {@inheritDoc}
     */
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_domainmanager_locks';
    }

    /**
     * {@inheritDoc}
     */
    public static function getTypeName($nb = 0)
    {
        return _n('Imported field lock', 'Imported field locks', $nb, 'domainmanager');
    }

    /**
     * {@inheritDoc}
     */
    public static function getIcon()
    {
        return 'ti ti-world-cog';
    }

    /**
     * Locked fields of an item
     * (named to avoid clashing with non-static CommonDBTM::getLockedFields())
     *
     * @param  string $itemtype
     * @param  int    $items_id
     * @return string[] field names
     */
    public static function getLockedFieldNames(string $itemtype, int $items_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $fields = [];
        $iterator = $DB->request([
            'SELECT' => 'field',
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
            ],
        ]);
        foreach ($iterator as $row) {
            $fields[] = $row['field'];
        }

        return $fields;
    }

    /**
     * Replace the lock set of an item with the fields written by the last sync
     *
     * @param  string                $itemtype
     * @param  int                   $items_id
     * @param  array<string, mixed>  $fields field => last synced value
     * @return void
     */
    public static function replaceLocks(string $itemtype, int $items_id, array $fields): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete(self::getTable(), [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
        ]);

        $lock = new self();
        foreach ($fields as $field => $value) {
            $lock->add([
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'field'    => $field,
                'value'    => mb_substr((string) $value, 0, 255),
            ]);
        }
    }

    /**
     * Add or refresh a single field's lock without touching any other lock
     * already held on the same item — unlike `replaceLocks()`, which wipes
     * and rewrites the item's *entire* lock set and is only safe when one
     * caller owns that whole set (registrar-leg sync's name/dates). Used for
     * `domaintypes_id` (§9), which is written independently by import,
     * not by either sync leg.
     *
     * @param  string $itemtype
     * @param  int    $items_id
     * @param  string $field
     * @param  mixed  $value
     * @return void
     */
    public static function setLock(string $itemtype, int $items_id, string $field, $value): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete(self::getTable(), [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'field'    => $field,
        ]);

        (new self())->add([
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'field'    => $field,
            'value'    => mb_substr((string) $value, 0, 255),
        ]);
    }

    /**
     * Drop a single field's lock, counterpart to `setLock()`
     *
     * @param  string $itemtype
     * @param  int    $items_id
     * @param  string $field
     * @return void
     */
    public static function clearLock(string $itemtype, int $items_id, string $field): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete(self::getTable(), [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'field'    => $field,
        ]);
    }

    /**
     * Drop every lock of an item (purge cascade)
     *
     * @param  string $itemtype
     * @param  int    $items_id
     * @return void
     */
    public static function deleteForItem(string $itemtype, int $items_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete(self::getTable(), [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
        ]);
    }
}
