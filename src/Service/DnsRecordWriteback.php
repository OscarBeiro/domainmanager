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

namespace GlpiPlugin\Domainmanager\Service;

use Domain;
use DomainRecord;
use DomainRecordType;
use GlpiPlugin\Domainmanager\Contract\DnsRecordCommentSyncInterface;
use GlpiPlugin\Domainmanager\Contract\DnsRecordProxyToggleInterface;
use GlpiPlugin\Domainmanager\Contract\DnsRecordWriterInterface;
use GlpiPlugin\Domainmanager\Dto\ZoneRecord;
use GlpiPlugin\Domainmanager\DomainState;
use GlpiPlugin\Domainmanager\DriverFactory;
use GlpiPlugin\Domainmanager\DriverRegistry;
use GlpiPlugin\Domainmanager\Exception\DriverException;
use GlpiPlugin\Domainmanager\ImportedRecord;
use GlpiPlugin\Domainmanager\ImportLock;
use GlpiPlugin\Domainmanager\NsProviderRegistry;
use GlpiPlugin\Domainmanager\Profile;
use GlpiPlugin\Domainmanager\RecordConflict;
use GlpiPlugin\Domainmanager\SupplierConfig;
use Log;
use Session;
use Supplier;
use Throwable;

/**
 * Native-tab write-back to whichever driver a domain's DNS is configured
 * under (ARCHITECTURE.md §11.7, §11.10, Phase 34b — IONOS was the only
 * implementation at the time this was written, generalized in Phase 41),
 * superseding the removed `DnsRecordWriteController` + confirmation-modal
 * design. Called from `hook.php`'s `pre_item_add`/`item_add`/
 * `pre_item_update`/`pre_item_delete` entries on `DomainRecord` — all logic
 * here is a straight port of what the removed controller did per-action,
 * now running inline in GLPI's own native add/edit/delete flow instead of a
 * plugin-rendered panel.
 */
class DnsRecordWriteback
{
    /**
     * Types this feature may ever write. Same subset as
     * DnsRecordWriterInterface::WRITABLE_TYPES (§11.4) — NS/MX excluded by
     * design.
     */
    private const WRITABLE_TYPES = DnsRecordWriterInterface::WRITABLE_TYPES;

    /**
     * Subset of WRITABLE_TYPES a proxy toggle can ever apply to (Cloudflare
     * itself reports this per-record via `proxiable`, but TXT is never
     * proxiable there — checked as a cheap early filter before even looking
     * at ImportedRecord/driver).
     */
    private const PROXIABLE_TYPES = ['A', 'AAAA', 'CNAME'];

    /**
     * ZoneRecord created by pre_item_add's driver call, stashed for the
     * paired item_add (post) hook to attach ImportedRecord/ImportLock/
     * history once the local row has an id. Keyed by spl_object_id() of the
     * same DomainRecord instance GLPI reuses across both hook calls within
     * one add() — never persisted, never touches a second request.
     *
     * @var array<int, ZoneRecord>
     */
    private static array $pendingCreated = [];

    /**
     * pre_item_add on DomainRecord: push createRecord() to the provider before the
     * local row exists, aborting the local add on failure so there is never
     * a local row with no corresponding provider record.
     *
     * @param  DomainRecord $item
     * @return void
     */
    public static function onPreAdd(DomainRecord $item): void
    {
        if (!is_array($item->input)) {
            return;
        }

        $type = self::typeName((int) ($item->input['domainrecordtypes_id'] ?? 0));
        if ($type === null || !in_array($type, self::WRITABLE_TYPES, true)) {
            return;
        }

        $domains_id = (int) ($item->input['domains_id'] ?? 0);
        $state = DomainState::getForDomain($domains_id);
        if ($state === null || !self::isDnsEditable($state)) {
            return;
        }

        if (!self::hasRight($type, CREATE) || Session::isCron()) {
            // Not eligible for write-back: either the profile lacks the
            // per-type right, or this is a cron-driven/sync add, which is
            // never a manual write-back push. Native add proceeds untouched.
            return;
        }

        $domain = new Domain();
        if (!$domain->getFromDB($domains_id)) {
            return;
        }

        $preflightError = self::managedTypesPreflight($type);
        if ($preflightError !== null) {
            self::abort($item, $preflightError);
            return;
        }

        $nsError = self::recheckNameservers($domain, $state);
        if ($nsError !== null) {
            self::abort($item, $nsError);
            return;
        }

        $name = (string) ($item->input['name'] ?? '@');
        $data = (string) ($item->input['data'] ?? '');
        $ttl  = (int) ($item->input['ttl'] ?? 3600);
        self::sanitizeInputs($name, $data, $ttl);
        if (!self::validateInputs($data, $ttl)) {
            self::abort($item, __('Invalid record data', 'domainmanager'));
            return;
        }

        try {
            $driver = self::getWritableDriver($state);
            $zoneName = $domain->fields['name'];
            // §9 Phase 37 addendum (found live, 2026-07-29): this was
            // building "$zoneName.$name" (e.g. "beiro.net.dnss") — backwards.
            // `DnsRecordWriterInterface::createRecord()`'s own docblock
            // already specified the correct convention ("record name,
            // absolute, e.g. www.example.com" — subdomain first); only this
            // call site's construction disagreed with it. A non-apex name is
            // "$name.$zoneName"; an apex record ('@' or empty `name`) is
            // just the zone itself, no label prepended.
            $absoluteName = ($name !== '' && $name !== '@') ? $name . '.' . $zoneName : $zoneName;
            $created = $driver->createRecord($zoneName, $type, $absoluteName, $data, $ttl);
            self::$pendingCreated[spl_object_id($item)] = $created;
            DomainState::recordWriteOutcome($domains_id, true);
        } catch (Throwable $e) {
            $message = $e instanceof DriverException ? $e->getMessage() : __('An error occurred while creating the record at the provider', 'domainmanager');
            if ($e instanceof DriverException && $e->isPermissionDenied) {
                DomainState::recordWriteOutcome($domains_id, false, $message);
            }
            PluginLogger::error("Failed to push new DNS record for domain #$domains_id", $e::class . ': ' . $e->getMessage());
            self::abort($item, sprintf(__('Could not create this record at %s: %s', 'domainmanager'), self::driverLabel(self::configuredDriverName($state)), $message));
        }
    }

