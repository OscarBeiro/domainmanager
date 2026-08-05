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
use GlpiPlugin\Domainmanager\DomainState;
use GlpiPlugin\Domainmanager\Service\DnsRecordWriteback;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Backs the "creating this record here pushes it live" notice on
 * `DomainRecord`'s own generic add form (`domainrecord_new_notice.html.twig`
 * / `DomainForm::injectDomainRecord()`): the domain isn't known server-side
 * at render time on that form (still a dropdown, or the tab's own hidden
 * field isn't visible to the POST_ITEM_FORM hook either), so the banner's
 * visibility is decided client-side, via this small read-only lookup, once
 * a domain is actually picked.
 * URL: GET /plugins/domainmanager/domainwritebackstatus/{domains_id}
 */
class DomainWritebackStatusController extends AbstractController
{
    #[Route('/domainwritebackstatus/{domains_id}', name: 'domainmanager_domainwritebackstatus', methods: ['GET'], requirements: ['domains_id' => '\d+'])]
    public function __invoke(int $domains_id): Response
    {
        $domain = new Domain();
        if ($domains_id <= 0 || !$domain->getFromDB($domains_id) || !$domain->can($domains_id, READ)) {
            return new JsonResponse(['managed_writeback' => false]);
        }

        $state = DomainState::getForDomain($domains_id);

        return new JsonResponse([
            'managed_writeback' => $state !== null && DnsRecordWriteback::isDomainDnsEditable($state),
        ]);
    }
}
