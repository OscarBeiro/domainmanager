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
use Supplier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
    public function __invoke(int $domains_id, Request $request): Response
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

        // ARCHITECTURE.md §15.3 Phase 55: explicit operator override of a
        // previous run's sync safety guard refusal — only ever meaningful
        // as a deliberate, one-off re-request from the domain panel's own
        // "Update Now" button after it surfaced STATUS_SYNC_SAFETY_GUARD,
        // never a default.
        $force = (bool) $request->request->getBoolean('force', false);

        $result = (new SyncEngine())->sync($domain, false, $force);

        // §19 Phase 76: resolve the link URL here (not in the JS) so the
        // post-sync provider cell can rebuild the same hyperlink the initial
        // page load renders — mirrors DomainForm.php's dns_supplier lookup,
        // null (no link) whenever the resolved supplier no longer exists.
        $result['dns_supplier_link_url'] = null;
        if ((int) $result['dns_suppliers_id'] > 0) {
            $supplier = new Supplier();
            if ($supplier->getFromDB((int) $result['dns_suppliers_id'])) {
                $result['dns_supplier_link_url'] = $supplier->getLinkURL();
            }
        }

        return new JsonResponse($result);
    }
}
