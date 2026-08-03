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

class DriverRegistry
{
    public const DRIVER_NONE        = 'none';
    public const DRIVER_CLOUDFLARE  = 'cloudflare';
    public const DRIVER_IONOS       = 'ionos';
    public const DRIVER_DINAHOSTING = 'dinahosting';

    /**
     * Hardcoded list of driver keys
     *
     * @return string[]
     */
    public static function getAvailableDrivers(): array
    {
        return [
            self::DRIVER_NONE,
            self::DRIVER_CLOUDFLARE,
            self::DRIVER_IONOS,
            self::DRIVER_DINAHOSTING,
        ];
    }

    /**
     * isValidDriver
     *
     * @param  string $driver
     * @return bool
     */
    public static function isValidDriver(string $driver): bool
    {
        return in_array($driver, self::getAvailableDrivers(), true);
    }

    /**
     * Driver key => display label (for the supplier tab select)
     *
     * @return array<string, string>
     */
    public static function getDriverLabels(): array
    {
        return [
            self::DRIVER_NONE        => __('None (no API integration)', 'domainmanager'),
            self::DRIVER_CLOUDFLARE  => 'Cloudflare',
            self::DRIVER_IONOS       => 'IONOS',
            self::DRIVER_DINAHOSTING => 'Dinahosting',
        ];
    }

    /**
     * Credential fields expected by a driver
     *
     * @param  string $driver
     * @return array<string, array{label: string, secret: bool, required?: bool}>
     */
    public static function getCredentialFields(string $driver): array
    {
        switch ($driver) {
            case self::DRIVER_CLOUDFLARE:
                return [
                    // Required so the token is unambiguously scoped to one
                    // Cloudflare Account rather than "whatever zones the
                    // creating user happens to have access to" (§addendum
                    // "Switch Cloudflare Driver to Account-Scoped API
                    // Tokens") — used both to filter the zone lookup
                    // (`account.id=` query param) and directly as the
                    // registrar API's account path segment.
                    'account_id' => ['label' => __('Account ID', 'domainmanager'), 'secret' => false, 'required' => true],
                    'token'      => ['label' => __('API Token', 'domainmanager'), 'secret' => true],
                ];
            case self::DRIVER_IONOS:
                return [
                    'key'    => ['label' => __('API Key', 'domainmanager'), 'secret' => true],
                    'secret' => ['label' => __('API Secret', 'domainmanager'), 'secret' => true],
                ];
            case self::DRIVER_DINAHOSTING:
                return [
                    'user'     => ['label' => __('Username', 'domainmanager'), 'secret' => false],
                    'password' => ['label' => __('Password', 'domainmanager'), 'secret' => true],
                ];
        }

        return [];
    }

    /**
     * Credential fields for every driver (for the supplier tab JS toggle)
     *
     * @return array<string, array<string, array{label: string, secret: bool, required?: bool}>>
     */
    public static function getAllCredentialFields(): array
    {
        $fields = [];
        foreach (self::getAvailableDrivers() as $driver) {
            $fields[$driver] = self::getCredentialFields($driver);
        }

        return $fields;
    }

    /**
     * Capabilities a driver's connection test actually reports (§3.5).
     * Cloudflare only reports 'dns' (its registrar API needs a specific
     * domain name, unknown at credential-test time); Dinahosting reports
     * both from a single account-wide auth check; IONOS reports both but
     * only 'dns' is a real check — 'registrar' still reports "not
     * implemented" in `IonosDriver::testConnection()` even though
     * `fetchLifecycle()` (the actual sync pipeline) is a real
     * implementation against the Domains API — adding a real registrar
     * connection-test probe was out of scope for that change, not blocked
     * by missing documentation (§3.9).
     *
     * @param  string $driver
     * @return string[] subset of ['registrar', 'dns']
     */
    public static function getTestableCapabilities(string $driver): array
    {
        return match ($driver) {
            self::DRIVER_CLOUDFLARE                    => ['dns'],
            self::DRIVER_IONOS, self::DRIVER_DINAHOSTING => ['registrar', 'dns'],
            default                                      => [],
        };
    }

    /**
     * Phase 62 (ARCHITECTURE.md §15.4): the minimum TTL a driver's write API
     * actually accepts, so `DnsRecordWriteback::sanitizeInputs()` can floor
     * to the real per-provider value instead of a bare hardcoded literal.
     *
     * - **IONOS: 60, confirmed live** (§11.9 — probing the API directly
     *   returned HTTP 400 below it; not asserted anywhere in the published
     *   schema itself).
     * - **Cloudflare: 60**, per Cloudflare's own public API documentation
     *   (a DNS record's `ttl` field accepts `1` for "Automatic" or an
     *   integer from 60 upward) — not independently live-verified against
     *   this plugin's own account the way IONOS was, so treat as
     *   documentation-sourced, not probe-confirmed.
     * - **Dinahosting: no floor to enforce** — `DinahostingDriver` has no
     *   `ttl` write parameter at all; TTL is entirely server-managed and
     *   the caller's value is discarded (§3.8.1). Returns the same 60 as
     *   the others purely so a shared floor exists to apply before the
     *   driver is known; it has no effect on what Dinahosting actually does.
     *
     * `$ttl === 0` is not specially handled here — the reference IONOS
     * client is documented to omit the field entirely at 0 rather than send
     * a literal 0 (§11.9), but this floor runs before any driver ever sees
     * the value, so 0 never reaches a driver call in the first place.
     *
     * @param  string $driver
     * @return int
     */
    public static function getMinTtl(string $driver): int
    {
        return match ($driver) {
            self::DRIVER_CLOUDFLARE, self::DRIVER_IONOS, self::DRIVER_DINAHOSTING => 60,
            default => 60,
        };
    }

    /**
     * Phase 66 (ARCHITECTURE.md §15.5): whether the driver's provider
     * supports a CNAME at the zone apex — the real shape of the old
     * ALIAS/ANAME request, resolved as a capability flag rather than a new
     * record type, since no driver needs one to express it.
     *
     * - **Cloudflare: true**, per Cloudflare's own documented "CNAME
     *   flattening" at the apex — not independently live-verified against
     *   this plugin's own account.
     * - **IONOS: false** — its apex-alias behaviour was already evaluated
     *   and closed negative on evidence (§11.4); not reopened here.
     * - **Dinahosting: false** — no documented apex-alias support in its
     *   own API docs or the reference client.
     *
     * @param  string $driver
     * @return bool
     */
    public static function supportsApexCname(string $driver): bool
    {
        return $driver === self::DRIVER_CLOUDFLARE;
    }

    /**
     * The single capability whose result the Check Connection UI surfaces as
     * one combined badge/toast (§3.5, §6.1) — this is a display simplification
     * only, both capabilities are still tested and persisted as before. 'dns'
     * is preferred: it's the one capability that's a real, meaningful login
     * probe for every current driver (Cloudflare only has 'dns'; Dinahosting's
     * 'registrar'/'dns' come from the same single auth check anyway; IONOS's
     * 'registrar' connection-test probe is still a "not implemented" stub
     * — deliberately deferred, see getTestableCapabilities() — that would
     * otherwise always mask a real, successful 'dns' result).
     *
     * @param  string $driver
     * @return string|null null when the driver has nothing testable at all
     */
    public static function getPrimaryTestableCapability(string $driver): ?string
    {
        $capabilities = self::getTestableCapabilities($driver);

        if (in_array('dns', $capabilities, true)) {
            return 'dns';
        }

        return $capabilities[0] ?? null;
    }
}
