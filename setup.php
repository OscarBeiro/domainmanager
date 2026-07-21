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

define('PLUGIN_DOMAINMANAGER_VERSION', '0.3.2');
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
// Real, filterable search option (unlike the two above) — see its own
// registration below and ARCHITECTURE.md §9 Phase 5.5 for why it exists.
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN_REGISTRAR', 9403);

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

        // A real, filterable option — unlike the one above. Infocom's own
        // suppliers_id is never exposed as an add-on search option for any
        // itemtype by core (verified against src/Infocom.php's
        // rawSearchOptionsToAdd() on 11.0/bugfixes — it adds immo_number/
        // order_number/dates/etc. for the same glpi_infocoms join, but not
        // this field), so the Supplier tab's "Domains" list banner
        // (§9 Phase 5.5) has nothing native to link a filtered Domain
        // search to without this. Same join shape core itself uses for
        // every other Infocom field added to an asset's search page.
        $options[] = [
            // A dropdown FK two hops away (Domain -> glpi_infocoms via
            // itemtype_item -> glpi_suppliers via suppliers_id) needs both
            // conventions combined: 'table'/'field' name the FINAL dropdown
            // target (glpi_suppliers/name — this is what GLPI's dropdown
            // datatype uses to resolve the *itemtype* for display/value
            // lookup; pointing it at glpi_infocoms instead — verified live
            // — makes GLPI try to resolve values as Infocom records, not
            // Suppliers, silently breaking both display and filtering),
            // 'linkfield' names the FK column, and 'joinparams.beforejoin'
            // describes the first hop, mirroring how core's own
            // CartridgeItem/ConsumableItem cases in
            // Infocom::rawSearchOptionsToAdd() reach the right glpi_infocoms
            // row before resolving a field on it — extended one hop further
            // here since the field itself is on the table *after* that.
            'id'           => PLUGIN_DOMAINMANAGER_SO_DOMAIN_REGISTRAR,
            'table'        => 'glpi_suppliers',
            'field'        => 'name',
            'linkfield'    => 'suppliers_id',
            'name'         => __('Registrar (Financial information)', 'domainmanager'),
            'datatype'     => 'dropdown',
            'forcegroupby' => true,
            'joinparams'   => [
                'beforejoin' => [
                    [
                        'table'      => 'glpi_infocoms',
                        'joinparams' => [
                            'jointype' => 'itemtype_item',
                        ],
                    ],
                ],
            ],
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

        // So a core-wide `security:change_key` rotation re-encrypts this
        // field instead of silently orphaning it (leaving it encrypted
        // with the old, now-discarded key — permanently undecryptable).
        $PLUGIN_HOOKS[Hooks::SECURED_FIELDS]['domainmanager'] = [
            'glpi_plugin_domainmanager_supplierconfigs.api_credentials',
        ];

        // "Sync now (Domain Manager)" massive action on Domain (§9 Phase
        // 5.5) — see MassiveActionHandler and plugin_domainmanager_MassiveActions().
        $PLUGIN_HOOKS[Hooks::USE_MASSIVE_ACTION]['domainmanager'] = true;

        $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['domainmanager'] = [
            Supplier::class     => [HookHandler::class, 'supplierPurged'],
            Domain::class       => [HookHandler::class, 'domainPurged'],
            DomainRecord::class => [HookHandler::class, 'domainRecordPurged'],
        ];

        $PLUGIN_HOOKS[Hooks::POST_ITEM_FORM]['domainmanager'] = [DomainForm::class, 'inject'];

        // Registrar assignment mirrors Infocom's own "Supplier" field
        // (read-only in the Domain Manager panel, §6.2) rather than being a
        // plugin-owned editable field — see HookHandler::infocomSaved().
        $PLUGIN_HOOKS[Hooks::ITEM_ADD]['domainmanager'] = [
            Infocom::class => [HookHandler::class, 'infocomSaved'],
        ];
        $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['domainmanager'] = [
            Infocom::class => [HookHandler::class, 'infocomSaved'],
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
