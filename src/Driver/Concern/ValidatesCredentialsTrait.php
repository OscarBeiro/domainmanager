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

namespace GlpiPlugin\Domainmanager\Driver\Concern;

/**
 * Shared pre-flight credential validation for registrar/DNS drivers.
 *
 * Originally Cloudflare-only (missingConfigMessage()); extracted so every
 * driver rejects empty required credential fields the same way, with the
 * same message shape, before attempting any network call.
 */
trait ValidatesCredentialsTrait
{
    /**
     * @param  array<string, string> $credentials
     * @param  array<string, string> $requiredFields map of credential key => human label
     * @return string|null null when all required fields are present and non-empty
     */
    private static function missingConfigMessage(array $credentials, array $requiredFields): ?string
    {
        foreach ($requiredFields as $key => $label) {
            if (trim((string) ($credentials[$key] ?? '')) === '') {
                return sprintf(__('%s is not configured', 'domainmanager'), $label);
            }
        }

        return null;
    }
}
