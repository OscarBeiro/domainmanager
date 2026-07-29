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

use GlpiPlugin\Domainmanager\Dto\ZoneRecord;
use GlpiPlugin\Domainmanager\Exception\DriverException;

/**
 * DNS provider pipeline: single-record write-back (ARCHITECTURE.md §11).
 * Sibling of DnsPipelineInterface, not an extension of it: fetchRecord()
 * exists only to serve the edit confirmation modal, not reconciliation, and
 * DnsPipelineInterface's job (whole-zone read) is unrelated (§11.8).
 *
 * `$data` on every method uses the exact same convention as
 * ZoneRecord::$data — i.e. whatever fetchZoneRecords()/ZoneRecord already
 * produce for that type (TXT unquoted, CNAME with no trailing dot) — not
 * the literal core `DomainRecordType` field-join convention. Keeping the
 * two aligned means a record created or updated here reads back with the
 * same `$data` a subsequent sync would produce, so `record_hash` does not
 * churn (§11.5). A driver implementing this interface owns translating
 * that shape to and from its own wire format.
 */
interface DnsRecordWriterInterface
{
    /**
     * Types this interface may ever be asked to write. A strict subset of
     * ZoneRecord::TYPES (§11.4) — NS/MX excluded by design, not omission.
     */
    public const WRITABLE_TYPES = ['A', 'AAAA', 'CNAME', 'TXT'];

    /**
     * @param  string $domain FQDN, e.g. "example.com"
     * @param  string $type   one of self::WRITABLE_TYPES
     * @param  string $name   record name, absolute (e.g. "www.example.com")
     * @param  string $data   see interface docblock for convention
     * @param  int    $ttl
     * @return ZoneRecord the created record, remoteId populated from the
     *                     provider's response (no follow-up read, §11.9)
     * @throws DriverException on any failure (message safe to persist)
     */
    public function createRecord(string $domain, string $type, string $name, string $data, int $ttl): ZoneRecord;

    /**
     * @param  string $domain   FQDN, e.g. "example.com"
     * @param  string $remoteId provider-assigned record id
     * @param  string $type     one of self::WRITABLE_TYPES
     * @param  string $name     record name, absolute (e.g. "www.example.com")
     * @param  string $data     see interface docblock for convention
     * @param  int    $ttl
     * @return ZoneRecord the record as the provider reports it after the update
     * @throws DriverException on any failure (message safe to persist)
     */
    public function updateRecord(string $domain, string $remoteId, string $type, string $name, string $data, int $ttl): ZoneRecord;

    /**
     * @param  string $domain   FQDN, e.g. "example.com"
     * @param  string $remoteId provider-assigned record id
     * @return void
     * @throws DriverException on any failure (message safe to persist)
     */
    public function deleteRecord(string $domain, string $remoteId): void;

    /**
     * Single-record live read, used only to populate the edit confirmation
     * modal (§11.9) — never for reconciliation.
     *
     * @param  string $domain   FQDN, e.g. "example.com"
     * @param  string $remoteId provider-assigned record id
     * @return ZoneRecord
     * @throws DriverException on any failure (message safe to persist)
     */
    public function fetchRecord(string $domain, string $remoteId): ZoneRecord;
}
