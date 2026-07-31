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
use GlpiPlugin\Domainmanager\Config\Config;
use Migration;
use ProfileRight;

class Installer
{
    public const DOMAIN_TYPE_NAME = 'Internet Domain';

    public const RECORD_TYPE_NAMES = ['A', 'AAAA', 'ALIAS', 'CNAME', 'MX', 'NS', 'PTR', 'SOA', 'SRV', 'TXT', 'CAA'];

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
        self::addRegistrarMetadataColumns($migration);
        self::addRecordProxiedColumn($migration);
        self::addRecordGlpiCreatedColumn($migration);
        self::addDomainManagedColumn($migration);
        self::addDomainGlpiCreatedColumn($migration);
        self::addNameAsciiColumn($migration);
        self::addRdapColumns($migration);
        self::addDnsWriteStatusColumns($migration);
        self::seedRecordProxyDisplayPreference();
        self::clearDuplicateNameAscii();
        self::pruneStaleSearchOptionCriteria();
        self::seedDomainType();
        self::seedRecordTypes();
        self::registerRights($migration);
        self::migratePurgeRight();
        self::registerCronTasks();
        self::dropRecordConflictsTable($migration);
        self::backfillManagedFieldLocks();

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

        Config::uninstall();

        ProfileRight::deleteProfileRights([Profile::UNLOCK_RIGHT]);

        // Defensive: no plugin itemtype registers display preferences by default
        $DB->delete(
            'glpi_displaypreferences',
            ['itemtype' => ['LIKE', 'GlpiPlugin\\\\Domainmanager\\\\%']],
        );

