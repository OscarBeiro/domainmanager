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
use GlpiPlugin\Domainmanager\LockEnforcer;
use Infocom;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Unlink registrar" action (§9 Phase 14): clears the Domain's Infocom
 * `suppliers_id`. Exists because, once a domain has a confirmed working
 * registrar match, `LockEnforcer::infocomPreUpdate()` locks `suppliers_id`
 * against ordinary edits — this action bypasses that lock the same way the
 * existing Reassign action (`DomainRegistrarReassignController`) does,
 * without requiring `domainmanager:unlock_imported`.
 *
 * **Auth is `Domain::canUpdate()`**, unlike Reassign's Supplier-`UPDATE`-only
 * check: unlinking has no target supplier to check rights against, and
 * removing a Domain's own registrar link is squarely an action over the
 * Domain itself.
 *
 * URL: POST /plugins/domainmanager/domainunlink/{domains_id}
 */
class DomainRegistrarUnlinkController extends AbstractController
{
    #[Route('/domainunlink/{domains_id}', name: 'domainmanager_domainunlink', methods: ['POST'], requirements: ['domains_id' => '\d+'])]
    public function __invoke(int $domains_id, Request $request): Response
    {
        $domain = new Domain();
        if (!$domain->getFromDB($domains_id)) {
            return new JsonResponse(['ok' => false, 'message' => __('Domain not found', 'domainmanager')], 404);
        }

        if (!$domain->can($domains_id, UPDATE)) {
            return new JsonResponse(['ok' => false, 'message' => __('You do not have permission to update this domain', 'domainmanager')], 403);
        }

        $infocom = new Infocom();
        if (!$infocom->getFromDBByCrit(['itemtype' => Domain::class, 'items_id' => $domains_id])) {
            // Nothing to unlink; treat as already-unlinked, not an error.
            return new JsonResponse(['ok' => true]);
        }

        if ((int) $infocom->fields['suppliers_id'] === 0) {
            return new JsonResponse(['ok' => true]);
        }

        $previous = LockEnforcer::$sync_in_progress;
        LockEnforcer::$sync_in_progress = true;
        try {
            $ok = $infocom->update(['id' => $infocom->getID(), 'suppliers_id' => 0]);
        } finally {
            LockEnforcer::$sync_in_progress = $previous;
        }

        if (!$ok) {
            return new JsonResponse(['ok' => false, 'message' => __('Failed to unlink the registrar', 'domainmanager')], 500);
        }

        return new JsonResponse(['ok' => true]);
    }
}
