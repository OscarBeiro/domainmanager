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

namespace GlpiPlugin\Domainmanager;

use CommonGLPI;
use Domain;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use Session;
use Supplier;

/**
 * "Domain Manager" tab on Supplier: API driver + credentials form
 */
class SupplierTab extends CommonGLPI
{
    /**
     * {@inheritDoc}
     */
    public static function getTypeName($nb = 0)
    {
        return __('Domain Manager', 'domainmanager');
    }

    /**
     * {@inheritDoc}
     */
    public static function getIcon()
    {
        return 'ti ti-world-cog';
    }

    /**
     * {@inheritDoc}
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string|array
    {
        if (
            $item instanceof Supplier
            && !$withtemplate
            && $item->getID() > 0
            && $item->can($item->getID(), READ)
        ) {
            return self::createTabEntry(self::getTypeName(1));
        }

        return '';
    }

    /**
     * {@inheritDoc}
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof Supplier && $item->can($item->getID(), READ)) {
            return self::showForSupplier($item);
        }

        return false;
    }

    /**
     * Render the driver + credentials form
     *
     * @param  Supplier $supplier
     * @return bool
     */
    private static function showForSupplier(Supplier $supplier): bool
    {
        $config = SupplierConfig::getForSupplier((int) $supplier->getID());

        $current_driver = $config !== null
            ? (string) $config->fields['api_driver']
            : DriverRegistry::DRIVER_NONE;

        // Never echo secrets back: secret fields only get a saved/empty flag,
        // non-secret fields (e.g. username) are prefilled normally
        $saved  = [];
        $values = [];
        if ($config !== null) {
            $saved       = $config->getSavedCredentialFlags();
            $credentials = $config->getDecryptedCredentials();
            foreach (DriverRegistry::getCredentialFields($current_driver) as $name => $meta) {
                if (!$meta['secret'] && isset($credentials[$name])) {
                    $values[$name] = $credentials[$name];
                }
            }
        }

        $connection_test = $config !== null
            ? $config->getConnectionTestSummary()
            : [
                'registrar' => ['status' => 'never', 'message' => '', 'http_code' => null, 'date' => null],
                'dns'       => ['status' => 'never', 'message' => '', 'http_code' => null, 'date' => null],
            ];

        TemplateRenderer::getInstance()->display('@domainmanager/supplier_tab.html.twig', [
            'suppliers_id'         => (int) $supplier->getID(),
            'config_id'            => $config !== null ? (int) $config->getID() : 0,
            'form_url'             => SupplierConfig::getFormURL(),
            'can_edit'             => $supplier->can((int) $supplier->getID(), UPDATE),
            'current_driver'       => $current_driver,
            'driver_labels'        => DriverRegistry::getDriverLabels(),
            'credential_fields'    => DriverRegistry::getAllCredentialFields(),
            'saved'                => $saved,
            'values'               => $values,
            'connection_test'      => $connection_test,
            'testable_capabilities' => DriverRegistry::getTestableCapabilities($current_driver),
            'all_testable_capabilities' => array_combine(
                DriverRegistry::getAvailableDrivers(),
                array_map([DriverRegistry::class, 'getTestableCapabilities'], DriverRegistry::getAvailableDrivers())
            ),
            'primary_capability'   => DriverRegistry::getPrimaryTestableCapability($current_driver),
            'all_primary_capabilities' => array_combine(
                DriverRegistry::getAvailableDrivers(),
                array_map([DriverRegistry::class, 'getPrimaryTestableCapability'], DriverRegistry::getAvailableDrivers())
            ),
            'domains' => Session::haveRight('domain', READ)
                ? self::buildDomainsListRows(DomainState::getDomainsForSupplier((int) $supplier->getID()))
                : [],
        ]);

        return true;
    }

    /**
     * Presentation-layer enrichment of DomainState::getDomainsForSupplier()'s
     * raw rows: itemtype hyperlinks and the plugin-managed/known-unmanaged/
     * unknown classification for the DNS/NS column (reuses the sync engine's
     * existing states verbatim, no new classification logic — §5, §9 Phase 5.5).
     *
     * @param  array<int, array{domains_id:int, name:string, entities_id:int,
     *                registrar_suppliers_id:int, dns_suppliers_id:int,
     *                detected_provider:string, registrar_status:string,
     *                dns_status:string}> $domains
     * @return array<int, array{domains_id:int, name:string, url:string,
     *                registrar:?array{name:string, url:string},
     *                registrar_status:string, dns:array{kind:string,
     *                name:string, url:?string}, dns_status:string}>
     */
    private static function buildDomainsListRows(array $domains): array
    {
        return array_map(static function (array $domain): array {
            return [
                'domains_id'       => $domain['domains_id'],
                'name'             => $domain['name'],
                'url'              => Domain::getFormURLWithID($domain['domains_id']),
                'registrar'        => self::describeSupplierRole($domain['registrar_suppliers_id']),
                'registrar_status' => $domain['registrar_status'],
                'dns'              => self::describeDnsProvider($domain),
                'dns_status'       => $domain['dns_status'],
            ];
        }, $domains);
    }

    /**
     * @param  int $suppliers_id
     * @return ?array{name:string, url:string}
     */
    private static function describeSupplierRole(int $suppliers_id): ?array
    {
        if ($suppliers_id <= 0) {
            return null;
        }

        return [
            'name' => Dropdown::getDropdownName(Supplier::getTable(), $suppliers_id),
            'url'  => Supplier::getFormURLWithID($suppliers_id),
        ];
    }

    /**
     * @param  array{dns_suppliers_id:int, detected_provider:string, dns_status:string} $domain
     * @return array{kind:string, name:string, url:?string}
     */
    private static function describeDnsProvider(array $domain): array
    {
        if ($domain['dns_suppliers_id'] > 0) {
            return [
                'kind' => 'managed',
                'name' => Dropdown::getDropdownName(Supplier::getTable(), $domain['dns_suppliers_id']),
                'url'  => Supplier::getFormURLWithID($domain['dns_suppliers_id']),
            ];
        }

        if (
            $domain['dns_status'] === DomainState::STATUS_UNSUPPORTED
            || $domain['dns_status'] === DomainState::STATUS_UNCONFIGURED
        ) {
            // A real provider was detected either way — 'unsupported' means
            // no driver exists for it yet, 'unconfigured' means a driver
            // exists but no Supplier has been set up with it. Both read as
            // "known, not actively managed yet" from this list's point of
            // view (§9 Phase 5.5).
            return ['kind' => 'unmanaged', 'name' => $domain['detected_provider'], 'url' => null];
        }

        if ($domain['dns_status'] === DomainState::STATUS_UNKNOWN) {
            return ['kind' => 'unknown', 'name' => __('Unknown', 'domainmanager'), 'url' => null];
        }

        return ['kind' => 'never', 'name' => __('Never synced', 'domainmanager'), 'url' => null];
    }
}
