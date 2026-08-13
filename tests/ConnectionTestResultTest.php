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

use GlpiPlugin\Domainmanager\Dto\ConnectionTestResult;
use PHPUnit\Framework\TestCase;

final class ConnectionTestResultTest extends TestCase
{
    public function testFormatApiErrorUsesProvidersCodeAndMessage(): void
    {
        $message = ConnectionTestResult::formatApiError('Cloudflare', '10000', 'Authentication error', 403);

        $this->assertSame('Cloudflare error 10000: Authentication error', $message);
    }

    public function testFormatApiErrorFallsBackToHttpStatusForMissingCode(): void
    {
        $message = ConnectionTestResult::formatApiError('IONOS', null, 'Some message', 403);

        $this->assertSame('IONOS error HTTP 403: Some message', $message);
    }

    public function testFormatApiErrorFallsBackToHttpStatusForMissingMessage(): void
    {
        $message = ConnectionTestResult::formatApiError('IONOS', '42', null, 403);

        $this->assertSame('IONOS error 42: HTTP 403', $message);
    }

    public function testFormatApiErrorFallsBackToHttpStatusForBothMissing(): void
    {
        $message = ConnectionTestResult::formatApiError('IONOS', null, null, 403);

        $this->assertSame('IONOS error HTTP 403: HTTP 403', $message);
    }

    public function testFormatApiErrorTreatsEmptyStringsAsMissing(): void
    {
        $message = ConnectionTestResult::formatApiError('IONOS', '', '', 500);

        $this->assertSame('IONOS error HTTP 500: HTTP 500', $message);
    }
}