        // seedRecordProxyDisplayPreference() below is the one exception:
        // it seeds a global-default (users_id=0) column on core DomainRecord
        // itself, not a plugin itemtype, so the LIKE-based delete above
        // never catches it.
        $DB->delete(
            'glpi_displaypreferences',
            [
                'itemtype' => 'DomainRecord',
                'num'      => PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_PROXY,
                'users_id' => 0,
            ],
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
                    `is_managed` tinyint NOT NULL DEFAULT '0',
                    `is_glpi_created` tinyint NOT NULL DEFAULT '1',
                    `name_ascii` varchar(255) NOT NULL DEFAULT '',
                    `last_rdap_check_date` datetime NULL DEFAULT NULL,
                    `last_changed_date` datetime NULL DEFAULT NULL,
                    `transfer_date` datetime NULL DEFAULT NULL,
                    `pending_delete` tinyint NULL DEFAULT NULL,
                    `pending_transfer` tinyint NULL DEFAULT NULL,
                    `rdap_registrar_name` varchar(255) NULL DEFAULT NULL,
                    `rdap_registrar_iana_id` varchar(32) NULL DEFAULT NULL,
                    `rdap_nameservers` text,
                    `dns_write_status` varchar(20) NOT NULL DEFAULT 'manual',
                    `dns_write_message` text,
                    `date_mod` timestamp NULL DEFAULT NULL,
                    `date_creation` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `domains_id` (`domains_id`),
                    KEY `registrar_suppliers_id` (`registrar_suppliers_id`),
                    KEY `dns_suppliers_id` (`dns_suppliers_id`),
                    KEY `last_sync_date` (`last_sync_date`),
                    KEY `is_managed` (`is_managed`),
                    KEY `is_glpi_created` (`is_glpi_created`),
                    KEY `name_ascii` (`name_ascii`),
                    KEY `last_rdap_check_date` (`last_rdap_check_date`),
                    KEY `dns_write_status` (`dns_write_status`),
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
                    `is_proxied` tinyint NULL DEFAULT NULL,
                    `is_glpi_created` tinyint NOT NULL DEFAULT '0',
                    `date_mod` timestamp NULL DEFAULT NULL,
                    `date_creation` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `domainrecords_id` (`domainrecords_id`),
                    KEY `domains_id` (`domains_id`),
                    KEY `remote_id` (`remote_id`),
                    KEY `record_hash` (`record_hash`),
                    KEY `is_managed` (`is_managed`),
                    KEY `is_proxied` (`is_proxied`),
                    KEY `is_glpi_created` (`is_glpi_created`)
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
     * Every install between `addNameAsciiColumn()` shipping and this fix
     * backfilled/wrote `name_ascii` unconditionally from
     * `IdnNormalizer::toAscii()`, which Punycode-encodes a plain-ASCII
     * domain to itself — so every non-IDN domain's `name_ascii` duplicated
     * `glpi_domains.name`, making the "Punycode name" search option match
     * (and therefore fail to *filter*) every domain rather than just real
     * IDN ones (§9 Phase 17 addendum "Punycode name duplicates the domain
     * name"). One-time cleanup, idempotent (a no-op once no row's
     * `name_ascii` still equals its Domain's `name`): re-derives nothing,
     * just blanks out the rows `addNameAsciiColumn()`/`HookHandler::
     * domainSaved()` would no longer write that value for today.
     *
     * @return void
     */
    private static function clearDuplicateNameAscii(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = 'glpi_plugin_domainmanager_states';
        if (!$DB->fieldExists($table, 'name_ascii', false)) {
            return;
        }

        $DB->update(
            $table,
            ['name_ascii' => ''],
            [
                'name_ascii' => new \QueryExpression(
                    '(SELECT `name` FROM `glpi_domains` WHERE `glpi_domains`.`id` = `' . $table . '`.`domains_id`)',
                ),
            ],
        );
    }

    /**
     * Add the 7 registrar administrative-metadata columns to the states
     * table (§9 Phase 7). These are plugin-only concepts with no native
     * `glpi_domains` equivalent (unlike `date_domaincreation`/
     * `date_expiration`/`is_active`, which already existed as native
     * columns before this plugin — §0.2) — they live here, on the
     * plugin's own read-only state table, exactly like `detected_provider`/
     * `registrar_status` already do, and deliberately need no
     * `ImportLock`/`LockEnforcer` treatment: nothing exposes them as an
     * editable native Domain form field a user could otherwise touch, so
     * there is nothing for a lock to protect (§9's own open question on
     * lock-semantics parity, answered here rather than deferred).
     * All 7 use a nullable raw type string (`Migration::addField()`'s
     * `bool`/`string` shorthands always force `NOT NULL`, per precedent
     * already noted for `registrar_test_http_code` above) — `null` means
     * "this driver's API doesn't report it", a real, meaningful third
     * state, not merely absent.
     *
     * @param  Migration $migration
     * @return void
     */
    private static function addRegistrarMetadataColumns(Migration $migration): void
    {
        $table = 'glpi_plugin_domainmanager_states';

        $migration->addField($table, 'registrar_auth_info', 'varchar(255) NULL DEFAULT NULL');
        $migration->addField($table, 'registrar_privacy_enabled', 'tinyint NULL DEFAULT NULL');
        $migration->addField($table, 'registrar_domain_lock', 'tinyint NULL DEFAULT NULL');
        $migration->addField($table, 'registrar_transfer_lock', 'tinyint NULL DEFAULT NULL');
        $migration->addField($table, 'registrar_auto_renew', 'tinyint NULL DEFAULT NULL');
        $migration->addField($table, 'registrar_domain_type', 'varchar(50) NULL DEFAULT NULL');
        $migration->addField($table, 'registrar_dnssec_enabled', 'tinyint NULL DEFAULT NULL');
    }

    /**
     * Add `is_proxied` to the records table (§9 Phase 7 addendum "Searchable
     * 'Proxy Status' Field for CDN-Proxied Records") — same table
     * `is_managed` already lives on (§5.7), not a new one. Idempotent via
     * `Migration::addField()` for upgrades; already present in
     * `createTables()`'s raw CREATE TABLE for fresh installs, matching the
     * existing `is_managed` precedent. A genuine tri-state, nullable
     * tinyint: `NULL` on every pre-existing row (an upgrade must never
     * backfill `0` — that would falsely claim "confirmed not proxied" for
     * records nothing has re-synced under proxy-awareness yet), populated
     * for real only the next time each record's domain is synced.
     *
     * @param  Migration $migration
     * @return void
     */
    private static function addRecordProxiedColumn(Migration $migration): void
    {
        $table = 'glpi_plugin_domainmanager_records';

        $migration->addField($table, 'is_proxied', 'tinyint NULL DEFAULT NULL');
        $migration->addKey($table, 'is_proxied');
    }

    /**
     * Makes the "Proxy status" search option (PLUGIN_DOMAINMANAGER_SO_
     * DOMAINRECORD_PROXY, registered in setup.php against core DomainRecord)
     * show up as a real column on the native Records list out of the box,
     * not just something a user can dig for under "Add criteria" (§9 Phase
     * 49). A `users_id => 0` row is GLPI's own "general default" convention
     * (DisplayPreference::GENERAL) — applies to every user who hasn't
     * customized their own DomainRecord list columns, and never overrides
     * a user who already has. Idempotent: checked for existence first since
     * there is no unique key to rely on and this runs on every
     * install/upgrade.
     *
     * @return void
     */
    private static function seedRecordProxyDisplayPreference(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $exists = countElementsInTable('glpi_displaypreferences', [
            'itemtype' => 'DomainRecord',
            'num'      => PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_PROXY,
            'users_id' => 0,
        ]) > 0;

        if ($exists) {
            return;
        }

        $rank = (int) ($DB->request([
            'SELECT' => new \QueryExpression('MAX(' . $DB->quoteName('rank') . ') AS ' . $DB->quoteName('max_rank')),
            'FROM'   => 'glpi_displaypreferences',
            'WHERE'  => ['itemtype' => 'DomainRecord', 'users_id' => 0],
        ])->current()['max_rank'] ?? 0);

        $DB->insert('glpi_displaypreferences', [
            'itemtype' => 'DomainRecord',
            'num'      => PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_PROXY,
            'rank'     => $rank + 1,
            'users_id' => 0,
        ]);
    }

    /**
     * Add `is_glpi_created` to the records table (ARCHITECTURE.md §11.12,
     * Phase 32) — same table `is_managed`/`is_proxied` already live on.
     * Idempotent via `Migration::addField()`/`addKey()` for upgrades;
     * already present in `createTables()`'s raw CREATE TABLE for fresh
     * installs, same convention as `is_proxied`.
     *
     * Unlike `is_proxied`, this is a non-nullable tinyint defaulting to
     * `0`: every pre-existing row was created by the reconciler, so
     * default `0` ("not GLPI-created") is factually correct for every one
     * of them and needs no backfill. Set once at creation by the write
     * path (§11.12, reusing `RecordReconciler::createRecord()`), never
     * changed afterwards — a record authored in GLPI stays authored in
     * GLPI even after a later upstream edit updates its other fields.
     *
     * @param  Migration $migration
     * @return void
     */
    private static function addRecordGlpiCreatedColumn(Migration $migration): void
    {
        $table = 'glpi_plugin_domainmanager_records';

        $migration->addField($table, 'is_glpi_created', 'bool', ['value' => 0]);
        $migration->addKey($table, 'is_glpi_created');
    }

    /**
     * Add `dns_write_status`/`dns_write_message` to the states table
     * (ARCHITECTURE.md §12.3, Phase 42) — per-domain, per-write-capable-driver
     * editability state for DNS record write-back, learned from real writes
     * only, never probed. Idempotent via `Migration::addField()`/`addKey()`
     * for upgrades; already present in `createTables()`'s raw CREATE TABLE for
     * fresh installs, same convention as `is_proxied`/`is_glpi_created`.
     *
     * `dns_write_status` defaults to `DomainState::DNS_WRITE_MANUAL` on every
     * pre-existing row: the next sync that recognizes a write-capable driver
     * for that domain moves it to `managed_readonly` (§12.3) — no backfill
     * needed here.
     *
     * @param  Migration $migration
     * @return void
     */
    private static function addDnsWriteStatusColumns(Migration $migration): void
    {
        $table = 'glpi_plugin_domainmanager_states';

        $migration->addField($table, 'dns_write_status', 'string', ['value' => 'manual']);
        $migration->addKey($table, 'dns_write_status');
        $migration->addField($table, 'dns_write_message', 'text', ['value' => null]);
    }

    /**
     * Add `is_managed` to the states table (§9 Phase 14 "Domain-level
     * Managed field"), backing the new Domain-level "Managed" search
     * option. Idempotent via `Migration::addField()`/`addKey()` for
     * upgrades; already present in `createTables()`'s raw CREATE TABLE for
     * fresh installs, same convention as `is_proxied`/`is_managed` on the
     * records table.
     *
     * Unlike `is_proxied` (which must stay `NULL` on upgrade — nothing has
     * re-synced under proxy-awareness yet), this table's existing rows
     * already carry real, meaningful `registrar_status`/`dns_status`
     * history from every prior sync/reassignment — defaulting them all to
     * `0` would falsely report every already-managed domain as unmanaged
     * until its next sync happens to run. So this migration also backfills
     * every existing row from that history in the same definition
     * `SyncEngine`/`HookHandler::infocomSaved()` use going forward: managed
     * whenever either leg's last known status is one only reachable after
     * that leg's pre-flight checks passed (`STATUS_OK`/`STATUS_ERROR`) —
     * see `DomainState::resolvesToActiveDriver()`'s docblock for why those
     * two values specifically mean "a real, driver-backed, active supplier
     * was resolved", independent of whether the API call itself succeeded.
     *
     * @param  Migration $migration
     * @return void
     */
    private static function addDomainManagedColumn(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table           = 'glpi_plugin_domainmanager_states';
        $column_existed  = $DB->fieldExists($table, 'is_managed', false);

        $migration->addField($table, 'is_managed', 'bool', ['value' => 0]);
        $migration->addKey($table, 'is_managed');
        // Migration::addField()/addKey() only queue the ALTER; flush it now
        // so the backfill UPDATE below (raw $DB->update(), not queued) runs
        // against a column that actually exists yet. executeMigration() is
        // safe to call multiple times per migration (it flushes the current
        // queue) and is also called once more at the end of install().
        $migration->executeMigration();

        if (!$column_existed) {
            $resolved = [DomainState::STATUS_OK, DomainState::STATUS_ERROR];
            $iterator = $DB->request([
                'SELECT' => ['id', 'registrar_status', 'dns_status'],
                'FROM'   => $table,
            ]);
            foreach ($iterator as $row) {
                $is_managed = in_array($row['registrar_status'], $resolved, true)
                    || in_array($row['dns_status'], $resolved, true);
                if ($is_managed) {
                    $DB->update($table, ['is_managed' => 1], ['id' => (int) $row['id']]);
                }
            }
        }
    }

    /**
     * Add `is_glpi_created` to the states table (ARCHITECTURE.md §14.2,
     * Phase 47) — the Domain-level counterpart to
     * `addRecordGlpiCreatedColumn()` above, backing the new "Native" search
     * option. Idempotent via `Migration::addField()`/`addKey()` for
     * upgrades; already present in `createTables()`'s raw CREATE TABLE for
     * fresh installs.
     *
     * Unlike the records table's version (which defaults `0`, since every
     * pre-existing row there was reconciler-created), this one defaults `1`:
     * every Domain that already has a state row got there either by manual
     * creation followed by a sync, or — before this phase existed — by
     * `DomainImportController`'s bulk import, with no way to tell the two
     * apart retroactively from the state row alone. Defaulting to "Native"
     * matches the far more common real-world case this plugin is deployed
     * into (§14, ARCHITECTURE.md: "most scenarios will be running GLPIs with
     * manual domains") and errs toward under- rather than over-reporting
     * imported domains as native. Set once at state-row creation only
     * (`SyncEngine::sync()`, `$isImport` parameter), never changed
     * afterward, same convention as the records table's version.
     *
     * @param  Migration $migration
     * @return void
     */
    private static function addDomainGlpiCreatedColumn(Migration $migration): void
    {
        $table = 'glpi_plugin_domainmanager_states';

        $migration->addField($table, 'is_glpi_created', 'bool', ['value' => 1]);
        $migration->addKey($table, 'is_glpi_created');
    }

    /**
     * Add `name_ascii` to the states table: a cached Punycode/ACE form of
     * the Domain's own `glpi_domains.name` (§9 Phase 17 "Domain identity
     * header"), kept in sync going forward by `HookHandler::domainSaved()`
     * on Domain `item_add`/`item_update`. This exists purely to make the
     * Punycode form searchable — `glpi_domains.name` stores the Unicode
     * form (`IdnNormalizer` is the single conversion point, §9 Phase 10),
     * and MySQL has no IDN function, so there is no way to match a
     * pasted-in Punycode string against the stored Unicode name without a
     * persisted ASCII column to search against instead.
     *
     * Backfilled from every non-deleted Domain on first install of this
     * column, not just Domains that already have a state row — a Domain
     * with no registrar/sync history yet must still be findable by
     * Punycode, so this creates a state row for it when one doesn't
     * already exist (mirroring `HookHandler::infocomSaved()`'s own
     * create-if-missing branch), unlike `addDomainManagedColumn()` above
     * which only ever updates rows that already exist.
     *
     * @param  Migration $migration
     * @return void
     */
    private static function addNameAsciiColumn(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table          = 'glpi_plugin_domainmanager_states';
        $column_existed = $DB->fieldExists($table, 'name_ascii', false);

        $migration->addField($table, 'name_ascii', 'varchar(255) NOT NULL DEFAULT \'\'');
        $migration->addKey($table, 'name_ascii');
        // Flush now so the backfill below runs against a column that
        // actually exists yet, same reasoning as addDomainManagedColumn().
        $migration->executeMigration();

        if ($column_existed) {
            return;
        }

        $now      = date('Y-m-d H:i:s');
        $iterator = $DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_domains',
            'WHERE'  => ['is_deleted' => 0],
        ]);

        foreach ($iterator as $row) {
            $domains_id = (int) $row['id'];
            $name       = (string) $row['name'];
            $name_ascii = IdnNormalizer::toAscii($name);
            // Same "distinct value only" rule as HookHandler::domainSaved()
            // — a plain-ASCII domain Punycode-encodes to itself, so leave
            // it empty rather than backfilling a duplicate of the name.
            if ($name_ascii === $name) {
                $name_ascii = '';
            }

            $state = DomainState::getForDomain($domains_id);
            if ($state !== null) {
                $DB->update($table, ['name_ascii' => $name_ascii], ['id' => $state->getID()]);
            } else {
                $DB->insert($table, [
                    'domains_id'    => $domains_id,
                    'name_ascii'    => $name_ascii,
                    'date_creation' => $now,
                    'date_mod'      => $now,
                ]);
            }
        }
    }

