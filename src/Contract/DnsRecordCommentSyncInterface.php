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

namespace GlpiPlugin\Domainmanager\Contract;

use GlpiPlugin\Domainmanager\Exception\DriverException;

/**
 * Optional capability: a driver whose provider has no per-record comment/
 * note concept simply doesn't implement this. GLPI's own DomainRecord
 * `comment` field is always the SSOT — this is only ever called to push the
 * GLPI-side value up to the provider (RecordReconciler on drift, and
 * DnsRecordWriteback on a manual edit); reading the provider's comment back
 * down only ever happens when the GLPI side is empty (ZoneRecord::$comment,
 * consumed directly by RecordReconciler, no driver call needed for that
 * direction).
 */
interface DnsRecordCommentSyncInterface
{
    /**
     * @param  string $domain   FQDN, e.g. "example.com"
     * @param  string $remoteId provider-assigned record id
     * @param  string $comment  desired comment (empty string clears it)
     * @return void
     * @throws DriverException on any failure (message safe to persist)
     */
    public function pushComment(string $domain, string $remoteId, string $comment): void;
}
