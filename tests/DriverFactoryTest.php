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

namespace GlpiPlugin\Domainmanager\Tests;

use GlpiPlugin\Domainmanager\Driver\CloudflareDriver;
use GlpiPlugin\Domainmanager\Driver\DinahostingDriver;
use GlpiPlugin\Domainmanager\Driver\IonosDriver;
use GlpiPlugin\Domainmanager\DriverFactory;
use GlpiPlugin\Domainmanager\DriverRegistry;
use GlpiPlugin\Domainmanager\Exception\DriverException;
use PHPUnit\Framework\TestCase;

/**
 * Only DriverFactory::createDriver() — forRegistrar()/forDns()/
 * forDiscovery()/build() take a real SupplierConfig itemtype and are out
 * of scope for a DB-less unit test (see TESTING-dev.md for that coverage).
 * All three concrete drivers' constructors are confirmed trivial property
 * assignments (no network, no side effects), so instantiating them here is
 * safe.
 */
final class DriverFactoryTest extends TestCase
{
    public function testCloudflareKeyReturnsCloudflareDriver(): void
    {
        $this->assertInstanceOf(
            CloudflareDriver::class,
            DriverFactory::createDriver(DriverRegistry::DRIVER_CLOUDFLARE, []),
        );
    }

    public function testIonosKeyReturnsIonosDriver(): void
    {
        $this->assertInstanceOf(
            IonosDriver::class,
            DriverFactory::createDriver(DriverRegistry::DRIVER_IONOS, []),
        );
    }

    public function testDinahostingKeyReturnsDinahostingDriver(): void
    {
        $this->assertInstanceOf(
            DinahostingDriver::class,
            DriverFactory::createDriver(DriverRegistry::DRIVER_DINAHOSTING, []),
        );
    }

    public function testUnknownKeyThrowsDriverException(): void
    {
        $this->expectException(DriverException::class);
        DriverFactory::createDriver('not-a-real-driver', []);
    }

    public function testNoneKeyThrowsDriverException(): void
    {
        $this->expectException(DriverException::class);
        DriverFactory::createDriver(DriverRegistry::DRIVER_NONE, []);
    }
}
