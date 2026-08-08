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
use DomainRecord;
use DomainRecordType;
use Glpi\Controller\AbstractController;
use GlpiPlugin\Domainmanager\ImportedRecord;
use GlpiPlugin\Domainmanager\Service\PublicIpResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Backs the Records tab's "Show public IP" per-row lookup (§17.9-§17.11,
 * Phase 71): a read-only, on-demand live DNS lookup of the publicly-visible
 * address for a Cloudflare-proxied record — deliberately not queried on page
 * load for every row (§9 Phase 23's cost constraint). Restricted to records
 * the plugin has itself marked `is_proxied`; the origin IP for every other
 * record is already shown in the Records tab's own Data column, so there is
 * no reason to expose a live-DNS fan-out for arbitrary record ids.
 * URL: GET /plugins/domainmanager/recordip/{domainrecords_id}
 */
class RecordPublicIpController extends AbstractController
{
    #[Route('/recordip/{domainrecords_id}', name: 'domainmanager_recordip', methods: ['GET'], requirements: ['domainrecords_id' => '\d+'])]
    public function __invoke(int $domainrecords_id): Response
    {
        $record = new DomainRecord();
        if (!$record->getFromDB($domainrecords_id)) {
            return new JsonResponse(['error' => __('Record not found', 'domainmanager')], 404);
        }

        $domains_id = (int) $record->fields['domains_id'];
        $domain     = new Domain();
        if (!$domain->getFromDB($domains_id) || !$domain->can($domains_id, READ)) {
            return new JsonResponse(['error' => __('You do not have permission to view this record', 'domainmanager')], 403);
        }

        $imported = ImportedRecord::getForDomainRecord($domainrecords_id);
        if ($imported === null || !(bool) $imported->fields['is_proxied']) {
            return new JsonResponse(['error' => __('This record is not proxied', 'domainmanager')], 400);
        }

        $typeObj = new DomainRecordType();
        $type    = $typeObj->getFromDB((int) $record->fields['domainrecordtypes_id']) ? $typeObj->fields['name'] : '';

        $zoneName = $domain->fields['name'];
        $name     = trim((string) $record->fields['name']);
        $fqdn     = ($name === '' || $name === '@' || strcasecmp($name, $zoneName) === 0)
            ? $zoneName
            : $name . '.' . $zoneName;

        $ips = (new PublicIpResolver())->resolve($fqdn, $type === 'AAAA' ? 'AAAA' : 'A');

        return new JsonResponse(['ips' => $ips]);
    }
}