    /**
     * item_add (post) on DomainRecord: the local row now has an id — attach
     * the ImportedRecord ownership row, field locks and Historical line
     * using the ZoneRecord stashed by onPreAdd().
     *
     * @param  DomainRecord $item
     * @return void
     */
    public static function onPostAdd(DomainRecord $item): void
    {
        $key = spl_object_id($item);
        if (!isset(self::$pendingCreated[$key])) {
            return;
        }
        $created = self::$pendingCreated[$key];
        unset(self::$pendingCreated[$key]);

        $records_id = (int) $item->getID();
        $domains_id = (int) $item->fields['domains_id'];
        $type = (int) $item->fields['domainrecordtypes_id'];

        $imported = new ImportedRecord();
        $imported->add([
            'domainrecords_id' => $records_id,
            'domains_id'       => $domains_id,
            'remote_id'        => $created->remoteId,
            'record_hash'      => $created->getHash(),
            'last_seen'        => date('Y-m-d H:i:s'),
            'is_managed'       => 1,
            'is_glpi_created'  => 1,
            'is_proxied'       => $created->isProxied !== null ? (int) $created->isProxied : null,
        ]);

        ImportLock::replaceLocks(DomainRecord::class, $records_id, [
            'name'                 => $item->fields['name'],
            'data'                 => $item->fields['data'],
            'ttl'                  => $item->fields['ttl'],
            'domainrecordtypes_id' => $type,
        ]);

        Log::history($domains_id, Domain::class, [0, '', '[Domain Manager] ' . sprintf(
            __('Record created from GLPI: %s %s → %s (TTL %d)', 'domainmanager'),
            self::typeName($type) ?? '?',
            $item->fields['name'] ?: '@',
            $item->fields['data'],
            (int) $item->fields['ttl'],
        ),
        ]);

        self::pushInitialComment($item, $created->remoteId, $domains_id);
    }

    /**
     * A comment typed on the native "New Domain record" form has nowhere to
     * go via `createRecord()` itself (§9 Phase 49 addendum — that call has
     * no comment parameter, kept minimal on purpose, see
     * `DnsRecordCommentSyncInterface`'s own docblock), so it would otherwise
     * sit local-only until the next scheduled sync's `RecordReconciler`
     * push picked it up. Pushed here instead, right after the record (and
     * its remote id) actually exist, so it's live immediately. Best-effort,
     * same non-blocking convention as the other push helpers in this class
     * — a failure here must never undo the creation that already succeeded.
     *
     * @param  DomainRecord $item
     * @param  string       $remoteId
     * @param  int          $domains_id
     * @return void
     */
    private static function pushInitialComment(DomainRecord $item, string $remoteId, int $domains_id): void
    {
        $comment = trim((string) ($item->fields['comment'] ?? ''));
        if ($comment === '' || $remoteId === '') {
            return;
        }

        $state = DomainState::getForDomain($domains_id);
        if ($state === null) {
            return;
        }

        $domain = new Domain();
        if (!$domain->getFromDB($domains_id)) {
            return;
        }

        try {
            $driver = self::getWritableDriver($state);
            if (!$driver instanceof DnsRecordCommentSyncInterface) {
                return;
            }

            $driver->pushComment($domain->fields['name'], $remoteId, $comment);
        } catch (Throwable $e) {
            $message = $e instanceof DriverException ? $e->getMessage() : __('an error occurred', 'domainmanager');
            PluginLogger::error("Failed to push initial DNS record comment #{$item->getID()}", $e::class . ': ' . $e->getMessage());
            Session::addMessageAfterRedirect(
                '[Domain Manager] ' . sprintf(__('Could not set the comment at %s: %s', 'domainmanager'), self::driverLabel(self::configuredDriverName($state)), $message),
                false,
                WARNING,
            );
        }
    }

