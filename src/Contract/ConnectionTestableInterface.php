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

use GlpiPlugin\Domainmanager\Dto\ConnectionTestResult;

/**
 * On-demand connection diagnostics (§3.5), independent of the sync pipelines.
 * One flat method per driver class (not one class per capability) since
 * every concrete driver in this plugin already implements multiple pipeline
 * interfaces in a single class; the result set reflects only the capabilities
 * actually meaningful to test for that driver (e.g. Cloudflare reports only
 * 'dns' — see CloudflareDriver).
 */
interface ConnectionTestableInterface
{
    /**
     * @param  array<string, string> $credentials Credential payload to test
     *         (may be unsaved/live form values, not necessarily persisted)
     * @return array<string, ConnectionTestResult> keyed by capability
     *         ('registrar' and/or 'dns')
     */
    public function testConnection(array $credentials): array;
}
