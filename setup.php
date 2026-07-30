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
use GlpiPlugin\Domainmanager\Service\DnsRecordWriteback;
use GlpiPlugin\Domainmanager\SupplierTab;

define('PLUGIN_DOMAINMANAGER_VERSION', '1.3.0-beta1');
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
// Real, filterable search options on Supplier (§9 Phase 16 "Supplier-side
// searchable fields") — see their own registration below.
define('PLUGIN_DOMAINMANAGER_SO_SUPPLIER_DOMAINS', 9411);
define('PLUGIN_DOMAINMANAGER_SO_SUPPLIER_REGISTRAR', 9412);
define('PLUGIN_DOMAINMANAGER_SO_SUPPLIER_NS_PROVIDER', 9413);
define('PLUGIN_DOMAINMANAGER_SO_SUPPLIER_REGISTRAR_COUNT', 9414);
define('PLUGIN_DOMAINMANAGER_SO_SUPPLIER_NS_PROVIDER_COUNT', 9415);
// Real, filterable search option on Domain (§9 Phase 17 "Domain identity
// header") — see its own registration below. A separate field from native
// id 1 ("Name", glpi_domains.name, Unicode-stored) rather than a merge into
// it: MySQL has no IDN function, so matching a pasted-in Punycode string
// against the Unicode name column isn't possible without this cached
// column to search against instead.
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN_NAME_ASCII', 9416);
// Real, filterable search options on Domain, one per registrar
// administrative-metadata column added by
// Installer::addRegistrarMetadataColumns() (§9 Phase 7) — see their own
// registration below. `registrar_domain_type` was dropped (commit
// "Drop Domain type field, move Transfer/EPP auth code into its place")
// so its slot is not reused here.
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN_WHOIS_PRIVACY', 9417);
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN_TRANSFER_LOCK', 9418);
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN_AUTH_CODE', 9419);
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN_DOMAIN_LOCK', 9420);
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN_AUTO_RENEW', 9421);
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN_DNSSEC', 9422);
// Real, filterable search options on Domain, one per RDAP-only column
// (§9 Phase 27 revision: Transfer lock/Domain lock/DNSSEC no longer have a
// separate RDAP column at all — RDAP fills the existing
// PLUGIN_DOMAINMANAGER_SO_DOMAIN_TRANSFER_LOCK/DOMAIN_LOCK/DNSSEC options'
// own columns directly, so ids 9425/9426/9429 originally reserved for
// "(RDAP)" duplicates of those three are unused — left as a gap in the
// 9400-9429 block rather than renumbered, so no previously-registered id
// ever silently changes meaning). `rdap_registrar_name`/`rdap_registrar_iana_id`/
// `rdap_nameservers` are deliberately not exposed here: they're read-only
// diagnostics, never a source of truth (§9 Phase 21 "Registrar-of-record
// note"), and the nameserver list isn't a simple scalar to filter on anyway.
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN_LAST_CHANGED', 9423);
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN_TRANSFER_DATE', 9424);
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN_PENDING_DELETE', 9427);
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN_PENDING_TRANSFER', 9428);
// Real, filterable search option on DomainRecord (ARCHITECTURE.md §11.12,
// Phase 32). NOT 9425/9426/9429: those look unused today but were briefly
// live (§9 Phase 26, same day superseded by Phase 27) — a previously
// -registered id must never silently change meaning, so they stay
// permanent gaps rather than being recycled. The 9400-9429 block is fully
// spoken for, so this widens it the same way 9400-9409 was widened to
// 9400-9429 originally.
define('PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_GLPI_CREATED', 9430);
// Real, filterable search option on Domain (ARCHITECTURE.md §14.2, Phase 47)
// — the Domain-level counterpart to PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_GLPI_CREATED
// above, same "Native" label/is_glpi_created-style field, backed by its own
// column on the states table rather than the records table.
define('PLUGIN_DOMAINMANAGER_SO_DOMAIN_GLPI_CREATED', 9431);

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

        // ARCHITECTURE.md §14.2 (Phase 47): "Native" — the Domain-level
        // counterpart to PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_GLPI_CREATED
        // below, backed by `is_glpi_created` on this same states table
        // (SyncEngine::sync() sets it once, at state-row creation only,
        // never touched afterward). Same single-hop 'child' join shape as
        // "Managed" above.
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAIN_GLPI_CREATED,
            'table'         => DomainState::getTable(),
            'field'         => 'is_glpi_created',
            'linkfield'     => 'domains_id',
            'name'          => __('Native', 'domainmanager'),
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
        // §9 Phase 17 "Domain identity header": plain 'text' datatype, same
        // single-hop 'child' join shape as the options above — lets a
        // Punycode string pasted from a DNS log find the domain, which
        // native id 1 ("Name") alone cannot do (see constant definition).
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAIN_NAME_ASCII,
            'table'         => DomainState::getTable(),
            'field'         => 'name_ascii',
            'linkfield'     => 'domains_id',
            'name'          => __('Punycode name', 'domainmanager'),
            'datatype'      => 'text',
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ];

        // The 5 tri-state registrar administrative-metadata flags
        // (Installer::addRegistrarMetadataColumns(), §9 Phase 7): same
        // single-hop 'child' join shape as every other Domain-side option
        // above, 'bool' datatype — same accepted "0 OR NULL" WHERE-builder
        // limitation as PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_PROXY's own
        // nullable tinyint (no per-search-option override reachable here
        // either, DomainState IS this plugin's own itemtype but the
        // limitation lives in core's SQLProvider, not something this
        // itemtype's own code path controls).
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAIN_WHOIS_PRIVACY,
            'table'         => DomainState::getTable(),
            'field'         => 'registrar_privacy_enabled',
            'linkfield'     => 'domains_id',
            'name'          => __('WHOIS privacy', 'domainmanager'),
            'datatype'      => 'bool',
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ];
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAIN_TRANSFER_LOCK,
            'table'         => DomainState::getTable(),
            'field'         => 'registrar_transfer_lock',
            'linkfield'     => 'domains_id',
            'name'          => __('Transfer lock', 'domainmanager'),
            'datatype'      => 'bool',
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ];
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAIN_DOMAIN_LOCK,
            'table'         => DomainState::getTable(),
            'field'         => 'registrar_domain_lock',
            'linkfield'     => 'domains_id',
            'name'          => __('Domain lock', 'domainmanager'),
            'datatype'      => 'bool',
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ];
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAIN_AUTO_RENEW,
            'table'         => DomainState::getTable(),
            'field'         => 'registrar_auto_renew',
            'linkfield'     => 'domains_id',
            'name'          => __('Auto-renew', 'domainmanager'),
            'datatype'      => 'bool',
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ];
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAIN_DNSSEC,
            'table'         => DomainState::getTable(),
            'field'         => 'registrar_dnssec_enabled',
            'linkfield'     => 'domains_id',
            'name'          => __('DNSSEC', 'domainmanager'),
            'datatype'      => 'bool',
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ];

        // §9 Phase 26/27 "Make the new fields searchable": the RDAP-only
        // columns, same single-hop 'child' join shape as every other
        // Domain-side option above. Transfer lock/Domain lock/DNSSEC don't
        // get their own separate search option here since Phase 27 —
        // PLUGIN_DOMAINMANAGER_SO_DOMAIN_TRANSFER_LOCK/DOMAIN_LOCK/DNSSEC
        // above already search the exact column RDAP now fills too.
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAIN_LAST_CHANGED,
            'table'         => DomainState::getTable(),
            'field'         => 'last_changed_date',
            'linkfield'     => 'domains_id',
            'name'          => __('Last changed', 'domainmanager'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ];
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAIN_TRANSFER_DATE,
            'table'         => DomainState::getTable(),
            'field'         => 'transfer_date',
            'linkfield'     => 'domains_id',
            'name'          => __('Last transfer', 'domainmanager'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ];
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAIN_PENDING_DELETE,
            'table'         => DomainState::getTable(),
            'field'         => 'pending_delete',
            'linkfield'     => 'domains_id',
            'name'          => __('Pending delete', 'domainmanager'),
            'datatype'      => 'bool',
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ];
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAIN_PENDING_TRANSFER,
            'table'         => DomainState::getTable(),
            'field'         => 'pending_transfer',
            'linkfield'     => 'domains_id',
            'name'          => __('Pending transfer', 'domainmanager'),
            'datatype'      => 'bool',
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ];

        // Transfer/EPP auth code: the raw value is a live credential (same
        // "never leaves the browser" treatment as any other secret in this
        // plugin, per the template's own "On file"/"Not on file" badge,
        // domain_panel.html.twig) — 'searchtype' is restricted to
        // ['empty'] only, so this is filterable ("which domains have a code
        // on file?") without ever exposing the value itself in a search
        // results column or criteria input.
        // `getSpecificValueToDisplay()` masks it unconditionally below.
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAIN_AUTH_CODE,
            'itemtype'      => DomainState::class,
            'table'         => DomainState::getTable(),
            'field'         => 'registrar_auth_info',
            'linkfield'     => 'domains_id',
            'name'          => __('Auth code', 'domainmanager'),
            'datatype'      => 'specific',
            'searchtype'    => ['empty'],
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

        // ARCHITECTURE.md §11.12 (Phase 32) — same single-hop 'child' join
        // shape/table as the two options above. `is_glpi_created` is a
        // non-nullable tinyint (default 0, backed by a NOT NULL column, so
        // the known "'empty' means 0 OR NULL" 'bool' limitation is harmless
        // here — every row genuinely has a real value). Not the same thing
        // as "Managed" above: a record can be Managed without being
        // GLPI-created (imported by sync) or GLPI-created and Managed at
        // the same time (written via this feature, then reconciled).
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_GLPI_CREATED,
            'table'         => ImportedRecord::getTable(),
            'field'         => 'is_glpi_created',
            'linkfield'     => 'domainrecords_id',
            'name'          => __('Native', 'domainmanager'),
            'datatype'      => 'bool',
            'massiveaction' => false,
            'joinparams'    => [
                'jointype' => 'child',
            ],
        ];
    }

    if ($itemtype === Supplier::class) {
        $options[] = [
            'id'   => 'domainmanager',
            'name' => __('Domain Manager', 'domainmanager'),
        ];

        // §9 Phase 16 "Supplier-side searchable fields", revised: modeled
        // directly on core's own `CommonITILTask::rawSearchOptionsToAdd()`
        // pair for Tickets — one 'itemlink' field showing the actual
        // related items (there: task "Description", id 26; here: the
        // domain names themselves) plus one 'count' field for filtering/
        // sorting by quantity (there: "Number of tasks", id 28; here:
        // "Number of domains (...)"). Confirmed against the live 11.0.8
        // source (`/var/www/glpi/src/CommonITILTask.php`) rather than
        // assumed from older docs.
        //
        // All four below are reverse one-hop joins — many rows on another
        // table point back at this Supplier's id, the opposite cardinality
        // from every Domain-side option above — so each needs
        // 'forcegroupby' (and 'usehaving' for the ones used in a HAVING-
        // filtered/count context) the same way core's reverse-count/
        // reverse-list options do (e.g. Software's "Number of
        // installations", Ticket's "Parent tickets"/id 50).
        //
        // "Domains" (total, union of both roles) stays count-only: it
        // reuses DomainState's own registrar_suppliers_id mirror column
        // (§0.1, kept in sync with the live Infocom link by
        // HookHandler::infocomSaved()) to OR both roles in one join, since
        // a single search-option join can only encode one OR'd pair of
        // columns on one table — there is no single table that could carry
        // an actual combined domain-name *list* the same way (that would
        // need two different tables' rows unioned, which the search
        // framework's one-join-per-option/beforejoin-chain model can't
        // express). Good enough for "is this supplier worth a closer look"
        // filtering; it is not the authoritative per-domain view that's on
        // the Supplier's own Domain Manager tab
        // (DomainState::getDomainsForSupplier(), which cross-checks the
        // mirror against the live Infocom value row by row).
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_SUPPLIER_DOMAINS,
            'table'         => DomainState::getTable(),
            'field'         => 'id',
            'name'          => __('Domains', 'domainmanager'),
            'datatype'      => 'count',
            'forcegroupby'  => true,
            'usehaving'     => true,
            'massiveaction' => false,
            'joinparams'    => [
                'jointype'  => 'child',
                'linkfield' => 'registrar_suppliers_id',
                'condition' => 'OR NEWTABLE.`dns_suppliers_id` = REFTABLE.`id`',
            ],
        ];

        // "Registrar": the actual domain names (clickable, 'itemlink'),
        // reading the live, authoritative link (glpi_infocoms, the same
        // one that puts a Domain on this Supplier's native "Items" tab)
        // rather than DomainState's mirror — no state row needs to exist
        // at all for a domain to show up here. Two-hop join: Supplier ->
        // glpi_infocoms (child, default linkfield 'suppliers_id' already
        // matches, restricted to itemtype='Domain' since Infocom is
        // polymorphic across itemtypes) -> glpi_domains (default/
        // 'standard' outer join, explicit linkfield 'items_id' overriding
        // the default 'domains_id' guess since Infocom's own FK column to
        // its target item is the generic, itemtype-agnostic 'items_id').
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_SUPPLIER_REGISTRAR,
            'table'         => 'glpi_domains',
            'field'         => 'name',
            'linkfield'     => 'items_id',
            'name'          => __('Registrar', 'domainmanager'),
            'datatype'      => 'itemlink',
            'forcegroupby'  => true,
            'usehaving'     => true,
            'massiveaction' => false,
            'joinparams'    => [
                'beforejoin' => [
                    'table'      => 'glpi_infocoms',
                    'joinparams' => [
                        'jointype'  => 'child',
                        'condition' => "AND NEWTABLE.`itemtype` = 'Domain'",
                    ],
                ],
            ],
        ];

        // "Number of domains (Registrar)": the 'count' companion to the
        // "Registrar" list above — same two-hop relationship, restated as
        // a single-hop 'count' join directly to glpi_infocoms (counting
        // Infocom rows is equivalent to counting the Domains they point at,
        // one row per Domain, and avoids a redundant beforejoin/count
        // through glpi_domains itself).
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_SUPPLIER_REGISTRAR_COUNT,
            'table'         => 'glpi_infocoms',
            'field'         => 'id',
            'name'          => __('Number of domains (Registrar)', 'domainmanager'),
            'datatype'      => 'count',
            'forcegroupby'  => true,
            'usehaving'     => true,
            'massiveaction' => false,
            'joinparams'    => [
                'jointype'  => 'child',
                'linkfield' => 'suppliers_id',
                'condition' => "AND NEWTABLE.`itemtype` = 'Domain'",
            ],
        ];

        // "NS Provider": the actual domain names (clickable, 'itemlink'),
        // sync-dependent by nature (§0.1 docblock on
        // DomainState::getDomainsForSupplier()) — a domain only shows up
        // here once at least one real sync has resolved this Supplier as
        // the DNS provider. Two-hop join: Supplier -> DomainState (child,
        // explicit linkfield 'dns_suppliers_id' overriding the default
        // 'suppliers_id' guess) -> glpi_domains (default/'standard' outer
        // join, linkfield 'domains_id' — DomainState's real FK column to
        // Domain, which already matches the default guess for this table
        // name so no override is strictly needed, kept explicit for
        // symmetry with the Registrar option above).
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_SUPPLIER_NS_PROVIDER,
            'table'         => 'glpi_domains',
            'field'         => 'name',
            'linkfield'     => 'domains_id',
            'name'          => __('NS Provider', 'domainmanager'),
            'datatype'      => 'itemlink',
            'forcegroupby'  => true,
            'usehaving'     => true,
            'massiveaction' => false,
            'joinparams'    => [
                'beforejoin' => [
                    'table'      => DomainState::getTable(),
                    'joinparams' => [
                        'jointype'  => 'child',
                        'linkfield' => 'dns_suppliers_id',
                    ],
                ],
            ],
        ];

        // "Number of domains (NS Provider)": the 'count' companion to the
        // "NS Provider" list above, same single-hop shape as before this
        // revision.
        $options[] = [
            'id'            => PLUGIN_DOMAINMANAGER_SO_SUPPLIER_NS_PROVIDER_COUNT,
            'table'         => DomainState::getTable(),
            'field'         => 'id',
            'name'          => __('Number of domains (NS Provider)', 'domainmanager'),
            'datatype'      => 'count',
            'forcegroupby'  => true,
            'usehaving'     => true,
            'massiveaction' => false,
            'joinparams'    => [
                'jointype'  => 'child',
                'linkfield' => 'dns_suppliers_id',
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

        // §9 Phase 36: POST_ITEM_FORM above only fires on the Domain item's
        // own main form tab — POST_SHOW_TAB fires for every *other* tab
        // (Records, Historical, …), needed so the "managed by Domain
        // Manager" indicator (and, on the Records tab, hiding the native
        // add controls) shows up regardless of which tab a user lands on
        // first. See DomainForm::onShowTab().
        $PLUGIN_HOOKS[Hooks::POST_SHOW_TAB]['domainmanager'] = [DomainForm::class, 'onShowTab'];

        // Registrar assignment mirrors Infocom's own "Supplier" field
        // (read-only in the Domain Manager panel, §6.2) rather than being a
        // plugin-owned editable field — see HookHandler::infocomSaved().
        $PLUGIN_HOOKS[Hooks::ITEM_ADD]['domainmanager'] = [
            Infocom::class => [HookHandler::class, 'infocomSaved'],
            // §9 Phase 17 "Domain identity header": keeps the state row's
            // cached Punycode form (name_ascii) in sync for search.
            Domain::class  => [HookHandler::class, 'domainSaved'],
            // ARCHITECTURE.md §11.7 (Phase 34b): row now has a local id —
            // attach ImportedRecord/ImportLock/history for a record
            // created via native-tab write-back (see the paired
            // PRE_ITEM_ADD entry below, which does the actual IONOS push).
            DomainRecord::class => [DnsRecordWriteback::class, 'onPostAdd'],
        ];
        $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['domainmanager'] = [
            Infocom::class => [HookHandler::class, 'infocomSaved'],
            Domain::class  => [HookHandler::class, 'domainSaved'],
        ];

        // ARCHITECTURE.md §11.7 (Phase 34b): pushes createRecord() to IONOS
        // before the local row exists, aborting the local add on failure —
        // see DnsRecordWriteback::onPreAdd().
        $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['domainmanager'] = [
            DomainRecord::class => [DnsRecordWriteback::class, 'onPreAdd'],
        ];

        $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['domainmanager'] = [
            Domain::class       => [HookHandler::class, 'domainPreUpdate'],
            // ARCHITECTURE.md §11.7 (Phase 34b): now also the write-back
            // push for writable types on an IONOS-managed domain, via
            // DnsRecordWriteback::onPreUpdate() — every other record keeps
            // the original plugin-import lock unchanged.
            DomainRecord::class => [LockEnforcer::class, 'domainRecordPreUpdate'],
            // §9 Phase 14: locks the Domain's Registrar (Infocom's Supplier)
            // once a confirmed working registrar match exists — see
            // LockEnforcer::infocomPreUpdate().
            Infocom::class      => [LockEnforcer::class, 'infocomPreUpdate'],
        ];
        $PLUGIN_HOOKS[Hooks::PRE_ITEM_DELETE]['domainmanager'] = [
            // ARCHITECTURE.md §11.7/§11.11 (Phase 34b): the soft-delete is
            // what pushes the upstream IONOS deletion — see
            // DnsRecordWriteback::onPreDelete(), called from here via
            // LockEnforcer::domainRecordPreDelete().
            DomainRecord::class => [LockEnforcer::class, 'domainRecordPreDelete'],
        ];
        $PLUGIN_HOOKS[Hooks::PRE_ITEM_PURGE]['domainmanager'] = [
            DomainRecord::class => [LockEnforcer::class, 'domainRecordPrePurge'],
        ];

        // ARCHITECTURE.md §14.3 (Phase 48 bug fix): paired counterpart to the
        // PRE_ITEM_DELETE entry above — recreates the record upstream when a
        // write-back-managed trashed record is restored, since the trash
        // itself already pushed a real deletion (DnsRecordWriteback::
        // onPreRestore()); without this, restoring only flipped is_deleted
        // locally and the next sync re-trashed it, looking like a no-op.
        $PLUGIN_HOOKS[Hooks::PRE_ITEM_RESTORE]['domainmanager'] = [
            DomainRecord::class => [DnsRecordWriteback::class, 'onPreRestore'],
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