    /**
     * pre_item_update on DomainRecord: called from
     * `LockEnforcer::domainRecordPreUpdate()` before its own unconditional
     * lock check. Returns true when this record/edit was handled here (the
     * write-back was attempted, whether it aborted the save or is letting
     * it proceed) — the caller must then skip its own lock logic. Returns
     * false when not eligible at all, so the caller falls through to its
     * existing plugin-owned-record lock.
     *
     * @param  DomainRecord $item
     * @return bool
     */
    public static function onPreUpdate(DomainRecord $item): bool
    {
        if (!is_array($item->input)) {
            return false;
        }

        $type = self::typeName((int) $item->fields['domainrecordtypes_id']);
        if ($type === null || !in_array($type, self::WRITABLE_TYPES, true)) {
            return false;
        }

        $domains_id = (int) $item->fields['domains_id'];
        $state = DomainState::getForDomain($domains_id);
        if ($state === null || !self::isDnsEditable($state)) {
            return false;
        }

        if (!self::hasRight($type, UPDATE) || Session::isCron()) {
            return false;
        }

        // Proxy status and comment are pushed independently of data/ttl
        // (§9 Phase 49): neither is a native DomainRecord field write-back
        // otherwise knows how to handle, and either can change without the
        // other, so both are best-effort side pushes here rather than
        // folded into the data/ttl diff-and-conflict machinery below.
        self::pushProxiedIfRequested($item, $type, $state);
        self::pushCommentIfChanged($item, $state);

        // Only a data/ttl change is actually pushed; a no-op update (e.g.
        // only unrelated fields submitted) shouldn't hit the provider.
        if (
            !array_key_exists('data', $item->input)
            && !array_key_exists('ttl', $item->input)
        ) {
            return false;
        }

        $domain = new Domain();
        if (!$domain->getFromDB($domains_id)) {
            return false;
        }

        $preflightError = self::managedTypesPreflight($type);
        if ($preflightError !== null) {
            self::abort($item, $preflightError);
            return true;
        }

        $nsError = self::recheckNameservers($domain, $state);
        if ($nsError !== null) {
            self::abort($item, $nsError);
            return true;
        }

        $data = (string) ($item->input['data'] ?? $item->fields['data']);
        $ttl  = (int) ($item->input['ttl'] ?? $item->fields['ttl']);
        $name = (string) $item->fields['name'];
        self::sanitizeInputs($name, $data, $ttl);
        if (!self::validateInputs($data, $ttl)) {
            self::abort($item, __('Invalid record data', 'domainmanager'));
            return true;
        }

        $imported = ImportedRecord::getForDomainRecord((int) $item->getID());
        if ($imported === null || $imported->fields['remote_id'] === '') {
            self::abort($item, sprintf(__('Record has no remote ID; cannot push to %s', 'domainmanager'), self::driverLabel(self::configuredDriverName($state))));
            return true;
        }

        // §13 (Phase 44): a bypass carries the id of an already-displayed
        // conflict row the user just resolved with "Keep my GLPI value" —
        // scoped to that one row (§13.6 item 3), never a generic
        // skip-the-diff-check flag, so a crafted submission cannot bypass
        // drift-checking on an edit nobody has reviewed.
        $conflictId = (int) ($item->input['_domainmanager_conflict_id'] ?? 0);

        try {
            $driver = self::getWritableDriver($state);

            if ($conflictId > 0) {
                $conflict = RecordConflict::getForDomainRecord((int) $item->getID());
                if ($conflict === null || (int) $conflict->getID() !== $conflictId) {
                    self::abort($item, __('This conflict no longer exists; it may already have been resolved or cancelled. Please retry your edit.', 'domainmanager'));
                    return true;
                }

                // §13.6 item 1: re-verify at resolution time, not just at
                // detection time — the live value can drift again in the
                // window between the conflict being shown and this click.
                try {
                    $live = $driver->fetchRecord($domain->fields['name'], $imported->fields['remote_id']);
                } catch (Throwable) {
                    self::abort($item, sprintf(__('Could not verify the current value at %s before resolving this conflict; please retry.', 'domainmanager'), self::driverLabel(self::configuredDriverName($state))));
                    return true;
                }

                if ($live->data !== $conflict->fields['live_data'] || $live->ttl !== (int) $conflict->fields['live_ttl']) {
                    $conflict->update([
                        'id'        => $conflict->getID(),
                        'live_data' => $live->data,
                        'live_ttl'  => $live->ttl,
                    ]);
                    self::abort($item, sprintf(__('The value at %s has changed again since this conflict was detected; review the updated value before retrying.', 'domainmanager'), self::driverLabel(self::configuredDriverName($state))));
                    return true;
                }

                // Confirmed unchanged since detection: consume the conflict
                // row and proceed with the push below.
                $conflict->delete(['id' => $conflict->getID()], true);
            } else {
                // Live re-fetch-and-diff before update (§11.10): the local
                // mirror is only as fresh as the last sync.
                try {
                    $live = $driver->fetchRecord($domain->fields['name'], $imported->fields['remote_id']);
                    if ($live->data !== $item->fields['data'] || $live->ttl !== (int) $item->fields['ttl']) {
                        $conflict = RecordConflict::replaceForDomainRecord((int) $item->getID(), $data, $ttl, $live->data, $live->ttl);
                        self::abort($item, sprintf(
                            __('The record at %1$s has changed since the last sync. Review the conflict and choose which value to keep: %2$s', 'domainmanager'),
                            self::driverLabel(self::configuredDriverName($state)),
                            self::conflictUrl($conflict),
                        ));
                        return true;
                    }
                } catch (Throwable) {
                    Session::addMessageAfterRedirect(
                        '[Domain Manager] ' . sprintf(__('Could not verify the current value at %s before this update; proceeding with local values.', 'domainmanager'), self::driverLabel(self::configuredDriverName($state))),
                        false,
                        WARNING,
                    );
                }
            }

            $driver->updateRecord($domain->fields['name'], $imported->fields['remote_id'], $type, $name, $data, $ttl);
            DomainState::recordWriteOutcome($domains_id, true);

            ImportLock::replaceLocks(DomainRecord::class, (int) $item->getID(), [
                'name'                 => $name,
                'data'                 => $data,
                'ttl'                  => $ttl,
                'domainrecordtypes_id' => (int) $item->fields['domainrecordtypes_id'],
            ]);

            Log::history($domains_id, Domain::class, [0, '', '[Domain Manager] ' . ($conflictId > 0
                ? sprintf(__('Record update conflict resolved: kept the GLPI value for %s %s — data %s → %s', 'domainmanager'), $type, $name, $item->fields['data'], $data)
                : sprintf(__('Record updated from GLPI: %s %s — data %s → %s', 'domainmanager'), $type, $name, $item->fields['data'], $data)),
            ]);

            return true;
        } catch (Throwable $e) {
            $message = $e instanceof DriverException ? $e->getMessage() : __('An error occurred while updating the record at the provider', 'domainmanager');
            if ($e instanceof DriverException && $e->isPermissionDenied) {
                DomainState::recordWriteOutcome($domains_id, false, $message);
            }
            PluginLogger::error("Failed to push updated DNS record #{$item->getID()} for domain #$domains_id", $e::class . ': ' . $e->getMessage());
            self::abort($item, sprintf(__('Could not update this record at %s: %s', 'domainmanager'), self::driverLabel(self::configuredDriverName($state)), $message));
            return true;
        }
    }

