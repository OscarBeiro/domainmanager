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
use Infocom;
use Supplier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One-click "Reassign registrar to X" from the Import Domains modal's
 * mismatch rows (§9 Phase 8): updates the Domain's Infocom `suppliers_id`.
 * `HookHandler::infocomSaved()` (already registered in setup.php for
 * Hooks::ITEM_ADD/ITEM_UPDATE on Infocom::class) picks this up automatically
 * — it both corrects the state row's registrar mirror and logs the change
 * on the Domain's own Historical tab, so nothing extra is needed here.
 *
 * **Auth is deliberately Supplier `can(UPDATE)` only, not `Domain::canUpdate()`**
 * — "same rights check as the credentials tab", per ARCHITECTURE.md §9
 * Phase 8's own explicit design. This is a documented, pre-approved scope
 * decision, not an oversight: someone who can manage a Supplier's API
 * config can redirect a Domain's registrar link through this action even
 * without Domain edit rights.
 * URL: POST /plugins/domainmanager/domainreassign/{domains_id}
 */
class DomainRegistrarReassignController extends AbstractController
{
    #[Route('/domainreassign/{domains_id}', name: 'domainmanager_domainreassign', methods: ['POST'], requirements: ['domains_id' => '\d+'])]
    public function __invoke(int $domains_id, Request $request): Response
    {
        $suppliers_id = (int) $request->request->get('suppliers_id', 0);

        $supplier = new Supplier();
        if ($suppliers_id <= 0 || !$supplier->getFromDB($suppliers_id)) {
            return new JsonResponse(['ok' => false, 'message' => __('Supplier not found', 'domainmanager')], 404);
        }

        if (!$supplier->can($suppliers_id, UPDATE)) {
            return new JsonResponse(['ok' => false, 'message' => __('You do not have permission to update this supplier', 'domainmanager')], 403);
        }

        $domain = new Domain();
        if (!$domain->getFromDB($domains_id)) {
            return new JsonResponse(['ok' => false, 'message' => __('Domain not found', 'domainmanager')], 404);
        }

        $infocom = new Infocom();
        $has_infocom = $infocom->getFromDBByCrit(['itemtype' => Domain::class, 'items_id' => $domains_id]);

        $ok = $has_infocom
            ? $infocom->update(['id' => $infocom->getID(), 'suppliers_id' => $suppliers_id])
            : $infocom->add(['itemtype' => Domain::class, 'items_id' => $domains_id, 'suppliers_id' => $suppliers_id]) !== false;

        if (!$ok) {
            return new JsonResponse(['ok' => false, 'message' => __('Failed to reassign the registrar', 'domainmanager')], 500);
        }

        return new JsonResponse([
            'ok'            => true,
            'supplier_name' => $supplier->getName(),
            'supplier_url'  => Supplier::getFormURLWithID($suppliers_id),
        ]);
    }
}
