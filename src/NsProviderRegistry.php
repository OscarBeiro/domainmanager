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

use GlpiPlugin\Domainmanager\Service\PluginLogger;
use Plugin;

/**
 * Loads and matches the versioned NS host -> DNS provider registry
 * (resources/ns-providers.json, contributor-maintained; §4)
 */
class NsProviderRegistry
{
    public const PROVIDER_UNKNOWN = 'Unknown';

    /**
     * @var array<int, array{name: string, patterns: string[], driver?: string}>|null
     */
    private static ?array $providers = null;

    /**
     * Validated registry entries, in file order
     *
     * @return array<int, array{name: string, patterns: string[], driver?: string}>
     */
    public static function getProviders(): array
    {
        if (self::$providers !== null) {
            return self::$providers;
        }

        self::$providers = [];

        $path = self::getRegistryPath();
        if ($path === null || !is_readable($path)) {
            PluginLogger::error('NS provider registry not readable');
            return self::$providers;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['providers']) || !is_array($data['providers'])) {
            PluginLogger::error('NS provider registry is malformed JSON');
            return self::$providers;
        }

        foreach ($data['providers'] as $index => $entry) {
            if (!self::isValidEntry($entry)) {
                PluginLogger::error("NS provider registry entry #$index skipped (invalid shape)");
                continue;
            }

            $provider = [
                'name'     => $entry['name'],
                'patterns' => array_values($entry['patterns']),
            ];
            if (isset($entry['driver'])) {
                $provider['driver'] = $entry['driver'];
            }
            self::$providers[] = $provider;
        }

        return self::$providers;
    }

    /**
     * Match NS hosts against the registry (first entry in file order wins)
     *
     * @param  string[] $ns_hosts as returned by the NS lookup
     * @return array{name: string, patterns: string[], driver?: string}|null null = provider unknown
     */
    public static function match(array $ns_hosts): ?array
    {
        $hosts = [];
        foreach ($ns_hosts as $host) {
            $host = strtolower(rtrim(trim((string) $host), '.'));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }
        if ($hosts === []) {
            return null;
        }

        foreach (self::getProviders() as $provider) {
            foreach ($provider['patterns'] as $pattern) {
                foreach ($hosts as $host) {
                    if (fnmatch(strtolower($pattern), $host)) {
                        return $provider;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Reset the in-memory cache (tests)
     *
     * @return void
     */
    public static function resetCache(): void
    {
        self::$providers = null;
    }

    /**
     * @return string|null
     */
    private static function getRegistryPath(): ?string
    {
        $dir = Plugin::getPhpDir('domainmanager');

        return $dir === false ? null : $dir . '/resources/ns-providers.json';
    }

    /**
     * @param  mixed $entry
     * @return bool
     */
    private static function isValidEntry($entry): bool
    {
        if (
            !is_array($entry)
            || !isset($entry['name']) || !is_string($entry['name']) || trim($entry['name']) === ''
            || !isset($entry['patterns']) || !is_array($entry['patterns']) || $entry['patterns'] === []
        ) {
            return false;
        }

        foreach ($entry['patterns'] as $pattern) {
            if (!is_string($pattern) || trim($pattern) === '') {
                return false;
            }
        }

        if (isset($entry['driver'])) {
            if (
                !is_string($entry['driver'])
                || $entry['driver'] === DriverRegistry::DRIVER_NONE
                || !DriverRegistry::isValidDriver($entry['driver'])
            ) {
                return false;
            }
        }

        return true;
    }
}
