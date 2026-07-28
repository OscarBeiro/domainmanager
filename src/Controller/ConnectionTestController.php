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

use Glpi\Controller\AbstractController;
use GlpiPlugin\Domainmanager\Contract\ConnectionTestableInterface;
use GlpiPlugin\Domainmanager\DriverFactory;
use GlpiPlugin\Domainmanager\DriverRegistry;
use GlpiPlugin\Domainmanager\Exception\DriverException;
use GlpiPlugin\Domainmanager\Service\PluginLogger;
use GlpiPlugin\Domainmanager\SupplierConfig;
use Supplier;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * "Check Connection" endpoint for the supplier tab (§3.5): tests the
 * *current form* credentials (which may not be saved yet), reports one
 * ConnectionTestResult per capability the driver supports, and persists
 * the outcome only when the supplier already has a saved SupplierConfig row.
 * URL: POST /plugins/domainmanager/connectiontest/{suppliers_id}
 * CSRF is enforced by the core CheckCsrfListener (X-Glpi-Csrf-Token header).
 */
class ConnectionTestController extends AbstractController
{
    #[Route('/connectiontest/{suppliers_id}', name: 'domainmanager_connectiontest', methods: ['POST'], requirements: ['suppliers_id' => '\d+'])]
    public function __invoke(int $suppliers_id, Request $request): Response
    {
        $supplier = new Supplier();
        if (!$supplier->getFromDB($suppliers_id)) {
            return new JsonResponse(['ok' => false, 'message' => __('Supplier not found', 'domainmanager')], 404);
        }

        if (!$supplier->can($suppliers_id, UPDATE)) {
            return new JsonResponse(['ok' => false, 'message' => __('You do not have permission to update this supplier', 'domainmanager')], 403);
        }

        // An inactive supplier is retired — its credentials must never be
        // used for an outbound call, not even a manual connection test
        // (§addendum "Skip Inactive Suppliers"). Checked here server-side
        // as well as by disabling the button client-side (SupplierTab),
        // since a direct POST could still bypass the disabled button.
        if (!(bool) $supplier->fields['is_active']) {
            return new JsonResponse(['ok' => false, 'message' => __('This supplier is inactive; connection testing is disabled', 'domainmanager')], 409);
        }

        $driver = (string) $request->request->get('api_driver', DriverRegistry::DRIVER_NONE);
        if (!DriverRegistry::isValidDriver($driver) || $driver === DriverRegistry::DRIVER_NONE) {
            return new JsonResponse(['ok' => false, 'message' => __('Invalid API driver', 'domainmanager')], 400);
        }

        $config      = SupplierConfig::getForSupplier($suppliers_id);
        $submitted   = $request->request->all('_credentials');
        $credentials = $this->mergeCredentials($driver, is_array($submitted) ? $submitted : [], $config);

        try {
            $driver_instance = DriverFactory::createDriver($driver, $credentials);
        } catch (DriverException $e) {
            return new JsonResponse(['ok' => false, 'message' => $e->getMessage()], 400);
        }

        if (!$driver_instance instanceof ConnectionTestableInterface) {
            return new JsonResponse(['ok' => false, 'message' => __('This driver does not support connection testing', 'domainmanager')], 400);
        }

        $started = microtime(true);
        try {
            $results = $driver_instance->testConnection($credentials);
        } catch (Throwable $e) {
            PluginLogger::error(
                "Connection test crashed for supplier #$suppliers_id (driver $driver)",
                $e::class . ': ' . $e->getMessage()
            );

            return new JsonResponse(['ok' => false, 'error' => __('Unexpected error while testing the connection, see the plugin error log', 'domainmanager')], 500);
        }
        $duration_ms = (int) round((microtime(true) - $started) * 1000);

        foreach ($results as $capability => $result) {
            PluginLogger::activity(sprintf(
                'Connection test: supplier #%d capability=%s driver=%s status=%s http_code=%s duration_ms=%d',
                $suppliers_id,
                $capability,
                $driver,
                $result->status->value,
                $result->httpStatusCode ?? 'n/a',
                $duration_ms
            ));

            if ($result->status->value !== 'success') {
                PluginLogger::error(
                    sprintf('Connection test failed: supplier #%d capability=%s driver=%s', $suppliers_id, $capability, $driver),
                    $result->rawDetail
                );
            }
        }

        if ($config !== null) {
            try {
                $config->recordConnectionTestResults($results);
            } catch (Throwable $e) {
                PluginLogger::error(
                    "Failed to persist connection test results for supplier #$suppliers_id",
                    $e::class . ': ' . $e->getMessage()
                );
            }
        }

        return new JsonResponse([
            'ok'      => true,
            'results' => array_map(static fn ($result) => $result->toArray(), $results),
        ]);
    }

    /**
     * Mirrors SupplierConfig::prepareDriverAndCredentials()'s "empty submit
     * keeps the stored secret" semantics, so testing a partially-edited form
     * still uses the still-valid other stored secrets.
     *
     * @param  string              $driver
     * @param  array<string,mixed> $submitted
     * @param  SupplierConfig|null $config
     * @return array<string,string>
     */
    private function mergeCredentials(string $driver, array $submitted, ?SupplierConfig $config): array
    {
        $stored = [];
        if ($config !== null && $config->fields['api_driver'] === $driver) {
            $stored = $config->getDecryptedCredentials();
        }

        $credentials = [];
        foreach (array_keys(DriverRegistry::getCredentialFields($driver)) as $name) {
            $value = trim((string) ($submitted[$name] ?? ''));
            if ($value !== '') {
                $credentials[$name] = $value;
            } elseif (isset($stored[$name]) && $stored[$name] !== '') {
                $credentials[$name] = $stored[$name];
            }
        }

        return $credentials;
    }
}
