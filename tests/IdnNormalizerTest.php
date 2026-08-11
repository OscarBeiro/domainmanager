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

use GlpiPlugin\Domainmanager\IdnNormalizer;
use PHPUnit\Framework\TestCase;

final class IdnNormalizerTest extends TestCase
{
    public function testAsciiDomainRoundTripsUnchanged(): void
    {
        $this->assertSame('example.com', IdnNormalizer::toAscii('example.com'));
        $this->assertSame('example.com', IdnNormalizer::toUnicode('example.com'));
    }

    public function testUnicodeDomainConvertsToPunycode(): void
    {
        // Verified live: idn_to_ascii('viñamoraima.com', IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46)
        $this->assertSame('xn--viamoraima-u9a.com', IdnNormalizer::toAscii('viñamoraima.com'));
    }

    public function testPunycodeDomainConvertsToUnicode(): void
    {
        $this->assertSame('viñamoraima.com', IdnNormalizer::toUnicode('xn--viamoraima-u9a.com'));
    }

    public function testEmptyStringReturnsEmptyString(): void
    {
        $this->assertSame('', IdnNormalizer::toAscii(''));
        $this->assertSame('', IdnNormalizer::toUnicode(''));
    }

    public function testInputIsTrimmedAndLowercased(): void
    {
        $this->assertSame('example.com', IdnNormalizer::toAscii('  EXAMPLE.COM  '));
    }
}
