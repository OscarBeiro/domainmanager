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
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT'];

    public const MAX_NAME_LENGTH = 255;
    public const MAX_DATA_LENGTH = 65000;

    public readonly string $type;
    public readonly string $name;
    public readonly string $data;
    public readonly int $ttl;
    public readonly string $remoteId;
    public readonly ?bool $isProxied;

    /**
     * $isProxied is a genuine tri-state (§9 Phase 7 addendum "Searchable
     * 'Proxy Status' Field for CDN-Proxied Records"): true/false only when
     * the provider's own API says this specific record is CDN-proxyable
     * (Cloudflare's `proxiable` flag, checked per-record rather than a
     * hardcoded type list — see CloudflareDriver::fetchZoneRecords()),
     * `null` for every non-Cloudflare driver and any record Cloudflare
     * itself reports as not proxyable.
     */
    public function __construct(string $type, string $name, string $data, int $ttl, string $remoteId = '', ?bool $isProxied = null)
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

        $this->type      = $type;
        $this->name      = $name;
        $this->data      = $data;
        $this->ttl       = $ttl;
        $this->remoteId  = $remoteId;
        $this->isProxied = $isProxied;
    }

    /**
     * Identity when the provider exposes no stable record id
     *
     * @return string sha256 of type|name|data|ttl
     */
    public function getHash(): string
    {
        return hash('sha256', $this->type . '|' . $this->name . '|' . $this->data . '|' . $this->ttl);
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