    /**
     * `_domainmanager_proxied` (§9 Phase 49) is a synthetic field, injected
     * as a plain checkbox by `domainrecord_edit_panel.html.twig`'s own JS —
     * never a real DomainRecord column, so there is nothing for
     * LockEnforcer/native save to strip or persist; this is the only place
     * it's ever read. Best-effort: a failure here warns but never aborts
     * the underlying record save (data/ttl may be unrelated to this toggle).
     *
     * @param  DomainRecord $item
     * @param  string       $type
     * @param  DomainState  $state
     * @return void
     */
    private static function pushProxiedIfRequested(DomainRecord $item, string $type, DomainState $state): void
    {
        if (!is_array($item->input) || !array_key_exists('_domainmanager_proxied', $item->input)) {
            return;
        }

        if (!in_array($type, self::PROXIABLE_TYPES, true)) {
            return;
        }

        $imported = ImportedRecord::getForDomainRecord((int) $item->getID());
        if ($imported === null || $imported->fields['remote_id'] === '') {
            return;
        }

        $desired = (bool) $item->input['_domainmanager_proxied'];
        $current = $imported->fields['is_proxied'] === null ? null : (bool) $imported->fields['is_proxied'];
        if ($current === $desired) {
            return;
        }

        $domain = new Domain();
        if (!$domain->getFromDB((int) $item->fields['domains_id'])) {
            return;
        }

        try {
            $driver = self::getWritableDriver($state);
            if (!$driver instanceof DnsRecordProxyToggleInterface) {
                return;
            }

            $updated = $driver->setProxied($domain->fields['name'], $imported->fields['remote_id'], $desired);
            $imported->update([
                'id'         => $imported->getID(),
                'is_proxied' => $updated->isProxied !== null ? (int) $updated->isProxied : null,
            ]);

            Log::history((int) $item->fields['domains_id'], Domain::class, [0, '', '[Domain Manager] ' . sprintf(
                __('Proxy status changed from GLPI: %s %s → %s', 'domainmanager'),
                $type,
                $item->fields['name'] ?: '@',
                $desired ? __('Proxied', 'domainmanager') : __('DNS only', 'domainmanager')
            )]);
        } catch (Throwable $e) {
            $message = $e instanceof DriverException ? $e->getMessage() : __('an error occurred', 'domainmanager');
            PluginLogger::error("Failed to push proxy status for DNS record #{$item->getID()}", $e::class . ': ' . $e->getMessage());
            Session::addMessageAfterRedirect(
                '[Domain Manager] ' . sprintf(__('Could not update proxy status at %s: %s', 'domainmanager'), self::driverLabel(self::configuredDriverName($state)), $message),
                false,
                WARNING,
            );
        }
    }

