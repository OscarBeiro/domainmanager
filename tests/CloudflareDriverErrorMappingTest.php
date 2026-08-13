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
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the reported bug: a bare 403 with no recognized restriction code
 * used to always claim "lacks DNS:Read permission for this zone", even when
 * that guess was wrong (e.g. a zero-zone account with DNS:Read already
 * granted). describeForbidden()/describeAuthFailure() are private, tested
 * via reflection since they have no I/O dependency of their own.
 */
final class CloudflareDriverErrorMappingTest extends TestCase
{
    private function callPrivateStatic(string $method, array $args): string
    {
        $reflection = new ReflectionMethod(CloudflareDriver::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$args);
    }

    public function testForbiddenWithRecognizedRestrictionCodeKeepsSpecificMessage(): void
    {
        $body = json_encode([
            'success' => false,
            'errors'  => [['code' => 9109, 'message' => 'Cannot use the access token from location: 203.0.113.5']],
        ]);

        $message = $this->callPrivateStatic('describeForbidden', [$body, 403]);

        $this->assertSame(
            'Cloudflare rejected this request due to a token restriction: Cannot use the access token from location: 203.0.113.5',
            $message,
        );
    }

    public function testForbiddenWithUnrecognizedErrorSurfacesApiCodeAndMessage(): void
    {
        // Reported case: a 403 with no restriction code and a real cause
        // that isn't a missing scope at all — the old fallback guessed
        // "lacks DNS:Read permission" here regardless.
        $body = json_encode([
            'success' => false,
            'errors'  => [['code' => 10000, 'message' => 'Authentication error']],
        ]);

        $message = $this->callPrivateStatic('describeForbidden', [$body, 403]);

        $this->assertSame('Cloudflare error 10000: Authentication error', $message);
    }

    public function testForbiddenWithNonJsonBodyFallsBackToHttpStatus(): void
    {
        $message = $this->callPrivateStatic('describeForbidden', ['not json', 403]);

        $this->assertSame('Cloudflare error HTTP 403: HTTP 403', $message);
    }

    public function testAuthFailureSurfacesApiCodeAndMessage(): void
    {
        $body = json_encode([
            'success' => false,
            'errors'  => [['code' => 1000, 'message' => 'Invalid API Token']],
        ]);

        $message = $this->callPrivateStatic('describeAuthFailure', [$body, 401]);

        $this->assertSame('Cloudflare error 1000: Invalid API Token', $message);
    }
}