    /**
     * Add the RDAP-enrichment columns to the states table (§9 Phases 21-27,
     * `PHASE21_PLAN.md`). Same table `is_managed`/`name_ascii`/the registrar
     * metadata columns already live on — a plugin-only concept with no
     * native `glpi_domains` equivalent, so it belongs on this read-only
     * state table rather than a new one.
     *
     * §9 Phase 27 revised the naming/storage strategy after review: no
     * "rdap_" prefix on fields with no ambiguity risk (`last_changed_date`/
     * `transfer_date`/`pending_delete`/`pending_transfer` — RDAP is simply
     * this data's *source*, not part of its name, same as e.g.
     * `registrar_dnssec_enabled` doesn't say which driver reported it), and
     * Transfer lock/Domain lock/DNSSEC no longer get a parallel `rdap_*`
     * shadow column at all — RDAP fills the *existing*
     * `registrar_transfer_lock`/`registrar_domain_lock`/`registrar_dnssec_enabled`
     * columns directly, only when the driver hasn't already reported a
     * value (see `RdapGapChecker`/`Cron::processRdapEnrichment()` and
     * `SyncEngine`'s matching "only touch fields actually reported" fix
     * below). `rdap_registrar_name`/`rdap_registrar_iana_id`/`rdap_nameservers`
     * keep the prefix: unlike the fields above, these represent a genuinely
     * different concept from anything already named "registrar" on this
     * table (Infocom's Supplier mirror) and dropping the prefix would read
     * as if they were that same authoritative value (§9 Phase 21
     * "Registrar-of-record note").
     *
     * All nullable, all additive, no changes to existing columns: `null`
     * means "not yet checked via RDAP" (for the tri-state flags/text
     * fields) or "never checked" (for `last_rdap_check_date`), the same
     * real, meaningful third state already established for
     * `registrar_dnssec_enabled` et al. above.
     *
     * @param  Migration $migration
     * @return void
     */
    private static function addRdapColumns(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = 'glpi_plugin_domainmanager_states';

        $migration->addField($table, 'last_rdap_check_date', 'datetime', ['value' => null]);

        // §9 Phase 27: renamed away from the "rdap_"-prefixed/shadow-column
        // naming this feature briefly had (never in a real release, only on
        // two dev containers). `changeField()`/`addField()` both queue
        // their ALTER clause purely from the *current* live schema at call
        // time, with no awareness of each other's pending clauses in the
        // same run — calling both for the same target column name in one
        // migration (rename old->new AND add new "if missing") produces a
        // single ALTER TABLE with two clauses creating the same column,
        // which MySQL rejects outright. So each of these is an *either/or*,
        // decided per-instance by checking which name (if either) already
        // exists: a genuine 1.0.0-vintage install upgrading directly gets
        // `addField()` (never had either name); a beta install that still
        // has the old name gets `changeField()` (rename, no data loss); an
        // install already on the final name (fresh installs via the
        // CREATE TABLE path above, or an already-upgraded beta) gets
        // neither — both calls are no-ops once their target/source column
        // already matches.
        foreach (
            [
                ['rdap_last_changed_date', 'last_changed_date', 'datetime', ['value' => null]],
                ['rdap_transfer_date', 'transfer_date', 'datetime', ['value' => null]],
                ['rdap_pending_delete', 'pending_delete', 'tinyint NULL DEFAULT NULL', []],
                ['rdap_pending_transfer', 'pending_transfer', 'tinyint NULL DEFAULT NULL', []],
            ] as [$oldfield, $newfield, $type, $options]
        ) {
            if ($DB->fieldExists($table, $oldfield, false)) {
                $migration->changeField($table, $oldfield, $newfield, $type, $options);
            } else {
                $migration->addField($table, $newfield, $type, $options);
            }
        }

        $migration->addField($table, 'rdap_registrar_name', 'varchar(255) NULL DEFAULT NULL');
        $migration->addField($table, 'rdap_registrar_iana_id', 'varchar(32) NULL DEFAULT NULL');
        $migration->addField($table, 'rdap_nameservers', 'text', ['value' => null]);
        $migration->addKey($table, 'last_rdap_check_date');

        // Transfer lock/Domain lock/DNSSEC folded into the existing
        // registrar_transfer_lock/registrar_domain_lock/registrar_dnssec_enabled
        // columns instead of a parallel rdap_* one (§9 Phase 27) — nothing
        // to migrate the *values* into (these beta-only columns never held
        // anything a real user would miss), just drop them if present.
        $migration->dropField($table, 'rdap_transfer_lock');
        $migration->dropField($table, 'rdap_domain_lock');
        $migration->dropField($table, 'rdap_dnssec_signed');
    }

