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
 * Plugin's ImportedRecord class (extends CommonDBTM)
 */
class ImportedRecord extends CommonDBTM
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_domainmanager_records';
    }

    public static function getForDomainRecord(int $domainrecords_id): ?self
    {
        $record = new self();
        if ($record->getFromDBByCrit(['domainrecords_id' => $domainrecords_id])) {
            return $record;
        }
        return null;
    }

    public static function isPluginOwned(int $domainrecords_id): bool
    {
        return self::getForDomainRecord($domainrecords_id) !== null;
    }
}

/**
 * LockEnforcer stub
 */
class LockEnforcer
{
    public static bool $sync_in_progress = false;
}

/**
 * ImportLock stub (no-op recorder)
 */
class ImportLock
{
    private static array $replacement_calls = [];

    public static function replaceLocks(string $itemtype, int $items_id, array $fields): void
    {
        // Record the call for inspection in tests if needed
        self::$replacement_calls[] = [
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'fields' => $fields,
        ];
    }

    public static function getReplacementCalls(): array
    {
        return self::$replacement_calls;
    }

    public static function clearReplacementCalls(): void
    {
        self::$replacement_calls = [];
    }

    public static function getLockedFieldNames(string $itemtype, int $items_id): array
    {
        return []; // No-op for tests
    }

    public static function setLock(string $itemtype, int $items_id, string $field, $value): void
    {
        // No-op
    }

    public static function clearLock(string $itemtype, int $items_id, string $field): void
    {
        // No-op
    }

    public static function deleteForItem(string $itemtype, int $items_id): void
    {
        // No-op
    }
}

/**
 * Config stub - returns configurable test values
 */
class Config
{
    private static int $max_count = 20;
    private static int $max_percent = 50;

    public static function getSyncSafetyMaxCount(): int
    {
        return self::$max_count;
    }

    public static function setSyncSafetyMaxCount(int $value): void
    {
        self::$max_count = $value;
    }

    public static function getSyncSafetyMaxPercent(): int
    {
        return self::$max_percent;
    }

    public static function setSyncSafetyMaxPercent(int $value): void
    {
        self::$max_percent = $value;
    }

    public static function resetDefaults(): void
    {
        self::$max_count = 20;
        self::$max_percent = 50;
    }
}

namespace GlpiPlugin\Domainmanager\Config;

/**
 * Config alias in the Config namespace (matches actual code path)
 */
class Config extends \GlpiPlugin\Domainmanager\Config
{
}
