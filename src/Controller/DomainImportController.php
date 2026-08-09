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
use GlpiPlugin\Domainmanager\Config\Config;
use GlpiPlugin\Domainmanager\DomainState;
use GlpiPlugin\Domainmanager\Service\DomainDiscoveryMatcher;
use GlpiPlugin\Domainmanager\Service\PluginLogger;
use GlpiPlugin\Domainmanager\SupplierTab;
use Infocom;
use Session;
use Supplier;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bulk-creates `Domain` items from the Import Domains modal's submitted
 * selection (§9 Phase 8). Existence is re-checked at submit time rather
 * than trusting the modal's snapshot (a concurrent change since it was
 * rendered must not be trusted) — already-existing names are skipped and
 * counted, never treated as a batch failure. Sets no `ImportLock` rows
 * (locking only ever starts after a domain's first real sync, an existing
 * rule — §0.3/§5). §9 Phase 73: no domain is synced inline here — a bulk
 * import of N domains would mean N synchronous provider API calls in one
 * HTTP request. Each created domain instead gets a bare state row
 * (`is_glpi_created = 0`, no `last_sync_date`) so `Cron::cronDomainSync`'s
 * existing least-recently-synced-first ordering picks it up on its very
 * next tick without any special-casing.
 * URL: POST /plugins/domainmanager/domainimport/{suppliers_id}
 */
class DomainImportController extends AbstractController
{
    #[Route('/domainimport/{suppliers_id}', name: 'domainmanager_domainimport', methods: ['POST'], requirements: ['suppliers_id' => '\d+'])]
    public function __invoke(int $suppliers_id, Request $request): Response
    {
        $supplier = new Supplier();
        if (!$supplier->getFromDB($suppliers_id)) {
            return new Response(__('Supplier not found', 'domainmanager'), 404);
        }

        if (!$supplier->can($suppliers_id, UPDATE)) {
            return new Response(__('You do not have permission to update this supplier', 'domainmanager'), 403);
        }

        if (!(bool) $supplier->fields['is_active']) {
            return new Response(__('This supplier is inactive; domain import is disabled', 'domainmanager'), 409);
        }

        // Must fail with a clear message, not silently, when the user can
        // configure the supplier but can't create Domain items (§9 Phase 8).
        if (!Domain::canCreate()) {
            return new Response(__('You do not have permission to create domains', 'domainmanager'), 403);
        }

        $submitted    = $request->request->all('_import');
        $entities_id  = (int) $request->request->get('entities_id', 0);
        $names        = [];
        foreach ($submitted as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $names[DomainDiscoveryMatcher::normalize($name)] = $name;
            }
        }

        if ($names === []) {
            Session::addMessageAfterRedirect(__s('No domain selected', 'domainmanager'), false, ERROR);

            return $this->redirectToSupplierTab($suppliers_id);
        }

        // §9 Phase 12: apply the configured "domain type to apply to
        // imported domains" setting at creation time only, if the admin
        // has set one. Left unset (0), the created Domain gets no
        // `domaintypes_id` key at all — identical to one created by hand.
        $domaintypes_id = Config::getDomainTypeId();
        $existing       = DomainDiscoveryMatcher::loadExistingDomains();
        $trashed        = DomainDiscoveryMatcher::loadTrashedDomains();

        $created  = 0;
        $restored = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($names as $normalized => $name) {
            if (isset($existing[$normalized])) {
                $skipped++;
                continue;
            }

            $domain = new Domain();

            // A previously-trashed domain (soft-deleted, not purged) still
            // carries whatever tickets/contracts/infocom were linked to it
            // — restore that same item rather than `add()`ing a duplicate
            // that would leave all of that orphaned on the trashed one.
            if (isset($trashed[$normalized])) {
                $domains_id = $trashed[$normalized];
                if (!$domain->getFromDB($domains_id)) {
                    $failed++;
                    PluginLogger::error("Failed to load trashed Domain item #$domains_id for reimported domain '$name'");
                    continue;
                }

                $update_data = ['id' => $domains_id, 'is_deleted' => 0];
                if ($domaintypes_id > 0) {
                    $update_data['domaintypes_id'] = $domaintypes_id;
                }
                if (!$domain->update($update_data)) {
                    $failed++;
                    PluginLogger::error("Failed to restore trashed Domain item #$domains_id for reimported domain '$name'");
                    continue;
                }

                $restored++;
            } else {
                $domain_data = [
                    'name'        => $name,
                    'entities_id' => $entities_id,
                    // Native `glpi_domains.is_active` defaults to 0 —
                    // Cron::cronDomainSync() filters on `is_active = 1`, so
                    // leaving this unset would make a freshly-discovered
                    // domain permanently invisible to sync until someone
                    // manually flips the native "Active" toggle.
                    'is_active'   => 1,
                ];
                if ($domaintypes_id > 0) {
                    $domain_data['domaintypes_id'] = $domaintypes_id;
                }

                $domains_id = $domain->add($domain_data);

                if (!$domains_id) {
                    $failed++;
                    PluginLogger::error("Failed to create Domain item for discovered domain '$name'");
                    continue;
                }

                $created++;

                // §14.2 (Phase 47): every domain reaching here was
                // discovered via supplier import, not created by hand — its
                // state row is marked not "Native" up front. §9 Phase 73:
                // no sync runs inline; leaving `last_sync_date` unset means
                // `Cron::cronDomainSync`'s existing oldest-first ordering
                // (NULL sorts first) picks this domain up on its next tick.
                (new DomainState())->add([
                    'domains_id'      => $domains_id,
                    'is_glpi_created' => 0,
                ]);
            }

            $infocom = new Infocom();
            if ($infocom->getFromDBByCrit(['itemtype' => Domain::class, 'items_id' => $domains_id])) {
                $infocom->update(['id' => $infocom->getID(), 'suppliers_id' => $suppliers_id]);
            } else {
                $infocom->add([
                    'itemtype'     => Domain::class,
                    'items_id'     => $domains_id,
                    'suppliers_id' => $suppliers_id,
                ]);
            }
        }

        Session::addMessageAfterRedirect(
            self::buildSummaryMessage($created, $restored, $skipped, $failed),
            false,
            ($created > 0 || $restored > 0) ? INFO : WARNING,
        );

        return $this->redirectToSupplierTab($suppliers_id);
    }

    /**
     * @param  int $created
     * @param  int $restored
     * @param  int $skipped
     * @param  int $failed
     * @return string
     */
    private static function buildSummaryMessage(int $created, int $restored, int $skipped, int $failed): string
    {
        $parts = [sprintf(_n('%d domain imported', '%d domains imported', $created, 'domainmanager'), $created)];

        if ($restored > 0) {
            $parts[] = sprintf(_n('%d restored from trash', '%d restored from trash', $restored, 'domainmanager'), $restored);
        }

        if ($skipped > 0) {
            $parts[] = sprintf(_n('%d already existed', '%d already existed', $skipped, 'domainmanager'), $skipped);
        }

        if ($failed > 0) {
            $parts[] = sprintf(
                _n('%d could not be created, see the plugin error log', '%d could not be created, see the plugin error log', $failed, 'domainmanager'),
                $failed,
            );
        }

        return implode(' — ', $parts);
    }

    /**
     * @param  int $suppliers_id
     * @return RedirectResponse
     */
    private function redirectToSupplierTab(int $suppliers_id): RedirectResponse
    {
        return new RedirectResponse(
            Supplier::getFormURLWithID($suppliers_id) . '&forcetab=' . urlencode(SupplierTab::class . '$1'),
        );
    }
}
