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
use Log;

/**
 * Sync milestones to the item history + the consolidated plugin log files
 * (never secrets or payloads in history; §3.6)
 */
class SyncLogger
{
    /**
     * Milestone visible in the domain Historical tab, also mirrored to the
     * activity log (domainmanager.log) for a consolidated file-based trail
     *
     * @param  int    $domains_id
     * @param  string $message
     * @return void
     */
    public function milestone(int $domains_id, string $message): void
    {
        Log::history($domains_id, Domain::class, [PLUGIN_DOMAINMANAGER_SO_DOMAIN, '', $message]);
        PluginLogger::activity('Domain #' . $domains_id . ': ' . $message);
    }

    /**
     * Technical detail of a sync failure, to domainmanager-errors.log
     *
     * @param  string $message
     * @return void
     */
    public function detail(string $message): void
    {
        PluginLogger::error($message);
    }

    /**
     * A plain informational activity-log line — not a state-changing
     * milestone (never the Historical tab, to avoid cluttering it on every
     * cron run with no new information) and not a failure (never
     * domainmanager-errors.log). Used both for deliberate skips (nothing
     * attempted, e.g. an inactive resolved supplier) and for recording a
     * fact about what an attempt actually did (e.g. how many records a DNS
     * fetch returned) — so a reader of domainmanager.log can independently
     * confirm "the API call really happened and returned N records" rather
     * than only ever seeing the reconciler's post-hoc diff stats
     * (§addendum "Debug: ... Possible Silent IONOS DNS Failure": the
     * architecture already aborts to an error status before reconciliation
     * on any real fetch failure, so this can't currently mask one — but
     * making that provable from the log itself, not just from reading the
     * code, is worth the one extra line).
     *
     * @param  int    $domains_id
     * @param  string $message
     * @return void
     */
    public function activity(int $domains_id, string $message): void
    {
        PluginLogger::activity('Domain #' . $domains_id . ': ' . $message);
    }

    /**
     * @param  int    $domains_id
     * @param  string $message
     * @return void
     */
    public function skip(int $domains_id, string $message): void
    {
        $this->activity($domains_id, $message);
    }
}
