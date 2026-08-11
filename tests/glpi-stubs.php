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

// Minimal GLPI stand-ins for pure unit tests. Only what the tested units
// actually touch — anything else failing loudly is a feature (it means a
// test started depending on GLPI behaviour that isn't modelled here).

define('PLUGIN_DOMAINMANAGER_VERSION', 'test');

function __(string $str, string $domain = 'glpi'): string
{
    return $str;
}

/**
 * Just enough of GLPI core's CommonDBTM for DomainState's class declaration
 * to load (DomainStatusResolverTest needs DomainState::STATUS_* constants,
 * which requires the class itself to be parseable). DomainState's own
 * methods are never called from a unit test, so no CRUD behavior is
 * stubbed here — only what's needed for `extends CommonDBTM` to resolve.
 */
class CommonDBTM
{
    /** @var array<string, mixed> */
    public $fields = [];
}
