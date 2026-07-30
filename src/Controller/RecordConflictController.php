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
use Glpi\Application\View\TemplateRenderer;
use Glpi\Controller\AbstractController;
use GlpiPlugin\Domainmanager\LockEnforcer;
use GlpiPlugin\Domainmanager\RecordConflict;
use GlpiPlugin\Domainmanager\Service\DnsRecordWriteback;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The update-conflict resolution screen (ARCHITECTURE.md §13, Phase 44):
 * reached from the message `DnsRecordWriteback::onPreUpdate()` shows on
 * drift, or from the record's own edit page whenever an unresolved conflict
 * row exists for it. Renders both values side by side and lets the user
 * pick which one wins, or cancel outright.
 * URL: GET /plugins/domainmanager/recordconflict/{id}
 *      POST /plugins/domainmanager/recordconflict/{id}/keep-glpi
 *      POST /plugins/domainmanager/recordconflict/{id}/keep-provider
 *      POST /plugins/domainmanager/recordconflict/{id}/cancel
 * CSRF is enforced by the core CheckCsrfListener (`_glpi_csrf_token` field).
 */
class RecordConflictController extends AbstractController
{
    #[Route('/recordconflict/{id}', name: 'domainmanager_recordconflict', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        [$conflict, $record, $domain, $error] = $this->load($id);
        if ($error !== null) {
            return $error;
        }

        $typeObj = new DomainRecordType();
        $typeObj->getFromDB((int) $record->fields['domainrecordtypes_id']);

        $html = TemplateRenderer::getInstance()->render('@domainmanager/recordconflict.html.twig', [
            'conflict'    => $conflict,
            'record'      => $record,
            'domain'      => $domain,
            'type_name'   => $typeObj->fields['name'] ?? '?',
            'driver_name' => DnsRecordWriteback::writableSupplierName((int) $domain->getID()) ?? __('the provider', 'domainmanager'),
            'record_url'  => $record->getFormURLWithID((int) $record->getID()),
            'keep_glpi_url'     => '/plugins/domainmanager/recordconflict/' . $id . '/keep-glpi',
            'keep_provider_url' => '/plugins/domainmanager/recordconflict/' . $id . '/keep-provider',
            'cancel_url'        => '/plugins/domainmanager/recordconflict/' . $id . '/cancel',
        ]);

        return new Response($html);
    }

    #[Route('/recordconflict/{id}/keep-glpi', name: 'domainmanager_recordconflict_keepglpi', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function keepGlpiValue(int $id): Response
    {
        [$conflict, $record, , $error] = $this->load($id);
        if ($error !== null) {
            return $error;
        }

        // Re-submits the originally-attempted values with a one-time,
        // conflict-row-scoped bypass (§13.6 item 3): `DnsRecordWriteback::
        // onPreUpdate()` re-verifies the live value against this exact
        // conflict row before pushing (§13.6 item 1) rather than trusting
        // this click blindly.
        $record->update([
            'id'                       => $record->getID(),
            'data'                     => $conflict->fields['submitted_data'],
            'ttl'                      => $conflict->fields['submitted_ttl'],
            '_domainmanager_conflict_id' => $conflict->getID(),
        ]);

        return $this->backToRecord($record);
    }

    #[Route('/recordconflict/{id}/keep-provider', name: 'domainmanager_recordconflict_keepprovider', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function keepProviderValue(int $id): Response
    {
        [$conflict, $record, , $error] = $this->load($id);
        if ($error !== null) {
            return $error;
        }

        // A local-only, sync-style write (§13.6 item 2): never push back to
        // the provider a value that just came *from* the provider. The
        // existing $sync_in_progress bypass (runtime-only, not input-based,
        // so it can't be forged through a form POST) makes
        // `LockEnforcer::domainRecordPreUpdate()` skip both
        // `DnsRecordWriteback::onPreUpdate()` and its own field-lock strip,
        // exactly like a real sync's own writes.
        LockEnforcer::$sync_in_progress = true;
        try {
            $record->update([
                'id'   => $record->getID(),
                'data' => $conflict->fields['live_data'],
                'ttl'  => $conflict->fields['live_ttl'],
            ]);
        } finally {
            LockEnforcer::$sync_in_progress = false;
        }

        $conflict->delete(['id' => $conflict->getID()], true);

        Session::addMessageAfterRedirect(
            '[Domain Manager] ' . __('Kept the provider\'s current value; the pending GLPI edit was discarded.', 'domainmanager'),
        );

        return $this->backToRecord($record);
    }

    #[Route('/recordconflict/{id}/cancel', name: 'domainmanager_recordconflict_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id): Response
    {
        [$conflict, $record, , $error] = $this->load($id);
        if ($error !== null) {
            return $error;
        }

        $conflict->delete(['id' => $conflict->getID()], true);

        Session::addMessageAfterRedirect(
            '[Domain Manager] ' . __('Conflict cancelled; no changes were made either side.', 'domainmanager'),
        );

        return $this->backToRecord($record);
    }

    /**
     * Shared load-and-authorize path for all four actions.
     *
     * @param  int $id
     * @return array{0: ?RecordConflict, 1: ?DomainRecord, 2: ?Domain, 3: ?Response}
     */
    private function load(int $id): array
    {
        $conflict = new RecordConflict();
        if (!$conflict->getFromDB($id)) {
            return [null, null, null, new Response(__('Conflict not found', 'domainmanager'), 404)];
        }

        $record = new DomainRecord();
        if (!$record->getFromDB((int) $conflict->fields['domainrecords_id'])) {
            return [null, null, null, new Response(__('Record not found', 'domainmanager'), 404)];
        }

        $domain = new Domain();
        if (!$domain->getFromDB((int) $record->fields['domains_id'])) {
            return [null, null, null, new Response(__('Domain not found', 'domainmanager'), 404)];
        }

        if (!$record->can($record->getID(), UPDATE)) {
            return [null, null, null, new Response(__('You do not have permission to update this record', 'domainmanager'), 403)];
        }

        return [$conflict, $record, $domain, null];
    }

    /**
     * @param  DomainRecord $record
     * @return Response
     */
    private function backToRecord(DomainRecord $record): Response
    {
        return new RedirectResponse($record->getFormURLWithID((int) $record->getID()));
    }
}
