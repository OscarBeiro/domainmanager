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
use GlpiPlugin\Domainmanager\Cron;
use GlpiPlugin\Domainmanager\DomainState;
use GlpiPlugin\Domainmanager\Service\RdapClient;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "RDAP last sync" icon endpoint (Phase 92): runs one RDAP lookup
 * synchronously for a single domain, reusing Cron::processRdapEnrichment()
 * (the same gap-fill logic the cron itself calls). The cron's own
 * candidate-selection query throttles the *sweep*, not RDAP lookups
 * themselves, so a manual one-off call here is safe — the panel's own
 * click-once-per-page-load guard (JS side) is what keeps a user from
 * hammering this endpoint.
 * URL: POST /plugins/domainmanager/rdapsync/{domains_id}
 * CSRF is enforced by the core CheckCsrfListener (X-Glpi-Csrf-Token header).
 */
class RdapSyncController extends AbstractController
{
    #[Route('/rdapsync/{domains_id}', name: 'domainmanager_rdapsync', methods: ['POST'], requirements: ['domains_id' => '\d+'])]
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

        try {
            Cron::processRdapEnrichment($domains_id, (string) $domain->fields['name'], new RdapClient());
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 502);
        }

        // Re-fetch rather than trust processRdapEnrichment()'s own return
        // value (a short human-readable string for the cron's own log, not
        // a structured payload) — the panel needs the actual field values
        // to refresh its cells.
        $domain->getFromDB($domains_id);
        $state = DomainState::getForDomain($domains_id);

        return new JsonResponse([
            'last_rdap_check_date' => $state?->fields['last_rdap_check_date'],
            'last_changed_date'    => $state?->fields['last_changed_date'],
            'transfer_date'        => $state?->fields['transfer_date'],
            'pending_delete'       => $state !== null ? (bool) $state->fields['pending_delete'] : null,
            'pending_transfer'     => $state !== null ? (bool) $state->fields['pending_transfer'] : null,
            'date_domaincreation'  => $domain->fields['date_domaincreation'],
            'date_expiration'      => $domain->fields['date_expiration'],
        ]);
    }
}
