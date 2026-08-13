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

use GlpiPlugin\Domainmanager\Driver\IonosDriver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * extractError()/extractDomainsApiError() are private and pure (no I/O),
 * tested via reflection. Covers the same 401/403 collapsing-into-one-fixed-
 * sentence issue as CloudflareDriverErrorMappingTest, for the IONOS driver's
 * two distinct error envelope shapes.
 */
final class IonosDriverErrorMappingTest extends TestCase
{
    private function callPrivateStatic(string $method, array $args): array
    {
        $reflection = new ReflectionMethod(IonosDriver::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$args);
    }

    public function testExtractErrorReadsGatewayMessageEnvelope(): void
    {
        [$code, $message] = $this->callPrivateStatic('extractError', ['{"message":"Invalid API key"}']);

        $this->assertNull($code);
        $this->assertSame('Invalid API key', $message);
    }

    public function testExtractErrorFallsBackToRawBodyWhenUnstructured(): void
    {
        [$code, $message] = $this->callPrivateStatic('extractError', ['plain text error']);

        $this->assertNull($code);
        $this->assertSame('plain text error', $message);
    }

    public function testExtractDomainsApiErrorReadsApplicationLevelArrayEnvelope(): void
    {
        $body = '[{"code":"NOT_FOUND","message":"Domain not found"}]';

        [$code, $message] = $this->callPrivateStatic('extractDomainsApiError', [json_decode($body, true), $body]);

        $this->assertSame('NOT_FOUND', $code);
        $this->assertSame('Domain not found', $message);
    }

    public function testExtractDomainsApiErrorReadsGatewayMessageEnvelope(): void
    {
        $body = '{"message":"Invalid API key"}';

        [$code, $message] = $this->callPrivateStatic('extractDomainsApiError', [json_decode($body, true), $body]);

        $this->assertNull($code);
        $this->assertSame('Invalid API key', $message);
    }
}