    /**
     * GLPI's own `comment` field is always the SSOT (§9 Phase 49, per user
     * request): this only ever pushes a local edit up to the provider, never
     * pulls the provider's value down over a local change — the download
     * direction (seeding an empty local comment from the provider) lives
     * entirely in RecordReconciler, driven by ZoneRecord::$comment. Scoped to
     * WRITABLE_TYPES, same as every other write-back path — best-effort,
     * same non-blocking convention as pushProxiedIfRequested() above.
     *
     * @param  DomainRecord $item
     * @param  DomainState  $state
     * @return void
     */
    private static function pushCommentIfChanged(DomainRecord $item, DomainState $state): void
    {
        if (!is_array($item->input) || !array_key_exists('comment', $item->input)) {
            return;
        }

        $imported = ImportedRecord::getForDomainRecord((int) $item->getID());
        if ($imported === null || $imported->fields['remote_id'] === '') {
            return;
        }

        $comment = trim((string) $item->input['comment']);
        if ($comment === trim((string) ($item->fields['comment'] ?? ''))) {
            return;
        }

        $domain = new Domain();
        if (!$domain->getFromDB((int) $item->fields['domains_id'])) {
            return;
        }

        try {
            $driver = self::getWritableDriver($state);
            if (!$driver instanceof DnsRecordCommentSyncInterface) {
                return;
            }

            $driver->pushComment($domain->fields['name'], $imported->fields['remote_id'], $comment);
        } catch (Throwable $e) {
            $message = $e instanceof DriverException ? $e->getMessage() : __('an error occurred', 'domainmanager');
            PluginLogger::error("Failed to push DNS record comment #{$item->getID()}", $e::class . ': ' . $e->getMessage());
            Session::addMessageAfterRedirect(
                '[Domain Manager] ' . sprintf(__('Could not update the comment at %s: %s', 'domainmanager'), self::driverLabel(self::configuredDriverName($state)), $message),
                false,
                WARNING,
            );
        }
    }

    /**
     * pre_item_delete on DomainRecord (soft-delete, §11.11 — this is the
     * action that pushes the upstream deletion, not the later hard purge).
     * Same return-value convention as onPreUpdate().
     *
     * @param  DomainRecord $item
     * @return bool
     */
    public static function onPreDelete(DomainRecord $item): bool
    {
        $type = self::typeName((int) $item->fields['domainrecordtypes_id']);
        if ($type === null || !in_array($type, self::WRITABLE_TYPES, true)) {
            return false;
        }

        $domains_id = (int) $item->fields['domains_id'];
        $state = DomainState::getForDomain($domains_id);
        if ($state === null || !self::isDnsEditable($state)) {
            return false;
        }

        if (!self::hasRight($type, DELETE) || Session::isCron()) {
            return false;
        }

        $domain = new Domain();
        if (!$domain->getFromDB($domains_id)) {
            return false;
        }

        $nsError = self::recheckNameservers($domain, $state);
        if ($nsError !== null) {
            self::abort($item, $nsError);
            return true;
        }

        $imported = ImportedRecord::getForDomainRecord((int) $item->getID());
        if ($imported === null || $imported->fields['remote_id'] === '') {
            // Nothing to push upstream (never synced/created through the
            // plugin) — let the native soft-delete proceed unmodified.
            return false;
        }

        try {
            $driver = self::getWritableDriver($state);
            $driver->deleteRecord($domain->fields['name'], $imported->fields['remote_id']);
            DomainState::recordWriteOutcome($domains_id, true);

            Log::history($domains_id, Domain::class, [0, '', '[Domain Manager] ' . sprintf(
                __('Record deleted from GLPI: %s %s', 'domainmanager'),
                $type,
                $item->fields['name'],
            ),
            ]);

            return true;
        } catch (Throwable $e) {
            $message = $e instanceof DriverException ? $e->getMessage() : __('An error occurred while deleting the record at the provider', 'domainmanager');
            if ($e instanceof DriverException && $e->isPermissionDenied) {
                DomainState::recordWriteOutcome($domains_id, false, $message);
            }
            PluginLogger::error("Failed to push deletion of DNS record #{$item->getID()} for domain #$domains_id", $e::class . ': ' . $e->getMessage());
            self::abort($item, sprintf(__('Could not delete this record at %s: %s', 'domainmanager'), self::driverLabel(self::configuredDriverName($state)), $message));
            return true;
        }
    }

