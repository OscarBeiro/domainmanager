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

use GlpiPlugin\Domainmanager\TldExtractor;
use PHPUnit\Framework\TestCase;

final class TldExtractorTest extends TestCase
{
    public function testSimpleTld(): void
    {
        $this->assertSame('com', TldExtractor::extract('example.com'));
    }

    public function testMultiPartTld(): void
    {
        $this->assertSame('co.uk', TldExtractor::extract('sub.example.co.uk'));
    }

    public function testNonAsciiTld(): void
    {
        $this->assertSame('gal', TldExtractor::extract('ticgal.gal'));
    }

    public function testEmptyStringReturnsEmptyString(): void
    {
        $this->assertSame('', TldExtractor::extract(''));
    }

    public function testUnresolvableSuffixReturnsEmptyString(): void
    {
        // Not in the PSL's ICANN section at all (a private/internal-only
        // pseudo-TLD) — TldExtractor::extract()'s own documented contract
        // is to fail closed to '', not throw, per its docblock.
        $this->assertSame('', TldExtractor::extract('nonexistent.internal'));
    }

    public function testMalformedInputReturnsEmptyString(): void
    {
        $this->assertSame('', TldExtractor::extract('notadomain'));
    }
}
