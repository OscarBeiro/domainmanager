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
use Dropdown;
use Log;
use Session;
use Supplier;

/**
 * Item hook callbacks (cascade cleanup, Registrar field persistence,
 * Domain pre-update dispatch)
 */
class HookHandler
{
    /**
     * item_purge on Supplier: drop its configuration and detach it from
     * every domain sync state
     *
     * @param  Supplier $supplier
     * @return void
     */
    public static function supplierPurged(Supplier $supplier): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $suppliers_id = (int) $supplier->getID();
        if ($suppliers_id <= 0) {
            return;
        }

        $DB->delete(SupplierConfig::getTable(), ['suppliers_id' => $suppliers_id]);

        DomainState::onSupplierPurge($suppliers_id);
    }

    /**
     * item_add on Domain: persist the injected Registrar field
     *
     * @param  Domain $domain
     * @return void
     */
    public static function domainAdded(Domain $domain): void
    {
        self::persistRegistrar($domain);
    }

    /**
     * pre_item_update on Domain: lock enforcement, then Registrar
     * persistence. The registrar must be handled pre-update because the
     * item_update hook only fires when a glpi_domains column actually
     * changed — a dropdown-only save would otherwise be lost.
     *
     * @param  Domain $domain
     * @return void
     */
    public static function domainPreUpdate(Domain $domain): void
    {
        LockEnforcer::domainPreUpdate($domain);
        self::persistRegistrar($domain);
    }

    /**
     * Store _domainmanager_registrar from the form input into the state row
     * (§0.1: glpi_domains has no supplier column, the Registrar is plugin-owned)
     *
     * @param  Domain $domain
     * @return void
     */
    private static function persistRegistrar(Domain $domain): void
    {
        if (!is_array($domain->input) || !isset($domain->input['_domainmanager_registrar'])) {
            return;
        }

        if (!Session::isCron() && !Session::haveRight('domain', UPDATE) && !Domain::canCreate()) {
            return;
        }

        $suppliers_id = max(0, (int) $domain->input['_domainmanager_registrar']);
        $domains_id   = (int) $domain->getID();

        $state = DomainState::getForDomain($domains_id);
        if ($state !== null) {
            $old_suppliers_id = (int) $state->fields['registrar_suppliers_id'];
            if ($old_suppliers_id !== $suppliers_id) {
                $state->update([
                    'id'                     => $state->getID(),
                    'registrar_suppliers_id' => $suppliers_id,
                ]);
                self::logRegistrarChange($domains_id, $old_suppliers_id, $suppliers_id);
            }
        } else {
            (new DomainState())->add([
                'domains_id'             => $domains_id,
                'registrar_suppliers_id' => $suppliers_id,
            ]);
            if ($suppliers_id > 0) {
                self::logRegistrarChange($domains_id, 0, $suppliers_id);
            }
        }
    }

    /**
     * Log a Registrar supplier assignment change on the Domain's own native
     * Historical tab (§3.7)
     *
     * @param  int $domains_id
     * @param  int $old_suppliers_id
     * @param  int $new_suppliers_id
     * @return void
     */
    private static function logRegistrarChange(int $domains_id, int $old_suppliers_id, int $new_suppliers_id): void
    {
        $old_name = $old_suppliers_id > 0 ? Dropdown::getDropdownName(Supplier::getTable(), $old_suppliers_id) : '';
        $new_name = $new_suppliers_id > 0 ? Dropdown::getDropdownName(Supplier::getTable(), $new_suppliers_id) : '';

        $message = match (true) {
            $old_suppliers_id === 0 && $new_suppliers_id > 0 => sprintf(__('[Domain Manager] Registrar supplier set to %s', 'domainmanager'), $new_name),
            $old_suppliers_id > 0 && $new_suppliers_id === 0 => __('[Domain Manager] Registrar supplier cleared', 'domainmanager'),
            default => sprintf(__('[Domain Manager] Registrar supplier changed from %1$s to %2$s', 'domainmanager'), $old_name, $new_name),
        };

        Log::history($domains_id, Domain::class, [0, '', $message]);
    }

    /**
     * item_purge on Domain: drop its state row, record ownership map and locks
     *
     * @param  Domain $domain
     * @return void
     */
    public static function domainPurged(Domain $domain): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $domains_id = (int) $domain->getID();
        if ($domains_id <= 0) {
            return;
        }

        $DB->delete(DomainState::getTable(), ['domains_id' => $domains_id]);
        $DB->delete(ImportedRecord::getTable(), ['domains_id' => $domains_id]);
        ImportLock::deleteForItem(Domain::class, $domains_id);
    }

    /**
     * item_purge on DomainRecord (by an unlock right holder): drop its
     * ownership row so the next sync can re-import it
     *
     * @param  DomainRecord $record
     * @return void
     */
    public static function domainRecordPurged(DomainRecord $record): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $records_id = (int) $record->getID();
        if ($records_id <= 0) {
            return;
        }

        $DB->delete(ImportedRecord::getTable(), ['domainrecords_id' => $records_id]);
    }
}
