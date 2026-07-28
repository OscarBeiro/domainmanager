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
use Infocom;
use Log;
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
     * pre_item_update on Domain: lock enforcement only. Registrar assignment
     * is no longer a plugin-owned field — see infocomSaved() below.
     *
     * @param  Domain $domain
     * @return void
     */
    public static function domainPreUpdate(Domain $domain): void
    {
        LockEnforcer::domainPreUpdate($domain);
    }

    /**
     * item_add/item_update on Infocom: mirror its `suppliers_id` into the
     * state row's `registrar_suppliers_id` whenever the Infocom row belongs
     * to a Domain. The Registrar is no longer a plugin-owned, independently
     * editable field (was §0.1's "glpi_domains has no supplier column"
     * adjustment) — it now directly follows the native "Supplier" field on
     * the Domain's own Infocom ("Financial and administrative information")
     * tab, read-only in the Domain Manager panel, so there is exactly one
     * place to set it.
     *
     * @param  Infocom $infocom
     * @return void
     */
    public static function infocomSaved(Infocom $infocom): void
    {
        if ($infocom->getField('itemtype') !== Domain::class) {
            return;
        }

        $domains_id   = (int) $infocom->getField('items_id');
        $suppliers_id = max(0, (int) $infocom->getField('suppliers_id'));
        if ($domains_id <= 0) {
            return;
        }

        $state = DomainState::getForDomain($domains_id);
        if ($state !== null) {
            $old_suppliers_id = (int) $state->fields['registrar_suppliers_id'];
            if ($old_suppliers_id !== $suppliers_id) {
                // §9 Phase 14: recompute "Managed" immediately, without
                // waiting for the next sync — the registrar side is
                // re-evaluated live (DomainState::resolvesToActiveDriver()),
                // OR'd with the DNS side's already-stored contribution
                // (dns_status is untouched by an Infocom change, so its
                // resolved-or-not state can't have changed here).
                $dns_resolved  = in_array(
                    $state->fields['dns_status'],
                    [DomainState::STATUS_OK, DomainState::STATUS_ERROR],
                    true
                );
                $update = [
                    'id'                     => $state->getID(),
                    'registrar_suppliers_id' => $suppliers_id,
                    'is_managed'             => (int) (DomainState::resolvesToActiveDriver($suppliers_id) || $dns_resolved),
                ];
                // Whatever registrar_status/registrar_message the state row
                // already held describes the *old* supplier (or no
                // supplier). Leaving it in place would show it, unchanged,
                // right next to the newly-assigned supplier's name — a
                // real "OK"/"Error" that's actually about someone else.
                // Reset it to a status honestly describing the new
                // assignment instead of trusting the sync engine to catch
                // up eventually.
                if ($suppliers_id <= 0) {
                    $update['registrar_status']  = DomainState::STATUS_UNCONFIGURED;
                    $update['registrar_message'] = null;
                } elseif ($old_suppliers_id <= 0) {
                    $update['registrar_status']  = DomainState::STATUS_NEVER;
                    $update['registrar_message'] = null;
                } else {
                    $update['registrar_status']  = DomainState::STATUS_REASSIGNED;
                    $update['registrar_message'] = null;
                }
                $state->update($update);
                self::logRegistrarChange($domains_id, $old_suppliers_id, $suppliers_id);
            }
        } elseif ($suppliers_id > 0) {
            (new DomainState())->add([
                'domains_id'             => $domains_id,
                'registrar_suppliers_id' => $suppliers_id,
                'is_managed'             => (int) DomainState::resolvesToActiveDriver($suppliers_id),
            ]);
            self::logRegistrarChange($domains_id, 0, $suppliers_id);
        }
    }

    /**
     * item_add/item_update on Domain: keep the state row's cached
     * Punycode/ASCII form of the domain's name (`name_ascii`) in sync
     * (§9 Phase 17 "Domain identity header") — independent of Infocom/
     * registrar assignment, since `glpi_domains.name` stores the Unicode
     * form and MySQL can't compute the Punycode form itself, this cache is
     * the only way a pasted-in Punycode string can be matched by search.
     * Creates the state row when one doesn't exist yet (a brand-new Domain
     * has no registrar/sync history), mirroring infocomSaved()'s own
     * create-if-missing branch.
     *
     * @param  Domain $domain
     * @return void
     */
    public static function domainSaved(Domain $domain): void
    {
        $domains_id = (int) $domain->getID();
        if ($domains_id <= 0) {
            return;
        }

        $name       = (string) ($domain->fields['name'] ?? '');
        $name_ascii = $name !== '' ? IdnNormalizer::toAscii($name) : '';

        $state = DomainState::getForDomain($domains_id);
        if ($state !== null) {
            if ($state->fields['name_ascii'] !== $name_ascii) {
                $state->update([
                    'id'         => $state->getID(),
                    'name_ascii' => $name_ascii,
                ]);
            }
        } elseif ($name_ascii !== '') {
            (new DomainState())->add([
                'domains_id' => $domains_id,
                'name_ascii' => $name_ascii,
            ]);
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
            $old_suppliers_id === 0 && $new_suppliers_id > 0 => sprintf(__('Registrar supplier set to %s', 'domainmanager'), $new_name),
            $old_suppliers_id > 0 && $new_suppliers_id === 0 => __('Registrar supplier cleared', 'domainmanager'),
            default => sprintf(__('Registrar supplier changed from %1$s to %2$s', 'domainmanager'), $old_name, $new_name),
        };

        // §9 Phase 14 addendum "Search UI cleanup": id_search_option 0
        // (blank "field" column) instead of a dummy search option that only
        // cluttered the Search UI — the plugin name is prefixed into the
        // message text instead.
        Log::history($domains_id, Domain::class, [0, '', '[' . __('Domain Manager', 'domainmanager') . '] ' . $message]);
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
        ImportLock::deleteForItem(DomainRecord::class, $records_id);
    }

    /**
     * Hooks::POST_INIT callback (§9 Phase 15 addendum). Strips any
     * `$_SESSION['glpisearch'][<itemtype>]['criteria']`/`['sort']` entry
     * still referencing one of the three dummy/duplicate search-option IDs
     * dropped in the Phase 14 addendum "Search UI cleanup" (Supplier 9401,
     * Domain 9402/9403) — `Installer::pruneStaleSearchOptionCriteria()`
     * only rewrites *persisted* `glpi_savedsearches` rows at install/upgrade
     * time, which can't reach a reference living purely in
     * `$_SESSION['glpisearch']` (`QueryBuilder::manageParams()` writes
     * whatever criteria a request used back into session on every request,
     * including its own first-touch default), and that's what live testing
     * confirmed the actual source was (`glpi_savedsearches`/
     * `glpi_savedsearches_users` were already empty). Runs on every page
     * load, early enough (session initialized, but before any
     * `front/*.php` calls `QueryBuilder::manageParams()`) to be effective;
     * a no-op once no session carries a stale ID anymore.
     *
     * @return void
     */
    public static function scrubStaleSearchSessionCriteria(): void
    {
        if (!isset($_SESSION['glpisearch']) || !is_array($_SESSION['glpisearch'])) {
            return;
        }

        $stale_ids_by_itemtype = [
            'Supplier' => [9401],
            'Domain'   => [9402, 9403],
        ];

        foreach ($stale_ids_by_itemtype as $itemtype => $stale_ids) {
            if (!isset($_SESSION['glpisearch'][$itemtype]) || !is_array($_SESSION['glpisearch'][$itemtype])) {
                continue;
            }

            $state = &$_SESSION['glpisearch'][$itemtype];

            if (isset($state['criteria']) && is_array($state['criteria'])) {
                foreach ($state['criteria'] as $key => $criterion) {
                    if (isset($criterion['field']) && in_array((int) $criterion['field'], $stale_ids, true)) {
                        unset($state['criteria'][$key]);
                    }
                }
                $state['criteria'] = array_values($state['criteria']);
            }

            if (isset($state['sort']) && in_array((int) $state['sort'], $stale_ids, true)) {
                $state['sort'] = 0;
            }

            unset($state);
        }
    }
}
