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
use Glpi\Application\View\TemplateRenderer;
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

        TemplateRenderer::getInstance()->display('@domainmanager/supplier_tab.html.twig', [
            'suppliers_id'      => (int) $supplier->getID(),
            'config_id'         => $config !== null ? (int) $config->getID() : 0,
            'form_url'          => SupplierConfig::getFormURL(),
            'can_edit'          => $supplier->can((int) $supplier->getID(), UPDATE),
            'current_driver'    => $current_driver,
            'driver_labels'     => DriverRegistry::getDriverLabels(),
            'credential_fields' => DriverRegistry::getAllCredentialFields(),
            'saved'             => $saved,
            'values'            => $values,
        ]);

        return true;
    }
}
