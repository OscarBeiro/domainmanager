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
use GlpiPlugin\Domainmanager\Config\Config as DomainmanagerConfig;
use GlpiPlugin\Domainmanager\DomainForm;
use GlpiPlugin\Domainmanager\DomainState;
use GlpiPlugin\Domainmanager\HookHandler;
use GlpiPlugin\Domainmanager\ImportedRecord;
use GlpiPlugin\Domainmanager\LockEnforcer;
use GlpiPlugin\Domainmanager\Profile as DomainmanagerProfile;
use GlpiPlugin\Domainmanager\SupplierTab;

define('PLUGIN_DOMAINMANAGER_VERSION', '0.11.5');
define('PLUGIN_DOMAINMANAGER_MIN_GLPI', '11.0.0');
define('PLUGIN_DOMAINMANAGER_MAX_GLPI', '11.0.99');
define('PLUGIN_DOMAINMANAGER_REPOSITORY_URL', 'https://github.com/TICGAL-GLPI-Plugins/domainmanager');

// Plugin-owned, real filterable search option IDs (§3.7, §9 Phase 14
// addendum "Search UI cleanup"). Two former dummy placeholders bound to
// `name` (Supplier id 9401, Domain id 9402, used only to label
// Log::history()'s id_search_option "Domain Manager") and one duplicate of
// a native option (Domain's "Registrar", id 9403 — confirmed live against
// `Infocom::rawSearchOptionsToAdd()` that native search option id **53**
// already exposes `glpi_suppliers.name` under "Financial and administrative
// information" for any Infocom-bearing itemtype, Domain included) were
// dropped: all three cluttered the Search UI's generic "Plugins" category
// with entries offering no real, non-duplicate filtering value. Log::history()
// call sites now pass `id_search_option = 0` (blank "field" column) and
// prefix the message text with "[Domain Manager] " instead — the documented
// fallback convention for plugins that would rather not have any Search UI
// footprint. `SupplierTab::getDomainsSearchUrl()` now links to native id 53
// instead of the dropped 9403.
// MUST be re-checked for collisions with `php tools/getsearchoptions.php
// --type=Domain` / `--type=DomainRecord` against the target instance before
// go-live: no other installed plugin may already use these IDs for the
// same itemtype.
// Real, filterable search option on DomainRecord (§addendum "Searchable
// 'Managed' Field on Domain Records") — see its own registration below.
define('PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_MANAGED', 9404);
// Real, filterable search option on DomainRecord (§9 Phase 7 addendum
// "Searchable 'Proxy Status' Field for CDN-Proxied Records") — see its own
// registration below.
define('PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_PROXY', 9405);
// Real, filterable search option on Domain (§9 Phase 14 "Domain-level
// Managed field") — see its own registration below.
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN_MANAGED', 9406);
// Real, filterable search options on Domain (§9 Phase 15 addendum
// "Searchable fields") — see their own registration below. Reserved block
// widened 9400-9409 -> 9400-9429 (search-options-registry.json) to leave
// room for the remaining states-table fields (registrar metadata columns)
// a future phase will expose the same way.
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN_NS_PROVIDER', 9407);
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN_REGISTRAR_STATUS', 9408);
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN_DNS_STATUS', 9409);
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN_LAST_SYNC', 9410);

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
 * §9 Phase 10: `IdnNormalizer` (Unicode <-> Punycode conversion, needed for
 * every DNS lookup and driver call as of this phase) hard-depends on the
 * `intl` PHP extension's `idn_to_ascii()`/`idn_to_utf8()` — block activation
 * with a clear message rather than letting every subsequent sync fail with
 * an opaque fatal error.
 *
 * @return bool
 */
function plugin_domainmanager_check_prerequisites(): bool
{
    if (!extension_loaded('intl')) {
        echo __('The intl PHP extension is required by Domain Manager (used for IDN/Punycode domain name conversion).', 'domainmanager');
        return false;
    }

    return true;
}

/**
 * Register the plugin's real, filterable search options (§3.7, §9 Phase 14
 * addendum "Search UI cleanup"). Each itemtype's group opens with an
 * explicit category-tab entry (`'id' => 'domainmanager'`, matching core's
 * own convention e.g. Infocom's `'id' => 'financial'`) so these show up
 * under a "Domain Manager"-labeled group in the Search UI's field/criteria
 * picker instead of falling into the generic, unlabeled "Plugins" catch-all
 * a plugin's search options default to without one.
 *
 * @param  string $itemtype
 * @return array
 */
