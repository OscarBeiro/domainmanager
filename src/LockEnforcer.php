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
use GlpiPlugin\Domainmanager\Service\DnsRecordWriteback;
use Infocom;
use Session;

/**
 * Server-side enforcement of the plugin lock layer (§0.3): synced Domain
 * fields, the Domain's Registrar (Infocom's Supplier), and plugin-imported
 * DomainRecords are shielded from users lacking the unlock right.
 * Enforcement fires on every entry point (forms, massive actions, API)
 * because it hooks the model layer.
 */
class LockEnforcer
{
    /**
     * Runtime flag set by SyncEngine (and the Unlink action) so their own
     * writes bypass enforcement; not input-based, so it cannot be forged
     * through a form POST
     */
    public static bool $sync_in_progress = false;

    /**
     * Runtime flag set while a `Domain` delete/purge is in progress, so the
     * cascaded pre_item_delete/pre_item_purge fired on each child
     * `DomainRecord` never pushes a driver deletion or blocks/warns locally
     * (ARCHITECTURE.md Phase 90) — the whole Domain, records included, is
     * being removed from GLPI only, not from the DNS provider.
     */
    private static bool $domain_removal_in_progress = false;

    /**
     * Field on DomainRecord that is always locked when the record is
     * plugin-owned, regardless of what the DNS driver reported this sync —
     * changing it would break the record/domain relationship the plugin
     * itself maintains, the same reasoning as `name` on Domain (§9 Phase 14).
     */
    private const STRUCTURAL_RECORD_FIELD = 'domains_id';

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
                    implode(', ', $stripped),
                ),
                false,
                WARNING,
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
        // Unlike Domain/Infocom, DomainRecord does NOT honour the
        // unlock-imported bypass here: that bypass predates write-back
        // support and was only ever a way to force-edit plugin-owned
        // records locally. Now that edits can be pushed upstream, a
        // privileged user's change must still go through DnsRecordWriteback
        // rather than silently skipping the provider. Only the sync
        // engine's own writes (and cron) bypass.
        if (self::canBypassSync() || !is_array($item->input)) {
            return;
        }

        // §11.7 (Phase 34b): for a writable type on an IONOS-managed,
        // write-capable domain where the profile holds the per-type UPDATE
        // right, DnsRecordWriteback pushes the edit to IONOS (or aborts the
        // save on failure) instead of this method's own unconditional
        // block below. Every other record/type/profile falls through
        // unchanged.
        if (DnsRecordWriteback::onPreUpdate($item)) {
            return;
        }

        if (!ImportedRecord::isPluginOwned((int) $item->getID())) {
            return;
        }

        // §9 Phase 14: conditional per-field locking, mirroring how Domain
        // already works — only fields the most recent reconcile actually
        // wrote are locked (ImportLock::replaceLocks(), called from
        // RecordReconciler), plus the always-locked structural field above.
        $protected = array_merge(
            [self::STRUCTURAL_RECORD_FIELD],
            ImportLock::getLockedFieldNames(DomainRecord::class, (int) $item->getID()),
        );

        $stripped = [];
        foreach ($protected as $field) {
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
                WARNING,
            );
        }
    }

    /**
     * pre_item_update on Infocom: strip a `suppliers_id` change once the
     * Domain it belongs to has a confirmed working registrar match (§9
     * Phase 14) — "confirmed" means the most recent registrar sync actually
     * succeeded against the currently-assigned supplier
     * (`DomainState::STATUS_OK`); a reassignment/error/never-synced state
     * doesn't count as confirmed, so it stays freely editable. Bypassed the
     * same way every other lock is: `unlock_imported`, cron, or the
     * `$sync_in_progress` flag (used by the Unlink action to clear it
     * without requiring the right, mirroring the existing Reassign action's
     * own Supplier-scoped bypass).
     *
     * @param  Infocom $item
     * @return void
     */
    public static function infocomPreUpdate(Infocom $item): void
    {
        if (self::canBypass() || !is_array($item->input)) {
            return;
        }

        if (($item->fields['itemtype'] ?? null) !== Domain::class) {
            return;
        }

        if (
            !array_key_exists('suppliers_id', $item->input)
            || (string) $item->input['suppliers_id'] === (string) $item->fields['suppliers_id']
        ) {
            return;
        }

        $domains_id = (int) $item->fields['items_id'];
        $state      = DomainState::getForDomain($domains_id);
        if ($state === null || $state->fields['registrar_status'] !== DomainState::STATUS_OK) {
            return;
        }

        unset($item->input['suppliers_id']);
        Session::addMessageAfterRedirect(
            __s('The registrar is locked by Domain Manager synchronization and was not changed', 'domainmanager'),
            false,
            WARNING,
        );
    }

    /**
     * pre_item_delete on Domain: marks the removal in progress so the
     * cascaded child DomainRecord delete never touches the driver
     * (Phase 90) — reset by HookHandler::domainDeleted().
     *
     * @param  Domain $item
     * @return void
     */
    public static function domainPreDelete(Domain $item): void
    {
        self::$domain_removal_in_progress = true;
    }

    /**
     * pre_item_purge on Domain: marks the removal in progress so the
     * cascaded child DomainRecord purge never blocks on the per-type
     * PURGE right nor leaves an orphaned row (Phase 90) — reset by
     * HookHandler::domainPurged().
     *
     * @param  Domain $item
     * @return void
     */
    public static function domainPrePurge(Domain $item): void
    {
        self::$domain_removal_in_progress = true;
    }

    /**
     * Resets the Domain-removal-in-progress flag once the delete/purge
     * (and its cascade to child DomainRecords) has finished.
     *
     * @return void
     */
    public static function domainRemovalComplete(): void
    {
        self::$domain_removal_in_progress = false;
    }

    /**
     * pre_item_delete on DomainRecord
     *
     * @param  DomainRecord $item
     * @return void
     */
    public static function domainRecordPreDelete(DomainRecord $item): void
    {
        self::blockRecordRemoval($item, true);
    }

    /**
     * pre_item_purge on DomainRecord
     *
     * @param  DomainRecord $item
     * @return void
     */
    public static function domainRecordPrePurge(DomainRecord $item): void
    {
        self::blockRecordRemoval($item, false);
    }

    /**
     * Cancel deletion/purge of plugin-imported records ($input = false)
     *
     * @param  DomainRecord $item
     * @param  bool         $is_soft_delete true from domainRecordPreDelete
     *                                      (the soft-delete-into-trash
     *                                      path), false from
     *                                      domainRecordPrePurge (the hard
     *                                      purge/empty-trash path) — only
     *                                      the former is eligible for
     *                                      write-back push (§11.7/§11.11)
     * @return void
     */
    private static function blockRecordRemoval(DomainRecord $item, bool $is_soft_delete): void
    {
        // Same reasoning as domainRecordPreUpdate(): the unlock-imported
        // right must not skip the write-back push on DomainRecord — only
        // the sync engine's own removals (and cron) bypass.
        if (self::canBypassSync()) {
            return;
        }

        // Phase 90: this record is only along for the ride because its
        // parent Domain is being deleted/purged — never push a driver
        // deletion and never block/warn locally for a cascaded removal.
        if (self::$domain_removal_in_progress) {
            return;
        }

        // §11.7 (Phase 34b): the soft-delete path only — per §11.11, that's
        // the action that pushes the upstream IONOS delete; the later hard
        // purge (emptying the trash) has nothing left to push and keeps its
        // existing unconditional block below unchanged.
        if ($is_soft_delete && DnsRecordWriteback::onPreDelete($item)) {
            return;
        }

        // Purge (emptying the trash) is gated by the PURGE bit on the
        // per-type write-back right matching this record's type: the
        // provider was already synced when the record was soft-deleted
        // above, so this only affects the local GLPI copy — but it's
        // irreversible on that side, so it's granted independently of
        // the same right's own DELETE bit, per type.
        if (!$is_soft_delete && DnsRecordWriteback::hasPurgeRight($item)) {
            return;
        }

        if (!ImportedRecord::isPluginOwned((int) $item->getID())) {
            return;
        }

        $item->input = false;
        Session::addMessageAfterRedirect(
            $is_soft_delete
                ? __s('This record is imported by Domain Manager synchronization and cannot be removed', 'domainmanager')
                : __s('Purging this record requires the "Purge" right for its DNS record type', 'domainmanager'),
            false,
            ERROR,
        );
    }

    /**
     * Full bypass: Domain/Infocom locks — includes the unlock-imported
     * right, since those fields have no write-back path to a provider.
     *
     * @return bool
     */
    private static function canBypass(): bool
    {
        return self::canBypassSync()
            || Session::haveRight(Profile::UNLOCK_RIGHT, Profile::RIGHT_UNLOCK_IMPORTED);
    }

    /**
     * Sync-only bypass: DomainRecord locks — deliberately excludes the
     * unlock-imported right so a privileged user's create/edit/delete
     * still goes through DnsRecordWriteback instead of skipping the
     * provider push.
     *
     * @return bool
     */
    private static function canBypassSync(): bool
    {
        return self::$sync_in_progress || Session::isCron();
    }
}
