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

use CommonDBTM;

/**
 * Short-lived update-conflict row (ARCHITECTURE.md §13, Phase 44):
 * `DnsRecordWriteback::onPreUpdate()` creates one whenever a live re-fetch
 * shows the provider's value has drifted from what GLPI knew, instead of
 * hard-refusing the edit. One open row per `domainrecords_id` at a time —
 * a second drifted submit while one is already pending replaces it rather
 * than stacking (§13.4). This is working state, not an audit trail; the
 * audit trail is the `Log::history()` entry logged on resolution.
 */
class RecordConflict extends CommonDBTM
{
    public static $rightname = 'domain';

    /**
     * {@inheritDoc}
     */
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_domainmanager_recordconflicts';
    }

    /**
     * {@inheritDoc}
     */
    public static function getTypeName($nb = 0)
    {
        return _n('DNS record update conflict', 'DNS record update conflicts', $nb, 'domainmanager');
    }

    /**
     * {@inheritDoc}
     */
    public static function getIcon()
    {
        return 'ti ti-world-cog';
    }

    /**
     * The currently open conflict row for a DomainRecord, if any.
     *
     * @param  int $domainrecords_id
     * @return self|null
     */
    public static function getForDomainRecord(int $domainrecords_id): ?self
    {
        $conflict = new self();
        if ($domainrecords_id > 0 && $conflict->getFromDBByCrit(['domainrecords_id' => $domainrecords_id])) {
            return $conflict;
        }

        return null;
    }

    /**
     * Create a new open conflict row for this DomainRecord, replacing any
     * existing one (§13.4: one open row per record, never stacked).
     *
     * @param  int    $domainrecords_id
     * @param  string $submitted_data
     * @param  int    $submitted_ttl
     * @param  string $live_data
     * @param  int    $live_ttl
     * @return self
     */
    public static function replaceForDomainRecord(
        int $domainrecords_id,
        string $submitted_data,
        int $submitted_ttl,
        string $live_data,
        int $live_ttl,
    ): self {
        self::deleteForDomainRecord($domainrecords_id);

        $conflict = new self();
        $conflict->add([
            'domainrecords_id' => $domainrecords_id,
            'submitted_data'   => $submitted_data,
            'submitted_ttl'    => $submitted_ttl,
            'live_data'        => $live_data,
            'live_ttl'         => $live_ttl,
        ]);

        return $conflict;
    }

    /**
     * Delete the open conflict row for a DomainRecord, if any (used both on
     * resolution/cancellation and as orphan cleanup when the record itself
     * is purged, §13.6 item 4).
     *
     * @param  int $domainrecords_id
     * @return void
     */
    public static function deleteForDomainRecord(int $domainrecords_id): void
    {
        $existing = self::getForDomainRecord($domainrecords_id);
        if ($existing !== null) {
            $existing->delete(['id' => $existing->getID()], true);
        }
    }
}
