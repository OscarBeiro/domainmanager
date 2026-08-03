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
use GlpiPlugin\Domainmanager\Config\Config;
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

        $domains_id = (int) ($item->input['domains_id'] ?? 0);
        $type_id    = (int) ($item->input['domainrecordtypes_id'] ?? 0);
        $rawName    = trim((string) ($item->input['name'] ?? '@'));

        // Plugin-wide, driver-independent: refuse a second non-trashed
        // record with the same type+name on a Domain Manager-tracked
        // domain, regardless of which (if any) driver is configured, and
        // regardless of write-back eligibility — this is a data-integrity
        // rule, not a provider-push feature (§ live bug, 2026-08-03: a
        // retry storm caused by a since-fixed Dinahosting bug created real
        // duplicate "manel" A records both upstream and locally; per user
        // request, this is deliberately NOT scoped to Dinahosting only —
        // round-robin multi-record setups some providers could otherwise
        // support are out of scope for Domain Manager). Applied before the
        // `_domainmanager_sync` check below so it also catches a duplicate
        // a reconciler sync would otherwise mirror in locally.
        //
        // `$rawName` alone is NOT what's compared: every stored
        // DomainRecord.name is an absolute FQDN (§ absoluteRecordName()),
        // while `$item->input['name']` here is the raw, still-unqualified
        // label a user types in the add form — comparing them directly
        // never matched (found live 2026-08-03: creating a 2nd "manel"
        // while "manel.dev.gal" already existed sailed straight past this
        // guard, only to be rejected deeper in, by Dinahosting's own
        // driver-specific check, with a less helpful message). Qualify
        // against the domain's own zone name first, same as the actual
        // push below does.
        $checkDomain = new Domain();
        if ($domains_id > 0 && $checkDomain->getFromDB($domains_id) && DomainState::getForDomain($domains_id) !== null) {
            $qualifiedName = self::absoluteRecordName($rawName, $checkDomain->fields['name']);
            $duplicateError = self::duplicateNameError($domains_id, $type_id, $qualifiedName);
            if ($duplicateError !== null) {
                self::abort($item, $duplicateError);
                return;
            }
        }

        if (!empty($item->input['_domainmanager_sync'])) {
            // RecordReconciler creating a local mirror of a record it just
            // read FROM the provider (§ live bug, 2026-08-03: with no such
            // guard, this fired for every reconciler-driven add — outside
            // Session::isCron(), since a manual "Update now" sync isn't a
            // cron run — and tried to push the record straight back to the
            // provider it came from, using its already-absolute name as if
            // it were a raw user-typed label and doubling the zone: e.g.
            // "manel.dev.gal" became "manel.dev.gal.dev.gal"). Never a
            // genuine user-initiated create; nothing to push.
            return;
        }

        $type = self::typeName($type_id);
        if ($type === null || !in_array($type, self::WRITABLE_TYPES, true)) {
            return;
        }

        $state = DomainState::getForDomain($domains_id);
        if ($state === null || !self::isDnsEditable($state)) {
            return;
        }

        $readOnlyError = self::readOnlyModeError();
        if ($readOnlyError !== null) {
            self::abort($item, $readOnlyError);
            return;
        }

        if (!self::hasRight($type, CREATE, $domains_id) || Session::isCron()) {
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
            $absoluteName = self::absoluteRecordName($name, $zoneName);
            $created = $driver->createRecord($zoneName, $type, $absoluteName, $data, $ttl);
            self::$pendingCreated[spl_object_id($item)] = $created;
            DomainState::recordWriteOutcome($domains_id, true);
        } catch (Throwable $e) {
            $message = $e instanceof DriverException ? $e->getMessage() : __('An error occurred while creating the record at the provider', 'domainmanager');
            if ($e instanceof DriverException && $e->isPermissionDenied) {
                DomainState::recordWriteOutcome($domains_id, false, $message);
            }
            PluginLogger::error("Failed to push new DNS record for domain #$domains_id", $e::class . ': ' . $e->getMessage());
            self::logWriteAttempt($domains_id, $type, $name, self::driverLabel(self::configuredDriverName($state)), __('create', 'domainmanager'), false, $message);
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

        $writeState = DomainState::getForDomain($domains_id);
        self::logWriteAttempt(
            $domains_id,
            self::typeName($type) ?? '?',
            $item->fields['name'] ?: '@',
            $writeState !== null ? self::driverLabel(self::configuredDriverName($writeState)) : self::driverLabel(null),
            __('create', 'domainmanager'),
            true,
            sprintf(__('%s → %s (TTL %d)', 'domainmanager'), $item->fields['name'] ?: '@', $item->fields['data'], (int) $item->fields['ttl']),
        );

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

        if (!empty($item->fields['is_deleted'])) {
            // Trashing a write-back-managed record already pushed a real
            // deleteRecord() upstream (§ onPreDelete()) — its remote copy is
            // gone. Saving an edit while still trashed must never attempt
            // to push it (found live 2026-08-03: doing so tried to update a
            // record that no longer exists at the provider). The only
            // write-back action a trashed record can still trigger is
            // onPreRestore() recreating it; a plain edit here is local-only,
            // same as any other native field change on a non-managed item.
            return false;
        }

        // Plugin-wide, driver-independent duplicate guard — see onPreAdd()'s
        // identical check for the full rationale. Effective values fall
        // back to the record's current stored fields for whichever of
        // domain/type/name isn't part of this particular update, since a
        // typical data/ttl-only update touches neither.
        $effectiveDomainsId = (int) ($item->input['domains_id'] ?? $item->fields['domains_id']);
        $effectiveTypeId    = (int) ($item->input['domainrecordtypes_id'] ?? $item->fields['domainrecordtypes_id']);
        $effectiveName      = trim((string) ($item->input['name'] ?? $item->fields['name']));
        if (DomainState::getForDomain($effectiveDomainsId) !== null) {
            $duplicateError = self::duplicateNameError($effectiveDomainsId, $effectiveTypeId, $effectiveName, (int) $item->getID());
            if ($duplicateError !== null) {
                self::abort($item, $duplicateError);
                return true;
            }
        }

        if (!empty($item->input['_domainmanager_sync'])) {
            // RecordReconciler reconciling a local row to match the
            // provider — see onPreAdd()'s identical guard. Never a genuine
            // user-initiated update; nothing to push.
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

        $readOnlyError = self::readOnlyModeError();
        if ($readOnlyError !== null) {
            self::abort($item, $readOnlyError);
            return true;
        }

        if (!self::hasRight($type, UPDATE, $domains_id) || Session::isCron()) {
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

        try {
            $driver = self::getWritableDriver($state);

            // A managed, write-back record's GLPI value is always
            // authoritative — this push overwrites whatever is live at the
            // provider unconditionally, same as name/proxy-toggle/comment
            // edits already do. (Previously did a live re-fetch-and-diff
            // here and refused the edit on drift; removed — for a record
            // GLPI actively manages there's no genuine ambiguity to
            // reconcile, and gating a legitimate edit on a stale/failed
            // fetch was pure downside.)
            $driver->updateRecord($domain->fields['name'], $imported->fields['remote_id'], $type, $name, $data, $ttl);
            DomainState::recordWriteOutcome($domains_id, true);

            ImportLock::replaceLocks(DomainRecord::class, (int) $item->getID(), [
                'name'                 => $name,
                'data'                 => $data,
                'ttl'                  => $ttl,
                'domainrecordtypes_id' => (int) $item->fields['domainrecordtypes_id'],
            ]);

            self::logWriteAttempt(
                $domains_id,
                $type,
                $name,
                self::driverLabel(self::configuredDriverName($state)),
                __('update', 'domainmanager'),
                true,
                sprintf(__('data %s → %s', 'domainmanager'), $item->fields['data'], $data),
            );

            return true;
        } catch (Throwable $e) {
            $message = $e instanceof DriverException ? $e->getMessage() : __('An error occurred while updating the record at the provider', 'domainmanager');
            if ($e instanceof DriverException && $e->isPermissionDenied) {
                DomainState::recordWriteOutcome($domains_id, false, $message);
            }
            PluginLogger::error("Failed to push updated DNS record #{$item->getID()} for domain #$domains_id", $e::class . ': ' . $e->getMessage());
            self::logWriteAttempt($domains_id, $type, $name, self::driverLabel(self::configuredDriverName($state)), __('update', 'domainmanager'), false, $message);
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

            self::logWriteAttempt(
                (int) $item->fields['domains_id'],
                $type,
                $item->fields['name'] ?: '@',
                self::driverLabel(self::configuredDriverName($state)),
                __('proxy toggle', 'domainmanager'),
                true,
                $desired ? __('Proxied', 'domainmanager') : __('DNS only', 'domainmanager'),
            );
        } catch (Throwable $e) {
            $message = $e instanceof DriverException ? $e->getMessage() : __('an error occurred', 'domainmanager');
            PluginLogger::error("Failed to push proxy status for DNS record #{$item->getID()}", $e::class . ': ' . $e->getMessage());
            self::logWriteAttempt(
                (int) $item->fields['domains_id'],
                $type,
                $item->fields['name'] ?: '@',
                self::driverLabel(self::configuredDriverName($state)),
                __('proxy toggle', 'domainmanager'),
                false,
                $message,
            );
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
        if (is_array($item->input) && !empty($item->input['_domainmanager_sync'])) {
            // RecordReconciler trashing a local row because the provider no
            // longer reports it — see onPreAdd()'s identical guard. The
            // record is already gone upstream; nothing to push.
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

        $readOnlyError = self::readOnlyModeError();
        if ($readOnlyError !== null) {
            self::abort($item, $readOnlyError);
            return true;
        }

        if (!self::hasRight($type, DELETE, $domains_id) || Session::isCron()) {
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

            self::logWriteAttempt(
                $domains_id,
                $type,
                $item->fields['name'],
                self::driverLabel(self::configuredDriverName($state)),
                __('delete', 'domainmanager'),
                true,
                __('record removed', 'domainmanager'),
            );

            return true;
        } catch (Throwable $e) {
            $message = $e instanceof DriverException ? $e->getMessage() : __('An error occurred while deleting the record at the provider', 'domainmanager');
            if ($e instanceof DriverException && $e->isPermissionDenied) {
                DomainState::recordWriteOutcome($domains_id, false, $message);
            }
            PluginLogger::error("Failed to push deletion of DNS record #{$item->getID()} for domain #$domains_id", $e::class . ': ' . $e->getMessage());
            self::logWriteAttempt($domains_id, $type, $item->fields['name'], self::driverLabel(self::configuredDriverName($state)), __('delete', 'domainmanager'), false, $message);
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
        // Plugin-wide, driver-independent duplicate guard — see onPreAdd()'s
        // identical check for the full rationale. Checked before the
        // `_domainmanager_sync` bail below too: restoring a trashed record
        // whose type+name another active record already claims (e.g. a
        // second copy that was left active while this one was trashed)
        // would recreate exactly the duplicate situation this guard exists
        // to prevent.
        $domains_id = (int) $item->fields['domains_id'];
        if (DomainState::getForDomain($domains_id) !== null) {
            $duplicateError = self::duplicateNameError(
                $domains_id,
                (int) $item->fields['domainrecordtypes_id'],
                trim((string) $item->fields['name']),
                (int) $item->getID(),
            );
            if ($duplicateError !== null) {
                self::abort($item, $duplicateError);
                return true;
            }
        }

        if (is_array($item->input) && !empty($item->input['_domainmanager_sync'])) {
            // RecordReconciler restoring a local row because the provider
            // still reports it — see onPreAdd()'s identical guard. Never a
            // genuine user-initiated restore; nothing to recreate upstream.
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

        $readOnlyError = self::readOnlyModeError();
        if ($readOnlyError !== null) {
            self::abort($item, $readOnlyError);
            return true;
        }

        if (!self::hasRight($type, CREATE, $domains_id) || Session::isCron()) {
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
            // Unlike onPreAdd()'s raw user-typed label, `$item->fields['name']`
            // is a previously-stored record's name — already an absolute FQDN
            // (same convention onPreUpdate() relies on, passing it to
            // updateRecord() unmodified). Running it through
            // absoluteRecordName() here would append the zone a second time
            // (found live 2026-08-03: restoring an already-absolute non-apex
            // name produced "$name.$zoneName.$zoneName").
            $created = $driver->createRecord($zoneName, $type, $name, $data, $ttl);
            DomainState::recordWriteOutcome($domains_id, true);

            $imported->update([
                'id'          => $imported->getID(),
                'remote_id'   => $created->remoteId,
                'record_hash' => $created->getHash(),
                'last_seen'   => date('Y-m-d H:i:s'),
            ]);

            self::logWriteAttempt(
                $domains_id,
                $type,
                $name,
                self::driverLabel(self::configuredDriverName($state)),
                __('restore', 'domainmanager'),
                true,
                sprintf(__('recreated → %s (TTL %d)', 'domainmanager'), $data, $ttl),
            );

            return true;
        } catch (Throwable $e) {
            $message = $e instanceof DriverException ? $e->getMessage() : __('An error occurred while recreating the record at the provider', 'domainmanager');
            if ($e instanceof DriverException && $e->isPermissionDenied) {
                DomainState::recordWriteOutcome($domains_id, false, $message);
            }
            PluginLogger::error("Failed to recreate restored DNS record #{$item->getID()} for domain #$domains_id", $e::class . ': ' . $e->getMessage());
            self::logWriteAttempt($domains_id, $type, $name, self::driverLabel(self::configuredDriverName($state)), __('restore', 'domainmanager'), false, $message);
            self::abort($item, sprintf(__('Could not recreate this record at %s: %s', 'domainmanager'), self::driverLabel(self::configuredDriverName($state)), $message));
            return true;
        }
    }

    /**
     * `DnsRecordWriterInterface::createRecord()`'s docblock specifies the
     * absolute-name convention ("record name, absolute, e.g.
     * www.example.com" — subdomain first); an apex record is just the zone
     * itself, no label prepended (§9 Phase 37 addendum, found live
     * 2026-07-29, fixed a backwards "$zoneName.$name" construction here).
     *
     * `$name` is only treated as already-apex when it's empty, `@`, or
     * already equal to `$zoneName` — the last case matters because a
     * driver's own record-name normalization (e.g. DinahostingDriver's
     * `qualifyHostname()`) can itself report an apex record's stored `name`
     * as the bare zone name rather than `@`. Missing that case doubled the
     * zone name on restore (found live 2026-08-03: a trashed apex TXT
     * record's name was already "dev.gal", so the old `$name !== '@'`-only
     * check appended the zone again, producing "dev.gal.dev.gal" and a
     * hard Dinahosting rejection).
     *
     * @param  string $name     stored DomainRecord name (absolute, or an
     *                          apex form: '', '@', or the zone itself)
     * @param  string $zoneName the domain's own zone name
     * @return string absolute FQDN
     */
    private static function absoluteRecordName(string $name, string $zoneName): string
    {
        if ($name === '' || $name === '@' || strcasecmp($name, $zoneName) === 0) {
            return $zoneName;
        }

        return $name . '.' . $zoneName;
    }

    /**
     * Plugin-wide, driver-independent duplicate guard: refuses a second
     * non-trashed record sharing `$domains_id`+`$type_id`+`$name` (case-
     * insensitive, matching how every driver's own identity matching
     * already treats names). Deliberately not scoped to any single
     * driver's own limitations (contrast `DinahostingDriver::
     * assertSingleRecordAtName()`, which exists only because that specific
     * API can't target one record among same-name siblings) — a real
     * duplicate is just as meaningless on a driver that could technically
     * store it.
     *
     * @param  int         $domains_id
     * @param  int         $type_id
     * @param  string      $name       already-trimmed
     * @param  int|null    $excludeId  the record itself, when checking an
     *                                 update rather than a fresh create
     * @return string|null a user-facing abort message, or null if clear
     */
    private static function duplicateNameError(int $domains_id, int $type_id, string $name, ?int $excludeId = null): ?string
    {
        if ($domains_id <= 0 || $type_id <= 0 || $name === '') {
            return null;
        }

        $where = [
            'domains_id'           => $domains_id,
            'domainrecordtypes_id' => $type_id,
            'name'                 => $name,
            'is_deleted'           => 0,
        ];
        if ($excludeId !== null) {
            $where['id'] = ['<>', $excludeId];
        }

        if (countElementsInTable(DomainRecord::getTable(), $where) === 0) {
            return null;
        }

        return sprintf(
            // Deliberately not "A %1$s record named..." - reads as "A A
            // record" for type A (found live 2026-08-03 while verifying
            // this guard against a real duplicate).
            __('A record of type %1$s named "%2$s" already exists for this domain; Domain Manager does not allow duplicate records', 'domainmanager'),
            self::typeName($type_id) ?? (string) $type_id,
            $name,
        );
    }

    /**
     * Phase 60 (ARCHITECTURE.md §15.4): the RFC 1034 rule `duplicateNameError()`
     * cannot express — it keys on `domains_id`+`domainrecordtypes_id`+`name`,
     * so a CNAME and an A record at the same name pass it today and produce
     * a broken zone the moment a resolver hits that name. This checks across
     * *all* types at `$name`, not just the one being written:
     * - writing a CNAME: refused if any other record (any type) already
     *   exists at that name;
     * - writing any other type: refused if a CNAME already exists at that
     *   name.
     *
     * @param  int      $domains_id
     * @param  string   $type       type name being written, e.g. 'CNAME'
     * @param  string   $name       already-absolute (§ absoluteRecordName())
     * @param  int|null $excludeId  the record itself, when checking an
     *                              update rather than a fresh create
     * @return string|null a user-facing abort message, or null if clear
     */
    private static function cnameCoexistenceError(int $domains_id, string $type, string $name, ?int $excludeId = null): ?string
    {
        if ($domains_id <= 0 || $name === '') {
            return null;
        }

        $where = [
            'domains_id' => $domains_id,
            'name'       => $name,
            'is_deleted' => 0,
        ];
        if ($excludeId !== null) {
            $where['id'] = ['<>', $excludeId];
        }

        if ($type === 'CNAME') {
            if (countElementsInTable(DomainRecord::getTable(), $where) === 0) {
                return null;
            }
            return sprintf(
                __('"%s" already has another DNS record; a CNAME cannot coexist with any other record type at the same name (RFC 1034)', 'domainmanager'),
                $name,
            );
        }

        $cnameTypeId = self::typeIdByName('CNAME');
        if ($cnameTypeId === null) {
            return null;
        }
        $where['domainrecordtypes_id'] = $cnameTypeId;
        if (countElementsInTable(DomainRecord::getTable(), $where) === 0) {
            return null;
        }
        return sprintf(
            __('"%s" already has a CNAME record; no other record type may coexist with a CNAME at the same name (RFC 1034)', 'domainmanager'),
            $name,
        );
    }

    /**
     * @param  string $name e.g. 'CNAME'
     * @return int|null
     */
    private static function typeIdByName(string $name): ?int
    {
        $typeObj = new DomainRecordType();
        if ($typeObj->getFromDBByCrit(['name' => $name])) {
            return (int) $typeObj->fields['id'];
        }
        return null;
    }

    /**
     * Phase 61 (ARCHITECTURE.md §15.4): RFC 7208 forbids more than one
     * `v=spf1` TXT record at a single owner name — a resolver that finds
     * two must treat SPF as a permanent error for that name, so this is a
     * block, not a warning. Needs a DB query across every TXT record at
     * `$name`, which `RecordValidator` deliberately has no access to (see
     * `RecordValidator::validateTxtContent()`'s docblock) — same shape as
     * `cnameCoexistenceError()` above.
     *
     * @param  int      $domains_id
     * @param  string   $name      already-absolute (§ absoluteRecordName())
     * @param  string   $value     raw, unquoted TXT content being written
     * @param  int|null $excludeId the record itself, when checking an
     *                             update rather than a fresh create
     * @return string|null a user-facing abort message, or null if clear
     */
    private static function spfDuplicateError(int $domains_id, string $name, string $value, ?int $excludeId = null): ?string
    {
        if ($domains_id <= 0 || $name === '' || stripos(trim($value), 'v=spf1') !== 0) {
            return null;
        }

        $txtTypeId = self::typeIdByName('TXT');
        if ($txtTypeId === null) {
            return null;
        }

        $where = [
            'domains_id'           => $domains_id,
            'name'                 => $name,
            'domainrecordtypes_id' => $txtTypeId,
            'is_deleted'           => 0,
            'data'                 => ['LIKE', 'v=spf1%'],
        ];
        if ($excludeId !== null) {
            $where['id'] = ['<>', $excludeId];
        }

        if (countElementsInTable(DomainRecord::getTable(), $where) === 0) {
            return null;
        }

        return sprintf(
            __('"%s" already has an SPF (v=spf1) TXT record; RFC 7208 forbids more than one per name', 'domainmanager'),
            $name,
        );
    }

    /**
     * ARCHITECTURE.md §15 Phase 57: one `Log::history()` line per *attempted*
     * provider write (success or failure), on the Domain, following
     * §3.7.1's convention exactly (`id_search_option = 0`, `"[Domain
     * Manager] "` prefix — no new search option). The line is the event,
     * not the payload: type/name/provider/operation/outcome only, never the
     * full RDATA — `Log::history()` truncates at 255 chars via
     * `mb_substr()`, which would silently mangle e.g. a DKIM `p=` value.
     * The untruncated detail already lives in `domainmanager.log` via the
     * `PluginLogger::error()` call at each failing call site.
     *
     * @param  int    $domains_id
     * @param  string $type
     * @param  string $name
     * @param  string $provider
     * @param  string $operation e.g. __('create')/__('update')/__('delete')
     * @param  bool   $success
     * @param  string $detail    short outcome detail (new value, or error message)
     * @return void
     */
    private static function logWriteAttempt(int $domains_id, string $type, string $name, string $provider, string $operation, bool $success, string $detail): void
    {
        $outcome = $success ? __('succeeded', 'domainmanager') : __('failed', 'domainmanager');
        Log::history($domains_id, Domain::class, [0, '', '[Domain Manager] ' . sprintf(
            __('%1$s %2$s %3$s at %4$s %5$s: %6$s', 'domainmanager'),
            ucfirst($operation),
            $type,
            $name ?: '@',
            $provider,
            $outcome,
            $detail,
        )
        ]);
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
     * ARCHITECTURE.md §15.3 Phase 53: the single choke point for the global
     * write kill switch — checked independently of, and before, any
     * per-type right, so an admin flipping it off is never second-guessed
     * by a user's own rights. Each driver's own writer methods assert the
     * same setting again (§ each Driver's createRecord()/updateRecord()/
     * deleteRecord()), so a future call path into a driver directly (e.g.
     * a new controller) can't bypass this by skipping this class.
     *
     * @return string|null a user-facing abort message naming the setting,
     *                      or null when writes are allowed
     */
    private static function readOnlyModeError(): ?string
    {
        if (!Config::isReadOnlyMode()) {
            return null;
        }

        return __('Domain Manager is in read-only mode (Setup > General > Domain Manager); no DNS record change can be pushed to the provider', 'domainmanager');
    }

    /**
     * §11.6/§8, Phase 52: these per-type rights are plain profile bits, so a
     * bare `Session::haveRight()` alone would hold them over every entity's
     * zones once granted anywhere — a profile granted `Domain Record: TXT`
     * for one entity would otherwise write TXT records on a Domain in any
     * other entity too. `Domain::can()` already enforces entity-awareness
     * for every other action in this plugin (§8's convention); mirrored
     * here directly via `Session::haveAccessToEntity()` since these rights
     * aren't itemtype-scoped GLPI rights that `Domain::can()` itself checks.
     *
     * @param  string $type
     * @param  int    $bit        CREATE|UPDATE|DELETE|PURGE
     * @param  int    $domains_id target Domain whose entity gates this right
     * @return bool
     */
    private static function hasRight(string $type, int $bit, int $domains_id): bool
    {
        $rights = Profile::getDnsRecordRights();
        if (!isset($rights[$type]) || !Session::haveRight($rights[$type], $bit)) {
            return false;
        }

        $domain = new Domain();
        if (!$domain->getFromDB($domains_id)) {
            return false;
        }

        return Session::haveAccessToEntity(
            (int) $domain->fields['entities_id'],
            (bool) ($domain->fields['is_recursive'] ?? false),
        );
    }

    /**
     * Whether the current user holds the CREATE bit on at least one
     * per-type DNS write-back right for this domain's entity (§11.6/§11.7,
     * entity-awareness added Phase 52) — used by `DomainForm::onShowTab()`
     * to decide whether the native "Link a record"/"New Domain record for
     * this item" controls are worth showing on a write-back-managed
     * domain's Records tab: a user who can't create any type via write-back
     * would only get a confusing local-only add that
     * `DnsRecordWriteback::onPreAdd()` may reject outright.
     *
     * @param  int $domains_id
     * @return bool
     */
    public static function userMayCreateAnyType(int $domains_id): bool
    {
        foreach (Profile::getDnsRecordRights() as $type => $right) {
            if (self::hasRight($type, CREATE, $domains_id)) {
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
        // Was reading `$item->fields['type']` — not a DomainRecord field
        // (every other type lookup in this class reads
        // `domainrecordtypes_id`) — so this always resolved to no type and
        // returned false unconditionally, hiding the Purge button even for
        // a user holding the per-type PURGE right (found live 2026-08-03).
        $type = self::typeName((int) ($item->fields['domainrecordtypes_id'] ?? 0));
        if ($type === null) {
            return false;
        }

        return self::hasRight($type, PURGE, (int) ($item->fields['domains_id'] ?? 0));
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
     * @param  int    $bit        CREATE|UPDATE|DELETE
     * @param  int    $domains_id target Domain whose entity gates this right
     *                            (Phase 52)
     * @return bool
     */
    public static function hasTypeRight(string $type, int $bit, int $domains_id): bool
    {
        return self::hasRight($type, $bit, $domains_id);
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
            if (self::hasRight($type, CREATE, $domains_id)) {
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
