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

use Glpi\Plugin\Hooks;
use GlpiPlugin\Domainmanager\DomainForm;
use GlpiPlugin\Domainmanager\HookHandler;
use GlpiPlugin\Domainmanager\LockEnforcer;
use GlpiPlugin\Domainmanager\Profile as DomainmanagerProfile;
use GlpiPlugin\Domainmanager\SupplierTab;

define('PLUGIN_DOMAINMANAGER_VERSION', '0.2.0');
define('PLUGIN_DOMAINMANAGER_MIN_GLPI', '11.0.0');
define('PLUGIN_DOMAINMANAGER_MAX_GLPI', '11.0.99');
define('PLUGIN_DOMAINMANAGER_REPOSITORY_URL', 'https://github.com/TICGAL-GLPI-Plugins/domainmanager');

// Plugin-owned search option IDs (§3.7) — used only as Log::history()'s
// id_search_option so the Historical tab's "field" column reads
// "Domain Manager", never exposed as a real editable/searchable value.
// MUST be re-checked for collisions with `php tools/getsearchoptions.php
// --type=Supplier` / `--type=Domain` against the target instance before
// go-live: no other installed plugin may already use these IDs for the
// same itemtype.
define('PLUGIN_DOMAINMANAGER_SO_SUPPLIER', 9401);
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN', 9402);

/**
 * Plugin_Version_Domainmanager
 *
 * @return array
 */
function plugin_version_domainmanager(): array
{
    return [
        'name'          => 'Domain Manager',
        'version'       => PLUGIN_DOMAINMANAGER_VERSION,
        'author'        => '<a href="https://tic.gal">TICGAL</a>',
        'homepage'      => 'https://tic.gal',
        'license'       => 'AGPLv3+',
        'requirements'  => [
            'glpi' => [
                'min' => PLUGIN_DOMAINMANAGER_MIN_GLPI,
                'max' => PLUGIN_DOMAINMANAGER_MAX_GLPI,
            ],
        ],
    ];
}

/**
 * Register the plugin's search options (§3.7): a single, non-functional
 * "Domain Manager" entry per itemtype, used only so Log::history() can set
 * id_search_option to something whose 'name' resolves to "Domain Manager"
 * in the Historical tab's "field" column. Bound to the itemtype's own
 * 'name' column so the Search UI (where this also appears as a normal,
 * selectable column/filter — an accepted side effect of this mechanism)
 * never hits a SQL error if a user actually tries to use it.
 *
 * @param  string $itemtype
 * @return array
 */
function plugin_domainmanager_getAddSearchOptionsNew($itemtype): array
{
    $options = [];

    if ($itemtype === Supplier::class) {
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_SUPPLIER,
            'table'         => Supplier::getTable(),
            'field'         => 'name',
            'name'          => __('Domain Manager', 'domainmanager'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
    }

    if ($itemtype === Domain::class) {
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAIN,
            'table'         => Domain::getTable(),
            'field'         => 'name',
            'name'          => __('Domain Manager', 'domainmanager'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
    }

    return $options;
}

/**
 * Plugin_Init_Domainmanager
 *
 * @return void
 */
function plugin_init_domainmanager(): void
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['domainmanager'] = true;

    if (Plugin::isPluginActive('domainmanager')) {
        Plugin::registerClass(DomainmanagerProfile::class, ['addtabon' => Profile::class]);
        Plugin::registerClass(SupplierTab::class, ['addtabon' => Supplier::class]);

        $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['domainmanager'] = [
            Supplier::class     => [HookHandler::class, 'supplierPurged'],
            Domain::class       => [HookHandler::class, 'domainPurged'],
            DomainRecord::class => [HookHandler::class, 'domainRecordPurged'],
        ];

        $PLUGIN_HOOKS[Hooks::POST_ITEM_FORM]['domainmanager'] = [DomainForm::class, 'inject'];

        $PLUGIN_HOOKS[Hooks::ITEM_ADD]['domainmanager'] = [
            Domain::class => [HookHandler::class, 'domainAdded'],
        ];

        $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['domainmanager'] = [
            Domain::class       => [HookHandler::class, 'domainPreUpdate'],
            DomainRecord::class => [LockEnforcer::class, 'domainRecordPreUpdate'],
        ];
        $PLUGIN_HOOKS[Hooks::PRE_ITEM_DELETE]['domainmanager'] = [
            DomainRecord::class => [LockEnforcer::class, 'domainRecordPreDelete'],
        ];
        $PLUGIN_HOOKS[Hooks::PRE_ITEM_PURGE]['domainmanager'] = [
            DomainRecord::class => [LockEnforcer::class, 'domainRecordPrePurge'],
        ];
    }
}