    /**
     * Rewrite any pre-existing saved search / bookmark still referencing one
     * of the three dummy/duplicate search-option IDs dropped in §9 Phase 14
     * addendum "Search UI cleanup" (`search-options-registry.json`'s
     * `removed` entries: Supplier 9401, Domain 9402/9403). GLPI's
     * `QueryBuilder::validateFilters()` already silently discards any
     * criterion referencing an option ID that no longer exists for that
     * itemtype (`src/Glpi/Search/Input/QueryBuilder.php`) — safe, but it
     * re-triggers a `E_USER_WARNING` ("Attempted to use invalid search
     * options...") on every render of a saved/default search still carrying
     * one of these IDs, indefinitely, since nothing ever rewrites the saved
     * `query` string itself. `glpi_savedsearches.query` is a plain
     * URL-encoded query string (`criteria[0][field]=9403&...`), not JSON —
     * `parse_str()`/`http_build_query()` round-trip it losslessly for the
     * one `criteria[n][field]` key this touches, leaving every other
     * criterion/sort/display option in the saved search untouched. Idempotent:
     * a row with none of these IDs is read and skipped without a write.
     *
     * @return void
     */
    private static function pruneStaleSearchOptionCriteria(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $stale_ids_by_itemtype = [
            'Supplier' => [9401],
            'Domain'   => [9402, 9403],
        ];

        foreach ($stale_ids_by_itemtype as $itemtype => $stale_ids) {
            $iterator = $DB->request([
                'SELECT' => ['id', 'query'],
                'FROM'   => 'glpi_savedsearches',
                'WHERE'  => ['itemtype' => $itemtype],
            ]);

            foreach ($iterator as $row) {
                parse_str((string) $row['query'], $params);

                if (!isset($params['criteria']) || !is_array($params['criteria'])) {
                    continue;
                }

                $changed = false;
                foreach ($params['criteria'] as $key => $criterion) {
                    if (isset($criterion['field']) && in_array((int) $criterion['field'], $stale_ids, true)) {
                        unset($params['criteria'][$key]);
                        $changed = true;
                    }
                }

                if (!$changed) {
                    continue;
                }

                $params['criteria'] = array_values($params['criteria']);
                $DB->update(
                    'glpi_savedsearches',
                    ['query' => http_build_query($params)],
                    ['id' => (int) $row['id']],
                );
            }
        }
    }

