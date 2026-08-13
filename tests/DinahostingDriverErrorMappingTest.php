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

use GlpiPlugin\Domainmanager\Driver\DinahostingDriver;
use GlpiPlugin\Domainmanager\Dto\ConnectionTestStatus;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Consistency check: Dinahosting's auth-failure envelope classification
 * (responseCode 2200/2201) now surfaces the API's own code/message through
 * the same shared `formatApiError()` template as Cloudflare/IONOS, instead
 * of its own two bespoke guessed-cause sentences, so all three drivers
 * behave identically here. `classifyEnvelope()` is a private instance
 * method with no I/O of its own, tested via reflection.
 */
final class DinahostingDriverErrorMappingTest extends TestCase
{
    private function classify(array $decoded): array
    {
        $driver     = new DinahostingDriver([]);
        $reflection = new ReflectionMethod(DinahostingDriver::class, 'classifyEnvelope');
        $reflection->setAccessible(true);

        return $reflection->invoke($driver, $decoded, 'System_GetRequestTypes', 200);
    }

    public function testAuthErrorUserSurfacesApiCodeAndMessage(): void
    {
        $result = $this->classify([
            'responseCode' => 2200,
            'message'      => 'Invalid username or password',
        ]);

        $this->assertSame(ConnectionTestStatus::AuthFailed, $result['registrar']->status);
        $this->assertSame('Dinahosting error 2200: Invalid username or password', $result['registrar']->userMessage);
    }

    public function testAuthErrorObjectSurfacesApiCodeAndMessage(): void
    {
        $result = $this->classify([
            'responseCode' => 2201,
            'message'      => 'Object not authorized for this session',
        ]);

        $this->assertSame(ConnectionTestStatus::Forbidden, $result['dns']->status);
        $this->assertSame('Dinahosting error 2201: Object not authorized for this session', $result['dns']->userMessage);
    }
}
