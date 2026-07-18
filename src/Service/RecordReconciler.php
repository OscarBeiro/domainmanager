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
use GlpiPlugin\Domainmanager\Dto\ZoneRecord;
use GlpiPlugin\Domainmanager\ImportedRecord;
use GlpiPlugin\Domainmanager\LockEnforcer;
use Session;

/**
 * Idempotent reconciliation of provider zone records into glpi_domainrecords.
 * Only plugin-owned rows (tracked in the ownership map) are ever touched;
 * upstream-vanished records are flagged stale, never deleted (§5.4)
 */
class RecordReconciler
{
    private const STALE_MARKER = '[Domain Manager] Not present upstream since ';

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
     * @param  Domain       $domain
     * @param  ZoneRecord[] $records upstream snapshot
     * @return array{added: int, updated: int, restored: int, stale: int, unchanged: int}
     */
    public function reconcile(Domain $domain, array $records): array
    {
        // Reconciliation is by definition a plugin-owned write: make sure
        // LockEnforcer never strips it, even when called outside SyncEngine
        $previous = LockEnforcer::$sync_in_progress;
        LockEnforcer::$sync_in_progress = true;
        try {
            return $this->doReconcile($domain, $records);
        } finally {
            LockEnforcer::$sync_in_progress = $previous;
        }
    }

    /**
     * @param  Domain       $domain
     * @param  ZoneRecord[] $records
     * @return array{added: int, updated: int, restored: int, stale: int, unchanged: int}
     */
    private function doReconcile(Domain $domain, array $records): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $domains_id = (int) $domain->getID();
        $type_ids   = $this->resolveTypeIds();
        $stats      = ['added' => 0, 'updated' => 0, 'restored' => 0, 'stale' => 0, 'unchanged' => 0];

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

            $was_stale = (int) $own_row['is_stale'] === 1;

            if ($own_row['record_hash'] !== $hash) {
                // Changed upstream (identified by remote id)
                $native->update([
                    'id'                   => $native->getID(),
                    'name'                 => $record->name,
                    'data'                 => $record->data,
                    'ttl'                  => $record->ttl,
                    'domainrecordtypes_id' => $type_ids[$record->type],
                    'comment'              => $this->withoutStaleMarker((string) $native->fields['comment']),
                ]);
                $stats['updated']++;
            } elseif ($was_stale) {
                $native->update([
                    'id'      => $native->getID(),
                    'comment' => $this->withoutStaleMarker((string) $native->fields['comment']),
                ]);
                $stats['restored']++;
            } else {
                $stats['unchanged']++;
            }

            $imported = new ImportedRecord();
            $imported->update([
                'id'          => $oid,
                'remote_id'   => $record->remoteId,
                'record_hash' => $hash,
                'last_seen'   => $now,
                'is_stale'    => 0,
            ]);
        }

        // Ownership rows not seen upstream anymore: flag stale (§5.4)
        foreach ($ownership as $oid => $own_row) {
            if (isset($claimed[$oid]) || (int) $own_row['is_stale'] === 1) {
                continue;
            }

            $native = new DomainRecord();
            if ($native->getFromDB((int) $own_row['domainrecords_id'])) {
                $native->update([
                    'id'      => $native->getID(),
                    'comment' => trim(
                        $this->withoutStaleMarker((string) $native->fields['comment'])
                        . "\n" . self::STALE_MARKER . date('Y-m-d')
                    ),
                ]);
            }

            $imported = new ImportedRecord();
            $imported->update(['id' => $oid, 'is_stale' => 1]);
            $stats['stale']++;
        }

        return $stats;
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
        ]);

        if (!$records_id) {
            $this->logger->detail(
                'Failed to create ' . $record->type . ' record "' . $record->name . '" for domain #' . $domain->getID()
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
            'is_stale'         => 0,
        ]);

        return true;
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

    /**
     * @param  string $comment
     * @return string
     */
    private function withoutStaleMarker(string $comment): string
    {
        $lines = array_filter(
            explode("\n", $comment),
            static fn(string $line): bool => !str_starts_with(trim($line), self::STALE_MARKER)
        );

        return trim(implode("\n", $lines));
    }
}
