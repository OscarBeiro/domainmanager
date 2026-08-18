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

namespace GlpiPlugin\Domainmanager\Dto;

use InvalidArgumentException;

/**
 * Immutable, validated DNS zone record as returned by a provider API;
 * drivers must catch InvalidArgumentException and skip the record
 */
final class ZoneRecord
{
    public const TYPES = ['A', 'AAAA', 'ALIAS', 'CNAME', 'MX', 'NS', 'PTR', 'SOA', 'SRV', 'TXT', 'CAA'];

    public const MAX_NAME_LENGTH = 255;
    public const MAX_DATA_LENGTH = 65000;

    public readonly string $type;
    public readonly string $name;
    public readonly string $data;
    public readonly int $ttl;
    public readonly string $remoteId;
    public readonly ?bool $isProxied;
    public readonly ?string $comment;
    public readonly ?bool $isTtlAuto;

    /**
     * $isProxied is a genuine tri-state (§9 Phase 7 addendum "Searchable
     * 'Proxy Status' Field for CDN-Proxied Records"): true/false only when
     * the provider's own API says this specific record is CDN-proxyable
     * (Cloudflare's `proxiable` flag, checked per-record rather than a
     * hardcoded type list — see CloudflareDriver::fetchZoneRecords()),
     * `null` for every non-Cloudflare driver and any record Cloudflare
     * itself reports as not proxyable.
     *
     * $comment is the provider's own free-text per-record note (Cloudflare
     * only; `null` for every driver that has no such concept) — kept out
     * of getHash() deliberately, same as $isProxied, since it never governs
     * add/update/trash reconciliation, only the separate comment sync.
     *
     * $isTtlAuto records the *semantic* "this TTL means automatic", not the
     * raw number — the number itself (a literal `1`) already lives in $ttl.
     * Driver-supplied, deliberately never derived here from `$ttl === 1`:
     * that sentinel is Cloudflare-specific (confirmed against Cloudflare's
     * own API docs, §9 research "persist proxy addresses + TTL-auto flag"),
     * and a `1`-second TTL from a driver with no such convention (IONOS,
     * Dinahosting) is a literal one-second TTL, not "automatic". Kept out
     * of getHash() for the same reason $isProxied is: it never governs
     * add/update/trash reconciliation.
     */
    public function __construct(string $type, string $name, string $data, int $ttl, string $remoteId = '', ?bool $isProxied = null, ?string $comment = null, ?bool $isTtlAuto = null)
    {
        $type = strtoupper(trim($type));
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unsupported record type '$type'");
        }

        $name     = self::sanitizeString($name, self::MAX_NAME_LENGTH, 'name');
        $data     = self::sanitizeString($data, self::MAX_DATA_LENGTH, 'data');
        $remoteId = self::sanitizeString($remoteId, 255, 'remote id', true);

        if ($name === '' || $data === '') {
            throw new InvalidArgumentException('Record name and data are mandatory');
        }

        if ($ttl < 0 || $ttl > 2147483647) {
            throw new InvalidArgumentException("TTL $ttl out of range");
        }

        if ($comment !== null) {
            $comment = self::sanitizeString($comment, 100, 'comment', true);
            if ($comment === '') {
                $comment = null;
            }
        }

        $this->type      = $type;
        $this->name      = $name;
        $this->data      = $data;
        $this->ttl       = $ttl;
        $this->remoteId  = $remoteId;
        $this->isProxied = $isProxied;
        $this->comment   = $comment;
        $this->isTtlAuto = $isTtlAuto;
    }

    /**
     * Identity when the provider exposes no stable record id.
     *
     * TTL is deliberately excluded, same reasoning as $isProxied/$comment
     * above: providers report inconsistent or "auto" (0) TTLs across syncs
     * for the same record, which produced duplicate rows instead of updates.
     * TTL is still applied on update once a match is found by this hash.
     *
     * @return string sha256 of type|name|data
     */
    public function getHash(): string
    {
        return hash('sha256', $this->type . '|' . $this->name . '|' . $this->data);
    }

    /**
     * Phase 63 (ARCHITECTURE.md §15.5): core's canonical `data` shape for MX
     * is `"<priority> <target>."` — a space-joined pair with the target
     * carrying a trailing dot (`is_fqdn`, per §11.5/§15.5's own table). All
     * three drivers already join priority and target with a single space in
     * that order, matching the shape; what wasn't confirmed per-provider is
     * whether the target itself always arrives with the trailing dot core's
     * own hand-entry form would add. Rather than guess per provider (no live
     * account access for this phase), this normalizes it unconditionally —
     * a no-op if the dot is already there, added if it's missing — so every
     * driver's imported MX ends up in exactly the form a hand-created record
     * through GLPI's own form would produce, which is what stops
     * `record_hash` from churning against a manually entered duplicate.
     *
     * Content with no space (malformed/priority-less) is returned unchanged
     * rather than guessed at.
     *
     * @param  string $content e.g. "10 mail.example.com" or "10 mail.example.com."
     * @return string
     */
    public static function normalizeMxContent(string $content): string
    {
        $spacePos = strpos($content, ' ');
        if ($spacePos === false) {
            return $content;
        }

        $priority = substr($content, 0, $spacePos);
        $target   = substr($content, $spacePos + 1);

        if ($target === '' || str_ends_with($target, '.')) {
            return $content;
        }

        return $priority . ' ' . $target . '.';
    }

    /**
     * @param  string $value
     * @param  int    $max_length
     * @param  string $what
     * @param  bool   $allow_empty
     * @return string
     */
    private static function sanitizeString(string $value, int $max_length, string $what, bool $allow_empty = false): string
    {
        $value = trim($value);

        if ($value === '' && $allow_empty) {
            return '';
        }

        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException("Record $what is not valid UTF-8");
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            throw new InvalidArgumentException("Record $what contains control characters");
        }

        if (mb_strlen($value) > $max_length) {
            throw new InvalidArgumentException("Record $what exceeds $max_length characters");
        }

        return $value;
    }
}
