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
     * @return array<string, array{label: string, secret: bool}>
     */
    public static function getCredentialFields(string $driver): array
    {
        switch ($driver) {
            case self::DRIVER_CLOUDFLARE:
                return [
                    'token' => ['label' => __('API Token', 'domainmanager'), 'secret' => true],
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
     * @return array<string, array<string, array{label: string, secret: bool}>>
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
     * domain name, unknown at credential-test time); IONOS/Dinahosting
     * stubs report both (as "not implemented").
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
}
