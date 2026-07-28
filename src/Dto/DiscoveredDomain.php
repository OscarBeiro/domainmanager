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

namespace GlpiPlugin\Domainmanager\Dto;

/**
 * One domain as returned by a registrar account's own list call (§9 Phase 8)
 * — no per-domain detail fetch is made just to build this list, so only
 * cheap, already-present fields from that same list response are modeled.
 */
final class DiscoveredDomain
{
    /**
     * @param string      $name         FQDN as returned by the registrar API
     * @param string|null $previewStatus driver-specific raw status string
     *        from the same list response, if any (e.g. a provisioning state);
     *        purely informational preview text, never parsed/relied upon
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $previewStatus = null,
    ) {
    }
}
