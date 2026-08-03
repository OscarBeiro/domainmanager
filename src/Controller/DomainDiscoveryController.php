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
use Entity;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Controller\AbstractController;
use GlpiPlugin\Domainmanager\DriverFactory;
use GlpiPlugin\Domainmanager\Exception\DriverException;
use GlpiPlugin\Domainmanager\Service\DomainDiscoveryMatcher;
use GlpiPlugin\Domainmanager\Service\PluginLogger;
use GlpiPlugin\Domainmanager\SupplierConfig;
use Session;
use Supplier;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * "Import Domains" modal content (§9 Phase 8): lists every domain the
 * supplier's registrar account actually has, enriched with an existence/
 * mismatch check against GLPI's own inventory. Renders an HTML fragment
 * (not JSON) for the SupplierTab button to inject into a Bootstrap modal —
 * matches this plugin's server-side-Twig-everywhere convention.
 * URL: GET|POST /plugins/domainmanager/domaindiscovery/{suppliers_id} — POST
 * because Ajax::createModalWindow()'s generated JS always calls jQuery's
 * .load(url, fields) with a (possibly empty) fields object, which makes
 * jQuery issue a POST rather than a GET; CSRF is still enforced normally
 * (core's own $(document).ajaxSend() handler attaches the token header to
 * every jQuery AJAX call automatically, this plugin is CSRF-compliant, §CSRF_COMPLIANT).
 */
class DomainDiscoveryController extends AbstractController
{
    #[Route('/domaindiscovery/{suppliers_id}', name: 'domainmanager_domaindiscovery', methods: ['GET', 'POST'], requirements: ['suppliers_id' => '\d+'])]
    public function __invoke(int $suppliers_id): Response
    {
        $supplier = new Supplier();
        if (!$supplier->getFromDB($suppliers_id)) {
            return $this->renderError(__('Supplier not found', 'domainmanager'));
        }

        if (!$supplier->can($suppliers_id, UPDATE)) {
            return $this->renderError(__('You do not have permission to update this supplier', 'domainmanager'));
        }

        // Never call out with an inactive supplier's credentials, not even
        // to list its domains (same rule already enforced for Check
        // Connection, §addendum "Skip Inactive Suppliers").
        if (!(bool) $supplier->fields['is_active']) {
            return $this->renderError(__('This supplier is inactive; domain discovery is disabled', 'domainmanager'));
        }

        $config = SupplierConfig::getForSupplier($suppliers_id);
        $driver = $config !== null ? DriverFactory::forDiscovery($config) : null;
        if ($driver === null) {
            return $this->renderError(__('This driver does not support domain discovery', 'domainmanager'));
        }

        try {
            $discovered = $driver->listAccountDomains();
        } catch (DriverException $e) {
            return $this->renderError($e->getMessage());
        } catch (Throwable $e) {
            PluginLogger::error(
                "Domain discovery crashed for supplier #$suppliers_id",
                $e::class . ': ' . $e->getMessage(),
            );

            return $this->renderError(__('Unexpected error while listing domains, see the plugin error log', 'domainmanager'));
        }

        $rows = DomainDiscoveryMatcher::match($discovered, $suppliers_id);

        $html = TemplateRenderer::getInstance()->render('@domainmanager/domain_discovery_modal.html.twig', [
            'suppliers_id'    => $suppliers_id,
            'supplier_name'   => $supplier->getName(),
            'rows'            => $rows,
            'can_create'      => Domain::canCreate(),
            'entity_dropdown' => Entity::dropdown([
                'name'    => 'entities_id',
                'value'   => $_SESSION['glpiactive_entity'] ?? 0,
                'display' => false,
            ]),
            'import_url'   => '/plugins/domainmanager/domainimport/' . $suppliers_id,
            'reassign_url' => '/plugins/domainmanager/domainreassign/',
        ]);

        return new Response($html);
    }

    /**
     * Renders the modal's own error state and returns HTTP 200: jQuery's
     * .load() (used by Ajax::createModalWindow(), see SupplierTab) only
     * injects the response body into the dialog on a 2xx status — on a 4xx/5xx
     * it discards the body entirely, leaving the modal blank with no
     * indication of what went wrong. Every error path must go through this
     * so the operator actually sees the message.
     */
    private function renderError(string $message): Response
    {
        $html = TemplateRenderer::getInstance()->render('@domainmanager/domain_discovery_modal.html.twig', [
            'error' => $message,
        ]);

        return new Response($html);
    }
}
