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

use Domain;
use Glpi\Controller\AbstractController;
use GlpiPlugin\Domainmanager\Service\SyncEngine;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Update Now" endpoint (§6.3): runs the sync synchronously for one domain.
 * URL: POST /plugins/domainmanager/sync/{domains_id}
 * CSRF is enforced by the core CheckCsrfListener (X-Glpi-Csrf-Token header).
 */
class SyncController extends AbstractController
{
    #[Route('/sync/{domains_id}', name: 'domainmanager_sync', methods: ['POST'], requirements: ['domains_id' => '\d+'])]
    public function __invoke(int $domains_id): Response
    {
        if (!Session::haveRight('domain', UPDATE)) {
            return new JsonResponse(['error' => __('You do not have permission to synchronize domains', 'domainmanager')], 403);
        }

        $domain = new Domain();
        if (!$domain->getFromDB($domains_id)) {
            return new JsonResponse(['error' => __('Domain not found', 'domainmanager')], 404);
        }

        if (!$domain->can($domains_id, UPDATE)) {
            return new JsonResponse(['error' => __('You do not have permission to synchronize this domain', 'domainmanager')], 403);
        }

        $result = (new SyncEngine())->sync($domain);

        return new JsonResponse($result);
    }
}