    /**
     * pre_item_restore on DomainRecord (§14.3/Phase 48 bug fix): the paired
     * counterpart to onPreDelete() above — trashing a write-back-managed
     * record pushes a real deleteRecord() upstream, so simply flipping
     * is_deleted back to 0 locally (GLPI's native restore) leaves the
     * provider still missing the record; the next sync then reads that as
     * "vanished upstream" and re-trashes it, which is what made restore look
     * like a no-op. Recreates the record upstream and refreshes the
     * ownership row's remote_id/hash (the old remote_id is gone once
     * deleted, so it cannot simply be reused). Same return-value convention
     * as onPreUpdate()/onPreDelete(): true = handled here (whether it let
     * the restore proceed or aborted it), false = not eligible, caller falls
     * through to native restore unmodified.
     *
     * @param  DomainRecord $item
     * @return bool
     */
    public static function onPreRestore(DomainRecord $item): bool
    {
        $type = self::typeName((int) $item->fields['domainrecordtypes_id']);
        if ($type === null || !in_array($type, self::WRITABLE_TYPES, true)) {
            return false;
        }

        $domains_id = (int) $item->fields['domains_id'];
        $state = DomainState::getForDomain($domains_id);
        if ($state === null || !self::isDnsEditable($state)) {
            return false;
        }

        if (!self::hasRight($type, CREATE) || Session::isCron()) {
            return false;
        }

        $imported = ImportedRecord::getForDomainRecord((int) $item->getID());
        if ($imported === null) {
            // Never plugin-owned — let the native restore proceed unmodified.
            return false;
        }

        $domain = new Domain();
        if (!$domain->getFromDB($domains_id)) {
            return false;
        }

        $nsError = self::recheckNameservers($domain, $state);
        if ($nsError !== null) {
            self::abort($item, $nsError);
            return true;
        }

        $name = (string) $item->fields['name'];
        $data = (string) $item->fields['data'];
        $ttl  = (int) $item->fields['ttl'];
        self::sanitizeInputs($name, $data, $ttl);

        try {
            $driver = self::getWritableDriver($state);
            $zoneName = $domain->fields['name'];
            $absoluteName = ($name !== '' && $name !== '@') ? $name . '.' . $zoneName : $zoneName;
            $created = $driver->createRecord($zoneName, $type, $absoluteName, $data, $ttl);
            DomainState::recordWriteOutcome($domains_id, true);

            $imported->update([
                'id'          => $imported->getID(),
                'remote_id'   => $created->remoteId,
                'record_hash' => $created->getHash(),
                'last_seen'   => date('Y-m-d H:i:s'),
            ]);

            Log::history($domains_id, Domain::class, [0, '', '[Domain Manager] ' . sprintf(
                __('Record restored from GLPI trash and recreated at %s: %s %s → %s (TTL %d)', 'domainmanager'),
                self::driverLabel(self::configuredDriverName($state)),
                $type,
                $name ?: '@',
                $data,
                $ttl,
            ),
            ]);

            return true;
        } catch (Throwable $e) {
            $message = $e instanceof DriverException ? $e->getMessage() : __('An error occurred while recreating the record at the provider', 'domainmanager');
            if ($e instanceof DriverException && $e->isPermissionDenied) {
                DomainState::recordWriteOutcome($domains_id, false, $message);
            }
            PluginLogger::error("Failed to recreate restored DNS record #{$item->getID()} for domain #$domains_id", $e::class . ': ' . $e->getMessage());
            self::abort($item, sprintf(__('Could not recreate this record at %s: %s', 'domainmanager'), self::driverLabel(self::configuredDriverName($state)), $message));
            return true;
        }
    }

    /**
     * Cancel the in-progress native operation, mirroring
     * `LockEnforcer::blockRecordRemoval()`'s own convention.
     *
     * @param  DomainRecord $item
     * @param  string       $reason
     * @return void
     */
    private static function abort(DomainRecord $item, string $reason): void
    {
        $item->input = false;
        Session::addMessageAfterRedirect('[Domain Manager] ' . $reason, false, ERROR);
    }

    /**
     * @param  string $type
     * @param  int    $bit CREATE|UPDATE|DELETE
     * @return bool
     */
    private static function hasRight(string $type, int $bit): bool
    {
        $rights = Profile::getDnsRecordRights();
        if (!isset($rights[$type])) {
            return false;
        }
        return Session::haveRight($rights[$type], $bit);
    }

