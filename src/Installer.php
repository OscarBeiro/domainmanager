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

use CronTask;
use DBConnection;
use DomainRecordType;
use DomainType;
use Migration;
use ProfileRight;

class Installer
{
    public const DOMAIN_TYPE_NAME = 'Internet Domain';

    public const RECORD_TYPE_NAMES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT'];

    public const TABLES = [
        'glpi_plugin_domainmanager_supplierconfigs',
        'glpi_plugin_domainmanager_states',
        'glpi_plugin_domainmanager_records',
        'glpi_plugin_domainmanager_locks',
    ];

    /**
     * Install or upgrade the plugin (idempotent)
     *
     * @param  Migration $migration
     * @return bool
     */
    public static function install(Migration $migration): bool
    {
        self::createTables($migration);
        self::addConnectionTestColumns($migration);
        self::migrateRecordManagedColumn($migration);
        self::seedDomainType();
        self::seedRecordTypes();
        self::registerRights($migration);
        self::registerCronTasks();

        $migration->executeMigration();

        return true;
    }

    /**
     * Uninstall the plugin, leaving no residue (native inventory data is kept)
     *
     * @param  Migration $migration
     * @return bool
     */
    public static function uninstall(Migration $migration): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (self::TABLES as $table) {
            $migration->displayMessage("Dropping $table");
            $migration->dropTable($table);
        }

        CronTask::unregister('domainmanager');

        ProfileRight::deleteProfileRights([Profile::UNLOCK_RIGHT]);

        // Defensive: no plugin itemtype registers display preferences by default
        $DB->delete(
            'glpi_displaypreferences',
            ['itemtype' => ['LIKE', 'GlpiPlugin\\\\Domainmanager\\\\%']]
        );

        $migration->executeMigration();

