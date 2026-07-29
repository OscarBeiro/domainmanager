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
use Html;
use Profile as CoreProfile;
use ProfileRight;
use Session;

class Profile extends CoreProfile
{
    public static $rightname = 'profile';

    /**
     * Name of the plugin right (a glpi_profilerights.name value)
     */
    public const UNLOCK_RIGHT = 'domainmanager:unlock_imported';

    /**
     * Single bit carried by the plugin right
     */
    public const RIGHT_UNLOCK_IMPORTED = 1;

    /**
     * Name of the DNS record write-back right (ARCHITECTURE.md §11.6,
     * Phase 32) — carries core's own CREATE/UPDATE/DELETE bits, rendered
     * via `Profile::displayRightsChoiceMatrix()` so it reads like any
     * other GLPI right instead of a bespoke checkbox set. Distinct from
     * `UNLOCK_RIGHT`: that one governs local overrides of plugin locks on
     * the native form; this one authorises writes through the plugin
     * panel, to the provider.
     */
    public const DNS_RECORDS_RIGHT = 'domainmanager:dns_records';

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
        switch ($item::getType()) {
            case CoreProfile::getType():
                return self::createTabEntry(self::getTypeName(1));
        }

        return '';
    }

    /**
     * getAllRights
     *
     * @return array
     */
    public function getAllRights(): array
    {
        return [
            [
                'rights'    => [
                    self::RIGHT_UNLOCK_IMPORTED => __('Edit fields and records imported by synchronization', 'domainmanager'),
                ],
                'label'     => __('Unlock imported domain data', 'domainmanager'),
                'field'     => self::UNLOCK_RIGHT,
            ],
            [
                'rights'    => [
                    CREATE => __('Create'),
                    UPDATE => __('Update'),
                    DELETE => __('Delete'),
                ],
                'label'     => __('DNS record write-back to provider', 'domainmanager'),
                'field'     => self::DNS_RECORDS_RIGHT,
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item->getType() == CoreProfile::getType()) {
            /** @var CoreProfile $item */
            return self::displayProfileForm($item);
        }

        return false;
    }

    /**
     * displayProfileForm
     *
     * @param  CoreProfile $profile
     * @return bool
     */
    public static function displayProfileForm(CoreProfile $profile): bool
    {
        $can_edit = Session::haveRight(self::$rightname, UPDATE);

        echo "<div class='spaced'>";
        if ($can_edit) {
            echo "<form method='post' action='" . htmlspecialchars($profile::getFormURL()) . "'>";
        }

        $matrix_options = [
            'canedit' => $can_edit,
            'title'   => self::getTypeName(1),
        ];
        $rights = (new self())->getAllRights();
        $profile->displayRightsChoiceMatrix($rights, $matrix_options);

        if ($can_edit) {
            echo "<div class='text-center'>";
            echo Html::hidden('id', ['value' => $profile->getID()]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo "</div>\n";
            Html::closeForm();
        }
        echo '</div>';

        return true;
    }

    /**
     * uninstall
     *
     * @param  \Migration $migration
     * @return void
     */
    public static function uninstall(\Migration $migration)
    {
        $migration->displayMessage("Removing profile rights");
        $profile = new self();
        foreach ($profile->getAllRights() as $data) {
            ProfileRight::deleteProfileRights([$data['field']]);
        }
    }
}
