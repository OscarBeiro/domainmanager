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

namespace GlpiPlugin\Domainmanager\Contract;

use GlpiPlugin\Domainmanager\Dto\DiscoveredDomain;
use GlpiPlugin\Domainmanager\Exception\DriverException;

/**
 * Bulk domain discovery (§9 Phase 8): lists every domain visible in a
 * supplier's real registrar account, independent of whether a matching
 * `Domain` item already exists in GLPI. Any driver may implement this —
 * capability is checked structurally (`instanceof`), never via a per-driver
 * allowlist, so the "Import Domains" UI (SupplierTab) appears automatically
 * for whichever driver actually supports it.
 */
interface DomainDiscoveryInterface
{
    /**
     * @return DiscoveredDomain[]
     * @throws DriverException on any failure (message safe to persist/display)
     */
    public function listAccountDomains(): array;
}
