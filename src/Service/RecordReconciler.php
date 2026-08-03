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
use GlpiPlugin\Domainmanager\Dto\ZoneRecord;
use GlpiPlugin\Domainmanager\Exception\BlastRadiusExceededException;
use GlpiPlugin\Domainmanager\ImportedRecord;
use GlpiPlugin\Domainmanager\ImportLock;
use GlpiPlugin\Domainmanager\LockEnforcer;
use Session;
use Throwable;

/**
 * Idempotent reconciliation of provider zone records into glpi_domainrecords.
 * Only plugin-owned rows (tracked in the ownership map) are ever touched;
 * upstream-vanished records are moved to GLPI's native trash bin
 * (`DomainRecord::delete()`, soft-delete) rather than a plugin-invented
 * comment-marker convention, and restored (`DomainRecord::restore()`) if
 * they reappear (§5.4, revised — supersedes the earlier comment-marker
 * design, §addendum "Vanished Records Go to the Native Trash Bin")
 */
class RecordReconciler
{
    public function __construct(private SyncLogger $logger = new SyncLogger())
    {
    }

    /**
     * Record type ids missing from the session profile's manageable types
     * (§0.4 native gate; empty when running as cron or profile allows all)
     *
     * @return string[] type names the current session cannot write
     */
    public function getUnmanageableTypeNames(): array
    {
        if (Session::isCron()) {
            return [];
        }

        $managed = $_SESSION['glpiactiveprofile']['managed_domainrecordtypes'] ?? [];
        if (!is_array($managed)) {
            $managed = [];
        }
        if ($managed === [-1]) {
            return [];
        }

        $missing = [];
        foreach ($this->resolveTypeIds() as $name => $type_id) {
            if (!in_array($type_id, $managed, true)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * Reconcile upstream records into the domain's native records
     *
     * @param  Domain                              $domain
     * @param  ZoneRecord[]                        $records upstream snapshot
     * @param  DnsRecordCommentSyncInterface|null  $commentDriver same driver
     *         `$records` was fetched from, only if it can push a comment
     *         back upstream (§9 Phase 49) — null for every driver without
     *         that capability, in which case comment sync is download-only
     *         (seeding a newly-created record from the provider's comment).
     * @param  bool  $force ARCHITECTURE.md §15.3 Phase 55: skip the
     *         blast-radius guard below and apply the trash bin moves
     *         regardless of how many records that touches — set only by an
     *         explicit operator override of a run that previously tripped
     *         {@see BlastRadiusExceededException} (§ requires explicit
     *         operator action to proceed).
     * @return array{added: int, updated: int, restored: int, trashed: int, unchanged: int}
     * @throws BlastRadiusExceededException
     */
    public function reconcile(Domain $domain, array $records, ?DnsRecordCommentSyncInterface $commentDriver = null, bool $force = false): array
    {
        // Reconciliation is by definition a plugin-owned write: make sure
        // LockEnforcer never strips it, even when called outside SyncEngine
        $previous = LockEnforcer::$sync_in_progress;
        LockEnforcer::$sync_in_progress = true;
        try {
            return $this->doReconcile($domain, $records, $commentDriver, $force);
        } finally {
            LockEnforcer::$sync_in_progress = $previous;
        }
    }

    /**
     * @param  Domain                              $domain
     * @param  ZoneRecord[]                        $records
     * @param  DnsRecordCommentSyncInterface|null  $commentDriver
     * @param  bool                                $force
     * @return array{added: int, updated: int, restored: int, trashed: int, unchanged: int}
     * @throws BlastRadiusExceededException
     */
    private function doReconcile(Domain $domain, array $records, ?DnsRecordCommentSyncInterface $commentDriver, bool $force = false): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $domains_id = (int) $domain->getID();
        $type_ids   = $this->resolveTypeIds();
        $stats      = ['added' => 0, 'updated' => 0, 'restored' => 0, 'trashed' => 0, 'unchanged' => 0];

        // Load the ownership map: remote_id and hash indexes over unclaimed rows
        $ownership = [];
        $iterator  = $DB->request([
            'FROM'  => ImportedRecord::getTable(),
            'WHERE' => ['domains_id' => $domains_id],
        ]);
        foreach ($iterator as $row) {
            $ownership[(int) $row['id']] = $row;
        }

        $by_remote = [];
        $by_hash   = [];
        foreach ($ownership as $oid => $row) {
            if ($row['remote_id'] !== '') {
                $by_remote[$row['remote_id']] = $oid;
            }
            $by_hash[$row['record_hash']][] = $oid;
        }

        $now     = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $claimed = [];

        foreach ($records as $record) {
            $hash = $record->getHash();

            // Match by remote id first, then by content hash
            $oid = null;
            if ($record->remoteId !== '' && isset($by_remote[$record->remoteId]) && !isset($claimed[$by_remote[$record->remoteId]])) {
                $oid = $by_remote[$record->remoteId];
            } else {
                foreach ($by_hash[$hash] ?? [] as $candidate) {
                    if (!isset($claimed[$candidate])) {
                        $oid = $candidate;
                        break;
                    }
                }
            }

            if ($oid === null) {
                if ($this->createRecord($domain, $record, $type_ids, $now)) {
                    $stats['added']++;
                }
                continue;
            }

            $claimed[$oid] = true;
            $own_row       = $ownership[$oid];

            $native = new DomainRecord();
            if (!$native->getFromDB((int) $own_row['domainrecords_id'])) {
                // Native row vanished outside our hooks: rebuild it
                (new ImportedRecord())->delete(['id' => $oid], true);
                if ($this->createRecord($domain, $record, $type_ids, $now)) {
                    $stats['added']++;
                }
                continue;
            }

            // Native is_deleted (trash bin), not a plugin-tracked flag, is
            // the sole source of truth for "was this stale" — read before
            // any restore()/update() call below changes it.
            $was_trashed = (bool) $native->fields['is_deleted'];

            if ($own_row['record_hash'] !== $hash) {
                // Changed upstream (identified by remote id) — always apply
                // the new data; also restore from the trash bin first if it
                // had gone stale, since a record can reappear with
                // different content too (bucketed as 'updated', matching
                // this branch's pre-existing priority over 'restored').
                if ($was_trashed) {
                    $native->restore(['id' => $native->getID(), '_domainmanager_sync' => true]);
                }
                $native->update([
                    'id'                   => $native->getID(),
                    'name'                 => $record->name,
                    'data'                 => $record->data,
                    'ttl'                  => $record->ttl,
                    'domainrecordtypes_id' => $type_ids[$record->type],
                    '_domainmanager_sync'  => true,
                ]);
                $stats['updated']++;
            } elseif ($was_trashed) {
                $native->restore(['id' => $native->getID(), '_domainmanager_sync' => true]);
                $stats['restored']++;
            } else {
                $stats['unchanged']++;
            }

            // §9 Phase 14: refresh the lock set every sync (whether or not
            // the hash changed this time), mirroring how Domain's own locks
            // are refreshed by every successful registrar sync regardless
            // of whether the values actually changed. Conditional per-field
            // in principle — built from exactly the fields ZoneRecord
            // reports this sync — though today's ZoneRecord DTO has no
            // nullable fields among these four, so in practice this locks
            // the same set every time (see ImportLock::replaceLocks()).
            ImportLock::replaceLocks(
                DomainRecord::class,
                (int) $native->getID(),
                [
                    'name'                 => $record->name,
                    'data'                 => $record->data,
                    'ttl'                  => $record->ttl,
                    'domainrecordtypes_id' => $type_ids[$record->type],
                ],
            );

            // is_proxied is refreshed on every sync regardless of which
            // branch above ran: unlike type/name/data/ttl, a proxy toggle
            // can change without the record's own content changing at all,
            // so it must never be gated behind the hash-changed branch.
            $imported = new ImportedRecord();
            $imported->update([
                'id'          => $oid,
                'remote_id'   => $record->remoteId,
                'record_hash' => $hash,
                'last_seen'   => $now,
                'is_proxied'  => self::toNullableInt($record->isProxied),
            ]);

            $this->reconcileComment($domain, $native, $record, $commentDriver);
        }

        // ARCHITECTURE.md §15.3 Phase 55: a successful fetch of the wrong or
        // empty zone (mis-scoped credential, a provider returning an empty
        // page mid-pagination) parses as a valid snapshot and would
        // otherwise reach the trash loop below exactly like a genuinely
        // emptied zone would — count what that loop is about to do and
        // refuse before it touches the DB if it crosses either configured
        // threshold. Computed here, after the match loop above has already
        // populated $claimed, and before any trash-bin mutation runs.
        if (!$force) {
            $would_trash = 0;
            $total_owned = 0;
            foreach ($ownership as $oid => $own_row) {
                $native = DomainRecord::getById((int) $own_row['domainrecords_id']);
                if ($native === false || (bool) $native->fields['is_deleted']) {
                    continue;
                }
                $total_owned++;
                if (!isset($claimed[$oid])) {
                    $would_trash++;
                }
            }

            $max_count   = Config::getBlastRadiusMaxCount();
            $max_percent = Config::getBlastRadiusMaxPercent();
            $percent     = $total_owned > 0 ? ($would_trash / $total_owned) * 100 : ($would_trash > 0 ? 100 : 0);

            if ($would_trash > 0 && ($would_trash > $max_count || $percent > $max_percent)) {
                throw new BlastRadiusExceededException(
                    sprintf(
                        __('Synchronization refused: this run would move %1$d of %2$d owned record(s) (%3$d%%) to the trash bin, above the configured blast-radius guard threshold (max %4$d records or %5$d%%). No change was applied. Force this synchronization only after confirming the upstream zone genuinely emptied.', 'domainmanager'),
                        $would_trash,
                        $total_owned,
                        (int) round($percent),
                        $max_count,
                        $max_percent,
                    ),
                    $would_trash,
                    $total_owned,
                );
            }
        }

        // Ownership rows not seen upstream anymore: move to the native
        // trash bin, never hard-delete (§5.4). Still "Managed" throughout —
        // is_managed on the ImportedRecord row is untouched here.
        foreach ($ownership as $oid => $own_row) {
            if (isset($claimed[$oid])) {
                continue;
            }

            $native = new DomainRecord();
            if (!$native->getFromDB((int) $own_row['domainrecords_id'])) {
                continue;
            }

            if ((bool) $native->fields['is_deleted']) {
                // Already trashed from a previous sync — nothing new to do,
                // and re-calling delete() would just add repeat noise.
                continue;
            }

            $native->delete(['id' => $native->getID(), '_domainmanager_sync' => true]);
            $stats['trashed']++;
        }

        return $stats;
    }

    /**
     * Two-way comment sync (§9 Phase 49, per user request — GLPI's own
     * `comment` is always the SSOT): downloads the provider's comment only
     * to seed a local field that's currently empty; any other mismatch
     * (local has a value the provider disagrees with, including the
     * provider having gone empty while GLPI still has one) is resolved by
     * pushing the local value back up. A record type the configured driver
     * never returns a comment for (`$record->comment === null`, e.g. every
     * non-Cloudflare driver) is left alone entirely — there's nothing to
     * reconcile against. Best-effort: a push failure is logged, never
     * allowed to fail the whole sync over one record's comment.
     *
     * @param  Domain                             $domain
     * @param  DomainRecord                       $native
     * @param  ZoneRecord                         $record
     * @param  DnsRecordCommentSyncInterface|null $commentDriver
     * @return void
     */
    private function reconcileComment(Domain $domain, DomainRecord $native, ZoneRecord $record, ?DnsRecordCommentSyncInterface $commentDriver): void
    {
        if ($record->comment === null) {
            return;
        }

        $local  = trim((string) ($native->fields['comment'] ?? ''));
        $remote = trim($record->comment);

        if ($local === $remote) {
            return;
        }

        if ($local === '' && $remote !== '') {
            $native->update(['id' => $native->getID(), 'comment' => $remote]);
            return;
        }

        if ($commentDriver === null || $record->remoteId === '') {
            return;
        }

        try {
            $commentDriver->pushComment((string) $domain->fields['name'], $record->remoteId, $local);
        } catch (Throwable $e) {
            $this->logger->detail(
                'Failed to push DNS record comment upstream for domain #' . $domain->getID() . ': ' . $e->getMessage(),
            );
        }
    }

    /**
     * @param  Domain             $domain
     * @param  ZoneRecord         $record
     * @param  array<string, int> $type_ids
     * @param  string             $now
     * @return bool
     */
    private function createRecord(Domain $domain, ZoneRecord $record, array $type_ids, string $now): bool
    {
        $native = new DomainRecord();
        $records_id = $native->add([
            'domains_id'           => $domain->getID(),
            'name'                 => $record->name,
            'data'                 => $record->data,
            'ttl'                  => $record->ttl,
            'domainrecordtypes_id' => $type_ids[$record->type],
            'entities_id'          => $domain->fields['entities_id'],
            'is_recursive'         => $domain->fields['is_recursive'],
            'comment'              => $record->comment ?? '',
            // Tells DnsRecordWriteback::onPreAdd() this is a local mirror of
            // a record just read FROM the provider, not a genuine
            // user-initiated create — see that guard's docblock (§ live
            // bug, 2026-08-03: without it, this fired on every reconciler
            // add outside an actual cron run and tried to push the record
            // straight back to the provider it came from).
            '_domainmanager_sync'  => true,
        ]);

        if (!$records_id) {
            $this->logger->detail(
                'Failed to create ' . $record->type . ' record "' . $record->name . '" for domain #' . $domain->getID(),
            );
            return false;
        }

        $imported = new ImportedRecord();
        $imported->add([
            'domainrecords_id' => $records_id,
            'domains_id'       => $domain->getID(),
            'remote_id'        => $record->remoteId,
            'record_hash'      => $record->getHash(),
            'last_seen'        => $now,
            // Set once at creation and never changed afterward — stays 1
            // through updated/trashed/restored, the whole time this
            // ownership row exists (§addendum "Searchable 'Managed' Field
            // on Domain Records"); only a real purge removes the row
            // (HookHandler::domainRecordPurged(), ITEM_PURGE only, never
            // fires for the soft-delete this reconciler itself performs).
            'is_managed'       => 1,
            'is_proxied'       => self::toNullableInt($record->isProxied),
        ]);

        // §9 Phase 14: same conditional per-field lock set as the update
        // path above.
        ImportLock::replaceLocks(
            DomainRecord::class,
            (int) $records_id,
            [
                'name'                 => $record->name,
                'data'                 => $record->data,
                'ttl'                  => $record->ttl,
                'domainrecordtypes_id' => $type_ids[$record->type],
            ],
        );

        return true;
    }

    /**
     * Never pass a native PHP bool into a CommonDBTM input array for a
     * tinyint/bool column — it silently persists as NULL instead of 1/0
     * (§0.10, caught during Phase 7a). `null` stays `null` (genuinely "not
     * applicable"), true/false become 1/0.
     *
     * @param  bool|null $value
     * @return int|null
     */
    private static function toNullableInt(?bool $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * Record type name => id, creating missing types idempotently
     *
     * @return array<string, int>
     */
    private function resolveTypeIds(): array
    {
        $ids = [];
        foreach (ZoneRecord::TYPES as $name) {
            $type = new DomainRecordType();
            if ($type->getFromDBByCrit(['name' => $name])) {
                $ids[$name] = (int) $type->getID();
            } else {
                $ids[$name] = (int) $type->add([
                    'name'         => $name,
                    'entities_id'  => 0,
                    'is_recursive' => 1,
                ]);
            }
        }

        return $ids;
    }
}