    /**
     * Seed the "Internet Domain" domain type (by-name idempotent check),
     * always — it stays a convenient default option in the §9 Phase 12
     * config dropdown regardless of whether it's actually applied.
     *
     * Also seeds the Phase 12 "domain type to apply to imported domains"
     * config value, exactly once (`Config::seedDefault()` never overwrites
     * an admin's later choice): if "Internet Domain" already existed
     * *before* this call, this install/activation is an upgrade from a
     * pre-Phase-12 version that force-assigned it to every imported domain
     * — default the new setting to that same type so upgrading doesn't
     * silently change behavior. A fresh install (the type didn't exist
     * yet) has no prior behavior to preserve, so it defaults to unset/0
     * (imported domains get no type, same as one created by hand).
     *
     * @return void
     */
    private static function seedDomainType(): void
    {
        $type    = new DomainType();
        $existed = (bool) $type->getFromDBByCrit(['name' => self::DOMAIN_TYPE_NAME]);

        if (!$existed) {
            $type->add([
                'name'         => self::DOMAIN_TYPE_NAME,
                'entities_id'  => 0,
                'is_recursive' => 1,
                'comment'      => 'Created by the Domain Manager plugin',
            ]);
        }

        Config::seedDefault($existed ? (int) $type->getID() : 0);
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
    /**
     * One-time cleanup for pre-existing installs that already carried the
     * old single flat `domainmanager:purge_records` right (Phase 45),
     * folded here into per-type PURGE bits (ARCHITECTURE.md §11.6
     * addendum): any profile that held the old right's bit 1 gets the
     * PURGE bit granted on every per-type `dns_records_*` right instead —
     * the old right had no per-type distinction, so this is the closest
     * equivalent — then the old right's rows are removed entirely.
     *
     * @return void
     */
    private static function migratePurgeRight(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $old_right = 'domainmanager:purge_records';

        $iterator = $DB->request([
            'SELECT' => ['profiles_id'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => $old_right, 'rights' => ['&', 1]],
        ]);

        foreach ($iterator as $row) {
            $profiles_id = (int) $row['profiles_id'];
            foreach (Profile::getDnsRecordRights() as $field) {
                $current = $DB->request([
                    'SELECT' => ['rights'],
                    'FROM'   => 'glpi_profilerights',
                    'WHERE'  => ['profiles_id' => $profiles_id, 'name' => $field],
                ])->current();

                if ($current === null) {
                    continue;
                }

                $DB->update(
                    'glpi_profilerights',
                    ['rights' => ((int) $current['rights']) | PURGE],
                    ['profiles_id' => $profiles_id, 'name' => $field],
                );
            }
        }

        $DB->delete('glpi_profilerights', ['name' => $old_right]);
        ProfileRight::cleanAllPossibleRights();
    }

    private static function registerRights(Migration $migration): void
    {
        $migration->addRight(Profile::UNLOCK_RIGHT, Profile::RIGHT_UNLOCK_IMPORTED, ['config' => UPDATE]);
        // ARCHITECTURE.md §11.6 (Phase 34b, superseding Phase 32's single
        // flat right): one write-capable right per writable record type,
        // none auto-granted to any existing profile — unlike the unlock
        // right above (piggybacked on config UPDATE), pushing changes to a
        // live provider is sensitive enough that an admin must grant each
        // type explicitly per profile.
        // Each right's PURGE bit (irreversible on the GLPI side, since the
        // provider was already synced at soft-delete time) is likewise not
        // auto-granted — an admin must opt a profile in explicitly, per type.
        foreach (Profile::getDnsRecordRights() as $field) {
            $migration->addRight($field, 0);
        }
        // Migration::addRight() inserts rows directly: reset the rights cache
        ProfileRight::cleanAllPossibleRights();
    }

    /**
     * The RecordConflict update-conflict-resolution feature (was
     * ARCHITECTURE.md §13, Phase 44) was removed: for a record Domain
     * Manager actively manages, GLPI's value is always authoritative, so
     * there was never a genuine conflict to reconcile. No longer created
     * for fresh installs (dropped from `createTables()`'s schema); this
     * drops the table for anyone upgrading from a version that still has
     * it, leaving no residue, same as `uninstall()`'s own table cleanup.
     *
     * @param  Migration $migration
     * @return void
     */
    private static function dropRecordConflictsTable(Migration $migration): void
    {
        $migration->dropTable('glpi_plugin_domainmanager_recordconflicts');
    }

    /**
     * §9: one-time (per-install/upgrade, idempotent) backfill locking
     * `domaintypes_id`/`date_domaincreation`/`date_expiration` on every
     * already-managed domain that has a value for them but no lock yet.
     * Needed because both are otherwise only ever locked as a *side effect*
     * of a live sync/RDAP-enrichment run actually touching that field —
     * `SyncEngine`/`Cron` only relock what a run itself just wrote or had
     * previously locked, so a domain that was already fully enriched
     * before this locking existed (RDAP's own `hasGap()` pre-check means
     * such a domain may never run its enrichment again at all) would
     * otherwise stay unlocked forever.
     *
     * @return void
     */
    private static function backfillManagedFieldLocks(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_domains.id',
                'glpi_domains.domaintypes_id',
                'glpi_domains.date_domaincreation',
                'glpi_domains.date_expiration',
            ],
            'FROM'      => 'glpi_domains',
            'INNER JOIN' => [
                'glpi_plugin_domainmanager_states' => [
                    'ON' => [
                        'glpi_plugin_domainmanager_states' => 'domains_id',
                        'glpi_domains'                      => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_domains.is_deleted'                       => 0,
                'glpi_plugin_domainmanager_states.is_managed'   => 1,
            ],
        ]);

        foreach ($iterator as $row) {
            $domains_id = (int) $row['id'];
            $locked     = ImportLock::getLockedFieldNames(\Domain::class, $domains_id);

            if ((int) $row['domaintypes_id'] > 0 && !in_array('domaintypes_id', $locked, true)) {
                ImportLock::setLock(\Domain::class, $domains_id, 'domaintypes_id', $row['domaintypes_id']);
            }
            if (!empty($row['date_domaincreation']) && !in_array('date_domaincreation', $locked, true)) {
                ImportLock::setLock(\Domain::class, $domains_id, 'date_domaincreation', $row['date_domaincreation']);
            }
            if (!empty($row['date_expiration']) && !in_array('date_expiration', $locked, true)) {
                ImportLock::setLock(\Domain::class, $domains_id, 'date_expiration', $row['date_expiration']);
            }
        }
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
            ],
        );

        // §9 Phase 22: its own automatic action, independently configurable
        // from the daily sync task above — default every 10 minutes, one
        // domain per tick (the cron cadence itself is the rate-limit
        // defense against rdap.org, §9 Phase 21 "Rate-limit rationale").
        CronTask::register(
            Cron::class,
            'RdapEnrichment',
            10 * MINUTE_TIMESTAMP,
            [
                'state'         => CronTask::STATE_WAITING,
                'logs_lifetime' => 30,
                'comment'       => __('Fill registrar-reported gaps (dates, lock/DNSSEC status, pending flags) from RDAP. Processes one domain per execution, gated by a daily per-domain check limit, to avoid overloading the RDAP API', 'domainmanager'),
            ],
        );
    }
}