        return true;
    }

    /**
     * Create the plugin tables when missing
     *
     * Migration has no CREATE TABLE builder, so initial creation uses
     * $DB->doQuery() with the GLPI default charset/collation/key-sign options.
     *
     * @param  Migration $migration
     * @return void
     */
    private static function createTables(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        $schemas = [
            'glpi_plugin_domainmanager_supplierconfigs' => <<<SQL
                CREATE TABLE `glpi_plugin_domainmanager_supplierconfigs` (
                    `id` int {$key_sign} NOT NULL AUTO_INCREMENT,
                    `suppliers_id` int {$key_sign} NOT NULL DEFAULT '0',
                    `api_driver` varchar(50) NOT NULL DEFAULT 'none',
                    `api_credentials` text,
                    `date_mod` timestamp NULL DEFAULT NULL,
                    `date_creation` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `suppliers_id` (`suppliers_id`),
                    KEY `api_driver` (`api_driver`),
                    KEY `date_mod` (`date_mod`),
                    KEY `date_creation` (`date_creation`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC
                SQL,
            'glpi_plugin_domainmanager_states' => <<<SQL
                CREATE TABLE `glpi_plugin_domainmanager_states` (
                    `id` int {$key_sign} NOT NULL AUTO_INCREMENT,
                    `domains_id` int {$key_sign} NOT NULL DEFAULT '0',
                    `registrar_suppliers_id` int {$key_sign} NOT NULL DEFAULT '0',
                    `dns_suppliers_id` int {$key_sign} NOT NULL DEFAULT '0',
                    `detected_provider` varchar(255) NOT NULL DEFAULT '',
                    `last_sync_date` timestamp NULL DEFAULT NULL,
                    `registrar_status` varchar(50) NOT NULL DEFAULT 'never',
                    `registrar_message` text,
                    `dns_status` varchar(50) NOT NULL DEFAULT 'never',
                    `dns_message` text,
                    `date_mod` timestamp NULL DEFAULT NULL,
                    `date_creation` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `domains_id` (`domains_id`),
                    KEY `registrar_suppliers_id` (`registrar_suppliers_id`),
                    KEY `dns_suppliers_id` (`dns_suppliers_id`),
                    KEY `last_sync_date` (`last_sync_date`),
                    KEY `date_mod` (`date_mod`),
                    KEY `date_creation` (`date_creation`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC
                SQL,
            'glpi_plugin_domainmanager_records' => <<<SQL
                CREATE TABLE `glpi_plugin_domainmanager_records` (
                    `id` int {$key_sign} NOT NULL AUTO_INCREMENT,
                    `domainrecords_id` int {$key_sign} NOT NULL DEFAULT '0',
                    `domains_id` int {$key_sign} NOT NULL DEFAULT '0',
                    `remote_id` varchar(255) NOT NULL DEFAULT '',
                    `record_hash` varchar(64) NOT NULL DEFAULT '',
                    `last_seen` timestamp NULL DEFAULT NULL,
                    `is_managed` tinyint NOT NULL DEFAULT '0',
                    `date_mod` timestamp NULL DEFAULT NULL,
                    `date_creation` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `domainrecords_id` (`domainrecords_id`),
                    KEY `domains_id` (`domains_id`),
                    KEY `remote_id` (`remote_id`),
                    KEY `record_hash` (`record_hash`),
                    KEY `is_managed` (`is_managed`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC
                SQL,
            'glpi_plugin_domainmanager_locks' => <<<SQL
                CREATE TABLE `glpi_plugin_domainmanager_locks` (
                    `id` int {$key_sign} NOT NULL AUTO_INCREMENT,
                    `itemtype` varchar(100) NOT NULL,
                    `items_id` int {$key_sign} NOT NULL DEFAULT '0',
                    `field` varchar(50) NOT NULL,
                    `value` varchar(255) DEFAULT NULL,
                    `date_mod` timestamp NULL DEFAULT NULL,
                    `date_creation` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unicity` (`itemtype`, `items_id`, `field`),
                    KEY `items_id` (`items_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC
                SQL,
        ];

        foreach ($schemas as $table => $create) {
            if (!$DB->tableExists($table)) {
                $migration->displayMessage("Creating $table");
                $DB->doQuery($create);
            }
        }
    }

    /**
     * Add the per-capability connection-test result columns to
     * supplierconfigs (§3.5), idempotent via Migration::addField()
     * (not the raw-CREATE-TABLE path used for initial creation, §0.6 does
     * not apply to post-creation schema changes)
     *
     * @param  Migration $migration
     * @return void
     */
    private static function addConnectionTestColumns(Migration $migration): void
    {
        $table = 'glpi_plugin_domainmanager_supplierconfigs';

        foreach (['registrar', 'dns'] as $prefix) {
            $migration->addField($table, "{$prefix}_test_status", 'string', ['value' => null]);
            $migration->addField($table, "{$prefix}_test_message", 'text', ['value' => null]);
            $migration->addField($table, "{$prefix}_test_http_code", 'INT NULL DEFAULT NULL');
            $migration->addField($table, "{$prefix}_test_date", 'datetime', ['value' => null]);
        }
    }

    /**
     * Replaces `is_stale` (a plugin-invented "flagged removed" marker,
     * superseded by native trash-bin soft-delete on DomainRecord itself —
     * see RecordReconciler) with `is_managed`, the field backing the new
     * "Managed" search option on DomainRecord (§addendum "Searchable
     * 'Managed' Field on Domain Records"). Idempotent via
     * `Migration::addField()`/`dropField()` (not the raw-CREATE-TABLE path
     * used for initial creation, §0.6 does not apply to post-creation
     * schema changes).
     *
     * Every pre-existing row in this table already represents a record the
     * plugin has imported/tracked — `value => 1` sets the new column's
     * `DEFAULT '1'`, which MySQL's `ADD COLUMN ... NOT NULL` also uses to
     * backfill every existing row (no separate `update` UPDATE needed).
     *
     * @param  Migration $migration
     * @return void
     */
    private static function migrateRecordManagedColumn(Migration $migration): void
    {
        $table = 'glpi_plugin_domainmanager_records';

        $migration->addField($table, 'is_managed', 'bool', ['value' => 1]);
        $migration->addKey($table, 'is_managed');
        $migration->dropField($table, 'is_stale');
    }

    /**
     * Seed the "Internet Domain" domain type (by-name idempotent check)
     *
     * @return void
     */
    private static function seedDomainType(): void
    {
        $type = new DomainType();
        if (!$type->getFromDBByCrit(['name' => self::DOMAIN_TYPE_NAME])) {
            $type->add([
                'name'         => self::DOMAIN_TYPE_NAME,
                'entities_id'  => 0,
                'is_recursive' => 1,
                'comment'      => 'Created by the Domain Manager plugin',
            ]);
        }
    }

    /**
     * Ensure the record types handled by the sync exist
     *
     * GLPI ships them by default; they are re-created only if an
     * administrator deleted them.
     *
     * @return void
     */
    private static function seedRecordTypes(): void
    {
        foreach (self::RECORD_TYPE_NAMES as $name) {
            $type = new DomainRecordType();
            if (!$type->getFromDBByCrit(['name' => $name])) {
                $type->add([
                    'name'         => $name,
                    'entities_id'  => 0,
                    'is_recursive' => 1,
                ]);
            }
        }
    }

    /**
     * Register the plugin right, granted by default to profiles with config UPDATE
     *
     * @param  Migration $migration
     * @return void
     */
    private static function registerRights(Migration $migration): void
    {
        $migration->addRight(Profile::UNLOCK_RIGHT, Profile::RIGHT_UNLOCK_IMPORTED, ['config' => UPDATE]);
        // Migration::addRight() inserts rows directly: reset the rights cache
        ProfileRight::cleanAllPossibleRights();
    }

    /**
     * Register the daily sync automatic action (idempotent, tunable in Setup > Automatic actions)
     *
     * @return void
     */
    private static function registerCronTasks(): void
    {
        CronTask::register(
            Cron::class,
            'DomainSync',
            DAY_TIMESTAMP,
            [
                'state'         => CronTask::STATE_WAITING,
                'hourmin'       => 23,
                'hourmax'       => 24,
                'param'         => 20,
                'logs_lifetime' => 30,
                'comment'       => __('Synchronize domain lifecycle and DNS zone records from provider APIs', 'domainmanager'),
            ]
        );
    }
}
