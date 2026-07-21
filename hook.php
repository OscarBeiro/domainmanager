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

use GlpiPlugin\Domainmanager\Installer;
use GlpiPlugin\Domainmanager\MassiveActionHandler;
use GlpiPlugin\Domainmanager\Service\PluginLogger;

/**
 * Install the plugin
 *
 * @return bool
 */
function plugin_domainmanager_install(): bool
{
    return Installer::install(new Migration(PLUGIN_DOMAINMANAGER_VERSION));
}

/**
 * Called by GLPI core right after activation succeeds (Plugin::activate(),
 * by naming convention, no registration needed) — writes a deterministic
 * first line to both plugin log files so an admin sees them in Setup >
 * Logs immediately, without having to guess whether logging works before
 * the first sync or connection test runs
 *
 * @return void
 */
function plugin_domainmanager_activate(): void
{
    PluginLogger::activity('Domain Manager activated, logging initialized');
    PluginLogger::ensureErrorLogExists();
}

/**
 * Uninstall the plugin
 *
 * @return bool
 */
function plugin_domainmanager_uninstall(): bool
{
    return Installer::uninstall(new Migration(PLUGIN_DOMAINMANAGER_VERSION));
}

/**
 * Hooks::AUTO_MASSIVE_ACTIONS callback (only invoked because
 * Hooks::USE_MASSIVE_ACTION is set in setup.php) — see MassiveActionHandler
 * (§9 Phase 5.5) for the actual action/processor.
 *
 * @param  string $itemtype
 * @return array<string, string>
 */
function plugin_domainmanager_MassiveActions(string $itemtype): array
{
    return MassiveActionHandler::getActions($itemtype);
}
