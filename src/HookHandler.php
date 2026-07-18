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
use Supplier;

/**
 * Item hook callbacks (cascade cleanup; Domain form persistence arrives in Phase 4)
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
