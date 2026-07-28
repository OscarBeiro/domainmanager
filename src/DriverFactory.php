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

use GlpiPlugin\Domainmanager\Contract\DnsPipelineInterface;
use GlpiPlugin\Domainmanager\Contract\DomainDiscoveryInterface;
use GlpiPlugin\Domainmanager\Contract\RegistrarDriverInterface;
use GlpiPlugin\Domainmanager\Driver\CloudflareDriver;
use GlpiPlugin\Domainmanager\Driver\DinahostingDriver;
use GlpiPlugin\Domainmanager\Driver\IonosDriver;
use GlpiPlugin\Domainmanager\Exception\DriverException;

/**
 * Builds concrete driver instances from a supplier configuration
 * (credentials decrypted via GLPIKey inside SupplierConfig)
 */
class DriverFactory
{
    /**
     * Registrar pipeline driver for a supplier
     *
     * @param  SupplierConfig $config
     * @return RegistrarDriverInterface
     * @throws DriverException when the driver is 'none'/unknown
     */
    public static function forRegistrar(SupplierConfig $config): RegistrarDriverInterface
    {
        $driver = self::build($config);
        if (!$driver instanceof RegistrarDriverInterface) {
            throw new DriverException(
                sprintf(__('Driver %s has no registrar pipeline', 'domainmanager'), $config->fields['api_driver']),
            );
        }

        return $driver;
    }

    /**
     * DNS pipeline driver for a supplier
     *
     * @param  SupplierConfig $config
     * @return DnsPipelineInterface
     * @throws DriverException when the driver is 'none'/unknown
     */
    public static function forDns(SupplierConfig $config): DnsPipelineInterface
    {
        $driver = self::build($config);
        if (!$driver instanceof DnsPipelineInterface) {
            throw new DriverException(
                sprintf(__('Driver %s has no DNS pipeline', 'domainmanager'), $config->fields['api_driver']),
            );
        }

        return $driver;
    }

    /**
     * Domain discovery pipeline driver for a supplier (§9 Phase 8), or null
     * when its configured driver doesn't implement DomainDiscoveryInterface
     * (currently implemented by IonosDriver, CloudflareDriver and
     * DinahostingDriver) or has none/an invalid driver configured.
     * Deliberately nullable rather than throwing,
     * unlike forRegistrar()/forDns(): those are only ever called once a
     * pipeline is already known to exist, while this is used to decide
     * whether to show the "Import Domains" button at all — capability is
     * checked structurally (instanceof), never via a per-driver allowlist.
     *
     * @param  SupplierConfig $config
     * @return DomainDiscoveryInterface|null
     */
    public static function forDiscovery(SupplierConfig $config): ?DomainDiscoveryInterface
    {
        try {
            $driver = self::build($config);
        } catch (DriverException) {
            return null;
        }

        return $driver instanceof DomainDiscoveryInterface ? $driver : null;
    }

    /**
     * Build a driver instance directly from a driver key + credential array,
     * without going through a persisted SupplierConfig (§3.5 — used by
     * ConnectionTestController to test unsaved/live form values).
     *
     * @param  string $driverKey
     * @param  array  $credentials
     * @return object
     * @throws DriverException when the driver is 'none'/unknown
     */
    public static function createDriver(string $driverKey, array $credentials): object
    {
        return match ($driverKey) {
            DriverRegistry::DRIVER_CLOUDFLARE  => new CloudflareDriver($credentials),
            DriverRegistry::DRIVER_IONOS       => new IonosDriver($credentials),
            DriverRegistry::DRIVER_DINAHOSTING => new DinahostingDriver($credentials),
            default => throw new DriverException(
                __('No API driver configured for this supplier', 'domainmanager'),
            ),
        };
    }

    /**
     * @param  SupplierConfig $config
     * @return object
     * @throws DriverException
     */
    private static function build(SupplierConfig $config): object
    {
        $driver_key = (string) ($config->fields['api_driver'] ?? DriverRegistry::DRIVER_NONE);

        return self::createDriver($driver_key, $config->getDecryptedCredentials());
    }
}
