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

namespace GlpiPlugin\Domainmanager\Tests\Dto;

use GlpiPlugin\Domainmanager\Dto\ConnectionTestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectionTestStatusTest extends TestCase
{
    public function testHasExactlyTenCases(): void
    {
        $this->assertCount(10, ConnectionTestStatus::cases());
    }

    #[DataProvider('caseValueProvider')]
    public function testFromValueRoundTrips(ConnectionTestStatus $case, string $value): void
    {
        $this->assertSame($value, $case->value);
        $this->assertSame($case, ConnectionTestStatus::from($value));
    }

    /**
     * @return array<string, array{ConnectionTestStatus, string}>
     */
    public static function caseValueProvider(): array
    {
        return [
            'Success'       => [ConnectionTestStatus::Success, 'success'],
            'AuthFailed'    => [ConnectionTestStatus::AuthFailed, 'auth_failed'],
            'Forbidden'     => [ConnectionTestStatus::Forbidden, 'forbidden'],
            'NotFound'      => [ConnectionTestStatus::NotFound, 'not_found'],
            'RateLimited'   => [ConnectionTestStatus::RateLimited, 'rate_limited'],
            'UpstreamError' => [ConnectionTestStatus::UpstreamError, 'upstream_error'],
            'NetworkError'  => [ConnectionTestStatus::NetworkError, 'network_error'],
            'Timeout'       => [ConnectionTestStatus::Timeout, 'timeout'],
            'UnknownError'  => [ConnectionTestStatus::UnknownError, 'unknown_error'],
            'NotConfigured' => [ConnectionTestStatus::NotConfigured, 'not_configured'],
        ];
    }
}