function plugin_domainmanager_getAddSearchOptionsNew($itemtype): array
{
    $options = [];

    if ($itemtype === Domain::class) {
        $options[] = [
            'id'   => 'domainmanager',
            'name' => __('Domain Manager', 'domainmanager'),
        ];

        // §9 Phase 14 "Domain-level Managed field": one row per Domain
        // already exists on this table (unlike the DomainRecord-level
        // Managed field, which needed its own table), so this is a direct
        // single-hop 'child' join, same shape/precedent as
        // PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_MANAGED below. A domain with
        // no state row yet (never synced, never assigned a registrar) reads
        // as NULL/"No" via the LEFT JOIN. 'massiveaction' => false: plugin
        // -derived, never meant to be bulk-edited directly.
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAIN_MANAGED,
            'table'         => DomainState::getTable(),
            'field'         => 'is_managed',
            'linkfield'     => 'domains_id',
            'name'          => __('Managed', 'domainmanager'),
            'datatype'      => 'bool',
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ];

        // §9 Phase 15 addendum "Searchable fields": same single-hop 'child'
        // join shape/table as PLUGIN_DOMAINMANAGER_SO_DOMAIN_MANAGED above
        // (one states row per Domain already exists). All four use
        // 'datatype' => 'specific' so the criteria's value input renders as
        // a dropdown wherever the underlying values form a real, bounded
        // set — `DomainState::getSpecificValueToSelect()`/
        // `getSpecificValueToDisplay()` are the ones actually called for
        // these (not `Domain`'s), since `SQLProvider` dispatches from the
        // search option's own `table`/`'itemtype'`, not from the itemtype
        // under search — `'itemtype'` is set explicitly below rather than
        // relying on `getItemTypeForTable()` to guess it correctly from a
        // table name that doesn't follow the plain naming-convention
        // class<->table mapping (`DomainState::getTable()` is an explicit
        // override, not derived from the class name).
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAIN_NS_PROVIDER,
            'itemtype'      => DomainState::class,
            'table'         => DomainState::getTable(),
            'field'         => 'detected_provider',
            'linkfield'     => 'domains_id',
            'name'          => __('NS Provider', 'domainmanager'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ];
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAIN_REGISTRAR_STATUS,
            'itemtype'      => DomainState::class,
            'table'         => DomainState::getTable(),
            'field'         => 'registrar_status',
            'linkfield'     => 'domains_id',
            'name'          => __('Registrar sync status', 'domainmanager'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ];
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAIN_DNS_STATUS,
            'itemtype'      => DomainState::class,
            'table'         => DomainState::getTable(),
            'field'         => 'dns_status',
            'linkfield'     => 'domains_id',
            'name'          => __('DNS sync status', 'domainmanager'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ];
        // A plain native 'datetime' datatype — last_sync_date's values
        // aren't a bounded enum, so a dropdown doesn't apply here; GLPI's
        // built-in datetime criteria (equals/before/after/empty) already
        // cover "sort and check validity" (e.g. find domains whose last
        // sync is older than a given date, or that have never synced at
        // all via "is empty") without any custom display/select code.
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAIN_LAST_SYNC,
            'table'         => DomainState::getTable(),
            'field'         => 'last_sync_date',
            'linkfield'     => 'domains_id',
            'name'          => __('Last sync', 'domainmanager'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ];
    }

    if ($itemtype === DomainRecord::class) {
        $options[] = [
            'id'   => 'domainmanager',
            'name' => __('Domain Manager', 'domainmanager'),
        ];

        // A single-hop join to the plugin's own ownership-map table
        // (glpi_plugin_domainmanager_records, ImportedRecord), which has a
        // direct, non-polymorphic domainrecords_id FK column back to this
        // itemtype's own id — the simplest search-option join shape,
        // 'jointype' => 'child' (confirmed against
        // Glpi\Search\Provider\SQLProvider::getLeftJoinCriteria() on
        // 11.0/bugfixes, not assumed: this jointype builds exactly
        // `LEFT JOIN <table> ON <domainrecord table>.id =
        // <table>.<linkfield>`, where <linkfield> defaults to
        // getForeignKeyFieldForTable() of this itemtype's own table —
        // `domainrecords_id`, already matching our schema even without the
        // explicit 'linkfield' below). No `beforejoin` hop needed.
        //
        // A row only exists here once RecordReconciler has actually
        // created/updated the native record at least once — a manually
        // created, never-synced record has no row at all, which this
        // LEFT JOIN naturally reads as NULL/"No" for the 'bool' datatype
        // (no separate default-value handling needed).
        //
        // 'massiveaction' => false: this value is plugin-derived, never
        // meant to be set directly by a user via bulk edit.
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_MANAGED,
            'table'         => ImportedRecord::getTable(),
            'field'         => 'is_managed',
            'linkfield'     => 'domainrecords_id',
            'name'          => __('Managed', 'domainmanager'),
            'datatype'      => 'bool',
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ];

        // Same join shape as PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_MANAGED
        // just above (single-hop 'child' join to the plugin's own ownership
        // table). 'equals'/'notequals' (Yes/No) on a plain nullable 'bool'
        // datatype are exact — confirmed live, real SQL captured — but
        // 'empty' ("is empty") is NOT clean NULL-only isolation: GLPI core's
        // 'bool' WHERE-builder deliberately falls through into the same
        // "0 OR NULL" handling as integer/decimal/count (SQLProvider.php's
        // `case "bool":`, `// no break here : use number comparaison case`),
        // and there is no per-search-option override reachable here since
        // DomainRecord is a core itemtype, not one this plugin owns. A
        // real, minor, accepted limitation — see ARCHITECTURE.md §9 Phase 7
        // addendum for the full verification and reasoning (corrects that
        // section's earlier "promising lead, not yet verified" note).
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_PROXY,
            'table'         => ImportedRecord::getTable(),
            'field'         => 'is_proxied',
            'linkfield'     => 'domainrecords_id',
            'name'          => __('Proxy status', 'domainmanager'),
            'datatype'      => 'bool',
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
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
        // §9 Phase 12: plugin-wide config, shown as a real tab of Setup >
        // General on core's own Config itemtype (same pattern as the
        // sibling `uxia` plugin's Config\Config).
        Plugin::registerClass(DomainmanagerConfig::class, ['addtabon' => Config::class]);

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
            // §9 Phase 14: locks the Domain's Registrar (Infocom's Supplier)
            // once a confirmed working registrar match exists — see
            // LockEnforcer::infocomPreUpdate().
            Infocom::class      => [LockEnforcer::class, 'infocomPreUpdate'],
        ];
        $PLUGIN_HOOKS[Hooks::PRE_ITEM_DELETE]['domainmanager'] = [
            DomainRecord::class => [LockEnforcer::class, 'domainRecordPreDelete'],
        ];
        $PLUGIN_HOOKS[Hooks::PRE_ITEM_PURGE]['domainmanager'] = [
            DomainRecord::class => [LockEnforcer::class, 'domainRecordPrePurge'],
        ];

        // Resolves to /plugins/domainmanager/Config, which redirects to the
        // Setup > General tab registered just above (§9 Phase 12).
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['domainmanager'] = 'Config';

        // §9 Phase 15 addendum: belt-and-suspenders companion to
        // Installer::pruneStaleSearchOptionCriteria() (which only rewrites
        // *persisted* glpi_savedsearches rows at install/upgrade time). Live
        // testing kept reproducing "Attempted to use invalid search options
        // from itemtype: Domain with IDs 9403" even with glpi_savedsearches
        // and glpi_savedsearches_users confirmed empty on the instance,
        // meaning the stale field reference was being carried purely via
        // $_SESSION['glpisearch'] (QueryBuilder::manageParams() persists
        // whatever criteria a request used back into session on every
        // request, including a first-touch default) — a source no one-time
        // migration can reach, since it isn't in the database at all.
        // Hooks::POST_INIT fires on every page load, early enough (session
        // is initialized, but front/*.php hasn't yet called
        // QueryBuilder::manageParams()) to strip it before it's read.
        $PLUGIN_HOOKS[Hooks::POST_INIT]['domainmanager'] = [HookHandler::class, 'scrubStaleSearchSessionCriteria'];
    }
}
