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

// Minimal GLPI stand-ins for pure unit tests. Only what the tested units
// actually touch — anything else failing loudly is a feature (it means a
// test started depending on GLPI behaviour that isn't modelled here).

define('PLUGIN_DOMAINMANAGER_VERSION', 'test');

function __(string $str, string $domain = 'glpi'): string
{
    return $str;
}

/**
 * Functional in-memory fake for CommonDBTM, backing a shared static store
 * keyed by class name. Each class extending this gets its own table-like
 * array of records, keyed by id. Supports add(), update(), delete(),
 * restore(), getFromDB(), getFromDBByCrit(), getID().
 */
class CommonDBTM
{
    /** @var array<string, mixed> */
    public $fields = [];

    /** @var int|null */
    private ?int $current_id = null;

    /** @var array<string, array<int, array<string, mixed>>> */
    private static array $store = [];

    /**
     * Initialize store for a class
     */
    protected static function initStore(string $class_name): void
    {
        if (!isset(self::$store[$class_name])) {
            self::$store[$class_name] = [];
        }
    }

    /**
     * Get the table name for this class (override in subclasses)
     */
    public static function getTable($classname = null)
    {
        return 'unknown_table';
    }

    /**
     * Add a record to the in-memory store
     */
    public function add(array $input): int|false
    {
        $class_name = static::class;
        self::initStore($class_name);

        // Auto-increment ID
        $max_id = 0;
        foreach (self::$store[$class_name] as $id => $_) {
            if ($id > $max_id) {
                $max_id = $id;
            }
        }
        $new_id = $max_id + 1;

        // Store the record
        $input['id'] = $new_id;
        // Initialize is_deleted to 0 if not set (for soft-delete support)
        if (!isset($input['is_deleted'])) {
            $input['is_deleted'] = 0;
        }
        self::$store[$class_name][$new_id] = $input;
        $this->fields = $input;
        $this->current_id = $new_id;

        return $new_id;
    }

    /**
     * Update a record (by id)
     */
    public function update(array $input): bool
    {
        if (!isset($input['id'])) {
            return false;
        }

        $class_name = static::class;
        self::initStore($class_name);

        $id = (int) $input['id'];
        if (!isset(self::$store[$class_name][$id])) {
            return false;
        }

        // Merge the input into the existing record
        foreach ($input as $key => $value) {
            self::$store[$class_name][$id][$key] = $value;
        }

        $this->fields = self::$store[$class_name][$id];
        $this->current_id = $id;

        return true;
    }

    /**
     * Soft-delete a record (sets is_deleted=1)
     */
    public function delete(array $input, $force = false): bool
    {
        if (!isset($input['id'])) {
            return false;
        }

        $class_name = static::class;
        self::initStore($class_name);

        $id = (int) $input['id'];
        if (!isset(self::$store[$class_name][$id])) {
            return false;
        }

        // Soft delete: set is_deleted
        self::$store[$class_name][$id]['is_deleted'] = 1;
        $this->fields = self::$store[$class_name][$id];
        $this->current_id = $id;

        return true;
    }

    /**
     * Restore a soft-deleted record (sets is_deleted=0)
     */
    public function restore(array $input): bool
    {
        if (!isset($input['id'])) {
            return false;
        }

        $class_name = static::class;
        self::initStore($class_name);

        $id = (int) $input['id'];
        if (!isset(self::$store[$class_name][$id])) {
            return false;
        }

        self::$store[$class_name][$id]['is_deleted'] = 0;
        $this->fields = self::$store[$class_name][$id];
        $this->current_id = $id;

        return true;
    }

    /**
     * Load a record by ID
     */
    public function getFromDB($id): bool
    {
        $class_name = static::class;
        self::initStore($class_name);

        $id = (int) $id;
        if (!isset(self::$store[$class_name][$id])) {
            return false;
        }

        $this->fields = self::$store[$class_name][$id];
        $this->current_id = $id;

        return true;
    }

    /**
     * Load a record by criteria (first match)
     */
    public function getFromDBByCrit(array $crit): bool
    {
        $class_name = static::class;
        self::initStore($class_name);

        foreach (self::$store[$class_name] as $id => $record) {
            $match = true;
            foreach ($crit as $field => $value) {
                if (!isset($record[$field]) || $record[$field] != $value) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $this->fields = $record;
                $this->current_id = $id;
                return true;
            }
        }

        return false;
    }

    /**
     * Get the ID of the current loaded record
     */
    public function getID(): int|false
    {
        return $this->current_id ?? false;
    }

    /**
     * Clear the store (for testing)
     */
    public static function clearStore(): void
    {
        self::$store = [];
    }

    /**
     * Get records from store for a class (for testing)
     */
    protected static function getStoreRecords(string $class_name): array
    {
        self::initStore($class_name);
        return self::$store[$class_name];
    }

    /**
     * Query the store (for DB emulation)
     */
    public static function queryStore(string $class_name, array $where = []): array
    {
        self::initStore($class_name);
        $results = [];

        foreach (self::$store[$class_name] as $record) {
            $match = true;
            foreach ($where as $field => $value) {
                if (!isset($record[$field]) || $record[$field] != $value) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $results[] = $record;
            }
        }

        return $results;
    }
}

/**
 * GLPI core Domain class (stub)
 */
class Domain extends CommonDBTM
{
    public static function getTable($classname = null)
    {
        return 'glpi_domains';
    }
}

/**
 * GLPI core DomainRecord class (stub)
 */
class DomainRecord extends CommonDBTM
{
    public static function getTable($classname = null)
    {
        return 'glpi_domainrecords';
    }

    /**
     * Static lookup by ID (used by RecordReconciler)
     */
    public static function getById(int $id): self|false
    {
        $record = new self();
        if ($record->getFromDB($id)) {
            return $record;
        }
        return false;
    }
}

/**
 * GLPI core DomainRecordType class (stub)
 */
class DomainRecordType extends CommonDBTM
{
    public static function getTable($classname = null)
    {
        return 'glpi_domainrecordtypes';
    }
}

/**
 * Fake DB class with request() support
 */
class FakeDB
{
    /**
     * Request support: returns an iterable of arrays for WHERE queries
     */
    public function request(array $options): array
    {
        $from = $options['FROM'] ?? null;
        $where = $options['WHERE'] ?? [];

        if (!$from) {
            return [];
        }

        // Map table names to classes
        $class_map = [
            'glpi_plugin_domainmanager_records' => 'GlpiPlugin\Domainmanager\ImportedRecord',
            'glpi_domainrecords' => DomainRecord::class,
            'glpi_domains' => Domain::class,
            'glpi_domainrecordtypes' => DomainRecordType::class,
        ];

        $class_name = $class_map[$from] ?? null;
        if (!$class_name) {
            return [];
        }

        return CommonDBTM::queryStore($class_name, $where);
    }

    /**
     * Delete support (used by ImportLock)
     */
    public function delete(string $table, array $where): bool
    {
        return true; // No-op for tests
    }
}

/**
 * Session stub
 */
class Session
{
    private static bool $is_cron = false;

    public static function isCron(): bool
    {
        return self::$is_cron;
    }

    public static function setIsCron(bool $value): void
    {
        self::$is_cron = $value;
    }

    public static function haveRight(string $right, string $level): bool
    {
        return false;
    }

    public static function addMessageAfterRedirect(string $message, bool $status = false, $type = 0): void
    {
        // No-op
    }
}