    /**
     * Whether the current user holds the CREATE bit on at least one
     * per-type DNS write-back right (§11.6/§11.7) — used by
     * `DomainForm::onShowTab()` to decide whether the native "Link a
     * record"/"New Domain record for this item" controls are worth
     * showing on a write-back-managed domain's Records tab: a user who can't
     * create any type via write-back would only get a confusing local-only
     * add that `DnsRecordWriteback::onPreAdd()` may reject outright.
     *
     * @return bool
     */
    public static function userMayCreateAnyType(): bool
    {
        foreach (Profile::getDnsRecordRights() as $type => $right) {
            if (self::hasRight($type, CREATE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the current user holds the PURGE bit on the per-type DNS
     * write-back right matching this record's type — used by
     * `LockEnforcer::blockRecordRemoval()` to gate hard-purging a
     * DomainRecord, one right per type rather than a single flat right
     * (ARCHITECTURE.md §11.6 addendum, matching native GLPI's own
     * DELETE/PURGE distinction).
     *
     * @param  DomainRecord $item
     * @return bool
     */
    public static function hasPurgeRight(DomainRecord $item): bool
    {
        $type = self::typeName((int) ($item->fields['type'] ?? 0));
        if ($type === null) {
            return false;
        }

        return self::hasRight($type, PURGE);
    }

    /**
     * Public wrapper around `isDnsEditable()` for callers outside this
     * class (§11.6 addendum) — e.g. `DomainForm::onShowTab()`, deciding
     * whether the domain's DNS is under write-back at all before
     * even considering hiding the native add controls.
     *
     * @param  DomainState $state
     * @return bool
     */
    public static function isDomainDnsEditable(DomainState $state): bool
    {
        return self::isDnsEditable($state);
    }

    /**
     * Public wrapper around `hasRight()` (§9 Phase 37) — lets a caller check
     * a *specific* right/bit combination (not just "any CREATE"), needed by
     * the custom add panel (per-type CREATE, to build the type dropdown) and
     * the edit-page banner/lock (per-type UPDATE/DELETE for one known type).
     *
     * @param  string $type
     * @param  int    $bit CREATE|UPDATE|DELETE
     * @return bool
     */
    public static function hasTypeRight(string $type, int $bit): bool
    {
        return self::hasRight($type, $bit);
    }

    /**
     * Whether this domain's configured DNS driver can toggle a record's
     * proxy status at all (§9 Phase 49) — used by `DomainForm` to decide
     * whether the edit-panel checkbox is worth injecting; independent of
     * any specific record's type (callers still check PROXIABLE_TYPES
     * themselves via `isProxiableType()`).
     *
     * @param  DomainState $state
     * @return bool
     */
    public static function supportsProxyToggle(DomainState $state): bool
    {
        if (!self::isDnsEditable($state)) {
            return false;
        }

        try {
            return self::getWritableDriver($state) instanceof DnsRecordProxyToggleInterface;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  string $type
     * @return bool
     */
    public static function isProxiableType(string $type): bool
    {
        return in_array($type, self::PROXIABLE_TYPES, true);
    }

    /**
     * Public accessor for `WRITABLE_TYPES` (§9 Phase 37) — the set of
     * `DomainRecordType` names write-back may ever touch, independent of any
     * particular domain/user's rights.
     *
     * @return string[]
     */
    public static function writableTypes(): array
    {
        return self::WRITABLE_TYPES;
    }

    /**
     * Record types the current user may create via write-back on this
     * domain (§9 Phase 37) — empty whenever the domain's DNS isn't under
     * write-back at all, or the user holds none of the four per-type
     * CREATE rights. Backs the custom add panel's type dropdown
     * (`DomainForm::renderRecordWritePanel()`): only ever offering types
     * that will actually succeed, instead of the native form's full
     * type list.
     *
     * @param  int $domains_id
     * @return string[]
     */
    public static function creatableTypesForDomain(int $domains_id): array
    {
        $state = DomainState::getForDomain($domains_id);
        if ($state === null || !self::isDnsEditable($state)) {
            return [];
        }

        $types = [];
        foreach (self::WRITABLE_TYPES as $type) {
            if (self::hasRight($type, CREATE)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Display name of the Supplier backing this domain's DNS write-back
     * (§9 Phase 37) — used purely for the "this creates/updates the record
     * live at <Supplier>" copy; `null` whenever the domain's DNS isn't under
     * write-back (nothing to name).
     *
     * @param  int $domains_id
     * @return string|null
     */
    public static function writableSupplierName(int $domains_id): ?string
    {
        $state = DomainState::getForDomain($domains_id);
        if ($state === null || !self::isDnsEditable($state)) {
            return null;
        }

        $supplier_id = (int) ($state->fields['dns_suppliers_id'] ?? 0);
        if ($supplier_id <= 0) {
            return null;
        }

        $supplier = new Supplier();
        if (!$supplier->getFromDB($supplier_id)) {
            return null;
        }

        return $supplier->fields['name'] ?? null;
    }

    /**
     * Ported from the removed DnsRecordWriteController::isDnsEditable().
     *
     * @param  DomainState $state
     * @return bool
     */
    private static function isDnsEditable(DomainState $state): bool
    {
        $supplier_id = $state->fields['dns_suppliers_id'] ?? 0;
        if ($supplier_id <= 0) {
            return false;
        }

        try {
            $config = SupplierConfig::getForSupplier($supplier_id);
            if ($config === null) {
                return false;
            }
            $driver = DriverFactory::forDns($config);
            return $driver instanceof DnsRecordWriterInterface;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Ported from the removed DnsRecordWriteController::getWritableDriver().
     *
     * @param  DomainState $state
     * @return DnsRecordWriterInterface
     * @throws DriverException
     */
    private static function getWritableDriver(DomainState $state): DnsRecordWriterInterface
    {
        $supplier_id = $state->fields['dns_suppliers_id'] ?? 0;
        $config = SupplierConfig::getForSupplier($supplier_id);
        if ($config === null) {
            throw new DriverException(__('Supplier configuration not found', 'domainmanager'));
        }
        $driver = DriverFactory::forDns($config);
        if (!$driver instanceof DnsRecordWriterInterface) {
            throw new DriverException(__('Driver does not support DNS record write-back', 'domainmanager'));
        }
        return $driver;
    }

    /**
     * §0.4 pre-flight: the acting profile's "manageable domain record
     * types" must include this type, or core's own
     * `DomainRecord::prepareInput()` would silently drop the local write
     * even after a successful provider push. Ported from the removed
     * controller's preflight().
     *
     * @param  string $type
     * @return string|null error message, or null when clear
     */
    private static function managedTypesPreflight(string $type): ?string
    {
        $manageable = $_SESSION['glpiactiveprofile']['managed_domainrecordtypes'] ?? [];
        if (!is_array($manageable)) {
            $manageable = [];
        }

        if ($manageable === [-1]) {
            return null;
        }

        $typeObj = new DomainRecordType();
        if (!$typeObj->getFromDBByCrit(['name' => $type])) {
            return __('Unable to resolve record type', 'domainmanager');
        }

        if (!in_array($typeObj->getID(), $manageable, true)) {
            return sprintf(
                __('Your profile\'s "Manageable domain record types" setting does not include %s', 'domainmanager'),
                $type,
            );
        }

        return null;
    }

    /**
     * Ported from the removed controller's recheckNameservers(). Compares
     * against the domain's own currently-configured DNS driver (via $state),
     * not a hardcoded provider — a re-resolved NS match against any *other*
     * driver than the one actually configured for this domain still means
     * "no longer authoritative" for write-back purposes, whichever driver
     * that configured one happens to be (found live, Phase 41: this
     * previously hardcoded DRIVER_IONOS, which would have wrongly rejected
     * every Cloudflare-managed write).
     *
     * @param  Domain      $domain
     * @param  DomainState $state
     * @return string|null
     */
    private static function recheckNameservers(Domain $domain, DomainState $state): ?string
    {
        $configuredDriver = self::configuredDriverName($state);
        try {
            $resolver = new NsResolver();
            $hosts = $resolver->getNameservers($domain->fields['name']);
            $provider = NsProviderRegistry::match($hosts);
            if ($provider === null || ($provider['driver'] ?? null) !== $configuredDriver) {
                return sprintf(
                    __('Domain nameservers have changed since last sync; cannot verify %s is still authoritative', 'domainmanager'),
                    self::driverLabel($configuredDriver),
                );
            }
        } catch (Throwable) {
            return __('Unable to re-check domain nameservers', 'domainmanager');
        }
        return null;
    }

    /**
     * Driver key currently configured for this domain's DNS supplier, or
     * null if it can't be resolved (§0.4-adjacent — mirrors isDnsEditable()'s
     * own supplier lookup so the two never disagree).
     *
     * @param  DomainState $state
     * @return string|null
     */
    private static function configuredDriverName(DomainState $state): ?string
    {
        $supplier_id = $state->fields['dns_suppliers_id'] ?? 0;
        if ($supplier_id <= 0) {
            return null;
        }
        try {
            $config = SupplierConfig::getForSupplier($supplier_id);
            return $config->fields['api_driver'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Human-readable driver label for user-facing messages (§9 Phase 41) —
     * replaces every message that previously hardcoded "IONOS" literally.
     *
     * @param  string|null $driver
     * @return string
     */
    private static function driverLabel(?string $driver): string
    {
        if ($driver === null) {
            return __('the configured provider', 'domainmanager');
        }
        return DriverRegistry::getDriverLabels()[$driver] ?? $driver;
    }

    /**
     * URL of the conflict-resolution screen for a just-detected conflict
     * (§13.3 item 2's "aborts with a message directing the user to the
     * conflict-resolution screen") — rendered as a clickable link rather
     * than a bare path the user would have to copy/paste.
     *
     * @param  RecordConflict $conflict
     * @return string
     */
    private static function conflictUrl(RecordConflict $conflict): string
    {
        $url = '/plugins/domainmanager/recordconflict/' . $conflict->getID();
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($url, ENT_QUOTES) . '</a>';
    }

    /**
     * Ported from the removed controller's sanitizeInputs().
     *
     * @param  string $name
     * @param  string $data
     * @param  int    $ttl
     * @return void
     */
    private static function sanitizeInputs(string &$name, string &$data, int &$ttl): void
    {
        $name = trim($name);
        $data = trim($data);
        $ttl = max(60, min($ttl, 2147483647));
    }

    /**
     * Ported from the removed controller's validateInputs() (the
     * type/name-specific branch was a no-op there — data emptiness alone
     * governed every type — kept identical here).
     *
     * @param  string $data
     * @param  int    $ttl
     * @return bool
     */
    private static function validateInputs(string $data, int $ttl): bool
    {
        return $data !== '' && $ttl >= 60;
    }

    /**
     * @param  int $typeId
     * @return string|null
     */
    private static function typeName(int $typeId): ?string
    {
        if ($typeId <= 0) {
            return null;
        }
        $typeObj = new DomainRecordType();
        if ($typeObj->getFromDB($typeId)) {
            return $typeObj->fields['name'] ?? null;
        }
        return null;
    }
}
