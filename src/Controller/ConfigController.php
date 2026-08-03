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

namespace GlpiPlugin\Domainmanager\Controller;

use DomainType;
use Glpi\Controller\AbstractController;
use GlpiPlugin\Domainmanager\Config\Config;
use GlpiPlugin\Domainmanager\Service\PluginLogger;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Target of the `config_page` plugin hook (§9 Phase 12) — drops the admin
 * on the plugin's tab of Setup > General, same pattern as the sibling
 * `uxia` plugin's `Controller\ConfigController`.
 */
final class ConfigController extends AbstractController
{
    #[Route('/Config', name: 'domainmanager_config', methods: ['GET'])]
    public function index(): Response
    {
        Session::checkRight(Config::$rightname, UPDATE);

        return new RedirectResponse(self::configTabUrl());
    }

    #[Route('/Config/Save', name: 'domainmanager_config_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        Session::checkRight(Config::$rightname, UPDATE);

        $domaintypes_id = (int) $request->request->get('domaintypes_id', 0);
        if ($domaintypes_id > 0 && !(new DomainType())->getFromDB($domaintypes_id)) {
            $domaintypes_id = 0;
        }

        Config::setDomainTypeId($domaintypes_id);

        PluginLogger::activity(
            $domaintypes_id > 0
                ? "Configuration changed: domain type to apply to imported domains set to DomainType #$domaintypes_id"
                : 'Configuration changed: domain type to apply to imported domains cleared (imported domains will get no type)',
        );

        $read_only_mode = (int) $request->request->get('read_only_mode', 0) === 1;
        if ($read_only_mode !== Config::isReadOnlyMode()) {
            Config::setReadOnlyMode($read_only_mode);
            PluginLogger::activity(
                $read_only_mode
                    ? 'Configuration changed: read-only mode enabled — all outbound DNS write-back is now refused'
                    : 'Configuration changed: read-only mode disabled — outbound DNS write-back resumes per normal rights',
            );
        }

        Session::addMessageAfterRedirect(__s('Configuration saved', 'domainmanager'));

        return new RedirectResponse(self::configTabUrl());
    }

    private static function configTabUrl(): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/front/config.form.php?forcetab='
            . urlencode(Config::class . '$1');
    }
}
