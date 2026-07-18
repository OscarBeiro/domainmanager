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
use DomainRecord;
use Session;

/**
 * Server-side enforcement of the plugin lock layer (§0.3): synced Domain
 * fields and plugin-imported DomainRecords are shielded from users lacking
 * the unlock right. Enforcement fires on every entry point (forms, massive
 * actions, API) because it hooks the model layer.
 */
class LockEnforcer
{
    /**
     * Runtime flag set by SyncEngine so its own writes bypass enforcement;
     * not input-based, so it cannot be forged through a form POST
     */
    public static bool $sync_in_progress = false;

    /**
     * Fields on DomainRecord that a lock protects
     */
    private const PROTECTED_RECORD_FIELDS = ['name', 'data', 'ttl', 'domainrecordtypes_id', 'domains_id'];

    /**
     * pre_item_update on Domain: strip locked fields from the input
     *
     * @param  Domain $item
     * @return void
     */
    public static function domainPreUpdate(Domain $item): void
    {
        if (self::canBypass() || !is_array($item->input)) {
            return;
        }

        $locked = ImportLock::getLockedFieldNames(Domain::class, (int) $item->getID());
        if ($locked === []) {
            return;
        }

        $stripped = [];
        foreach ($locked as $field) {
            if (
                array_key_exists($field, $item->input)
                && isset($item->fields[$field])
                && (string) $item->input[$field] !== (string) $item->fields[$field]
            ) {
                unset($item->input[$field]);
                $stripped[] = $field;
            }
        }

        if ($stripped !== []) {
            Session::addMessageAfterRedirect(
                sprintf(
                    __s('These fields are locked by Domain Manager synchronization and were not changed: %s', 'domainmanager'),
                    implode(', ', $stripped)
                ),
                false,
                WARNING
            );
        }
    }

    /**
     * pre_item_update on DomainRecord: strip protected changes on
     * plugin-imported records
     *
     * @param  DomainRecord $item
     * @return void
     */
    public static function domainRecordPreUpdate(DomainRecord $item): void
    {
        if (self::canBypass() || !is_array($item->input)) {
            return;
        }

        if (!ImportedRecord::isPluginOwned((int) $item->getID())) {
            return;
        }

        $stripped = [];
        foreach (self::PROTECTED_RECORD_FIELDS as $field) {
            if (
                array_key_exists($field, $item->input)
                && isset($item->fields[$field])
                && (string) $item->input[$field] !== (string) $item->fields[$field]
            ) {
                unset($item->input[$field]);
                $stripped[] = $field;
            }
        }

        if ($stripped !== []) {
            Session::addMessageAfterRedirect(
                __s('This record is imported by Domain Manager synchronization and cannot be modified', 'domainmanager'),
                false,
                WARNING
            );
        }
    }

    /**
     * pre_item_delete on DomainRecord
     *
     * @param  DomainRecord $item
     * @return void
     */
    public static function domainRecordPreDelete(DomainRecord $item): void
    {
        self::blockRecordRemoval($item);
    }

    /**
     * pre_item_purge on DomainRecord
     *
     * @param  DomainRecord $item
     * @return void
     */
    public static function domainRecordPrePurge(DomainRecord $item): void
    {
        self::blockRecordRemoval($item);
    }

    /**
     * Cancel deletion/purge of plugin-imported records ($input = false)
     *
     * @param  DomainRecord $item
     * @return void
     */
    private static function blockRecordRemoval(DomainRecord $item): void
    {
        if (self::canBypass()) {
            return;
        }

        if (!ImportedRecord::isPluginOwned((int) $item->getID())) {
            return;
        }

        $item->input = false;
        Session::addMessageAfterRedirect(
            __s('This record is imported by Domain Manager synchronization and cannot be removed', 'domainmanager'),
            false,
            ERROR
        );
    }

    /**
     * @return bool
     */
    private static function canBypass(): bool
    {
        return self::$sync_in_progress
            || Session::isCron()
            || Session::haveRight(Profile::UNLOCK_RIGHT, Profile::RIGHT_UNLOCK_IMPORTED);
    }
}
