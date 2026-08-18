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

namespace GlpiPlugin\Domainmanager\Tests\Service;

use GlpiPlugin\Domainmanager\DomainState;
use GlpiPlugin\Domainmanager\Service\DomainStatusResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Real bug precedent this guards against: a status constant that gained no
 * matching label/class entry rendered blank instead of failing loudly (see
 * TESTING-dev.md). Reflection over DomainState's own STATUS_* constants means
 * a newly-added constant with a forgotten label/class fails this test
 * immediately, instead of silently shipping a blank badge.
 */
final class DomainStatusResolverTest extends TestCase
{
    /**
     * @return string[]
     */
    private static function getStatusConstantValues(): array
    {
        $reflection = new ReflectionClass(DomainState::class);
        $values     = [];
        foreach ($reflection->getConstants() as $name => $value) {
            if (str_starts_with($name, 'STATUS_')) {
                $values[] = $value;
            }
        }

        return $values;
    }

    public function testEveryStatusConstantHasALabel(): void
    {
        $labels = DomainStatusResolver::getStatusLabels();
        foreach (self::getStatusConstantValues() as $value) {
            $this->assertArrayHasKey($value, $labels, "Missing label for status '$value'");
            $this->assertNotSame('', $labels[$value]);
        }
    }

    public function testEveryStatusConstantHasACssClass(): void
    {
        $classes = DomainStatusResolver::getStatusClasses();
        foreach (self::getStatusConstantValues() as $value) {
            $this->assertArrayHasKey($value, $classes, "Missing CSS class for status '$value'");
            $this->assertNotSame('', $classes[$value]);
        }
    }
}
