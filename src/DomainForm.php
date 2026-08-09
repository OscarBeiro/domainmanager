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

use Domain;
use DomainRecord;
use DomainRecordType;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Domainmanager\Config\Config;
use GlpiPlugin\Domainmanager\Service\DnsRecordWriteback;
use GlpiPlugin\Domainmanager\Service\DomainStatusResolver;
use GlpiPlugin\Domainmanager\Service\NsResolver;
use Session;
use Supplier;

/**
 * post_item_form panel inside the Domain generic form (§6.2): read-only
 * Registrar (mirrors Infocom's Supplier field), detected DNS provider,
 * status card, Update Now, lock JS
 */
class DomainForm
{
    /**
     * `Hooks::POST_ITEM_FORM` entry point (fires for every itemtype form —
     * a plain, non-itemtype-keyed hook, so this single callback dispatches
     * on `$item`'s actual class). Handles both the Domain item's own main
     * form (`injectDomain()`, unchanged since §6.2) and, as of §9 Phase 37,
     * a `DomainRecord`'s own edit form (`injectDomainRecord()`) — the
     * "managed by Domain Manager, saving here goes live" banner and the
     * cosmetic field-lock for records the user isn't allowed to write back.
     *
     * @param  array $params {item, options}
     * @return void
     */
    public static function inject(array $params): void
    {
        $item = $params['item'] ?? null;
        if ($item instanceof Domain) {
            self::injectDomain($item);
            return;
        }
        if ($item instanceof DomainRecord) {
            self::injectDomainRecord($item);
        }
    }

    /**
     * @param  Domain $item
     * @return void
     */
    private static function injectDomain(Domain $item): void
    {
        if (!Session::haveRight('domain', READ)) {
            return;
        }

        $domains_id = (int) $item->getID();
        $is_new     = $item->isNewItem();
        $can_update = $is_new
            ? Domain::canCreate()
            : $item->can($domains_id, UPDATE);

        $state = $is_new ? null : DomainState::getForDomain($domains_id);

        // A domain never picked up by sync gets no Domain Manager panel at all
        // (not even the "not managed" message, which is reserved for a domain
        // that has a state row with is_managed = 0) — §9 Phase 88.
        if (!$is_new && $state === null) {
            return;
        }

        // Locked fields disabling (cosmetic; server-side is authoritative)
        $locked_fields = [];
        if (
            !$is_new
            && !Session::haveRight(Profile::UNLOCK_RIGHT, Profile::RIGHT_UNLOCK_IMPORTED)
        ) {
            $locked_fields = ImportLock::getLockedFieldNames(Domain::class, $domains_id);
        }

        // Registrar is read-only here: it directly mirrors Infocom's own
        // "Supplier" field for this Domain (§0.1/§6.2) — there is exactly
        // one place to set it, HookHandler::infocomSaved() keeps this in
        // sync whenever that Infocom field changes.
        $registrar_supplier = null;
        if ($state !== null && (int) $state->fields['registrar_suppliers_id'] > 0) {
            $supplier = new Supplier();
            if ($supplier->getFromDB((int) $state->fields['registrar_suppliers_id'])) {
                $registrar_supplier = $supplier;
            }
        }

        $dns_supplier = null;
        if ($state !== null && (int) $state->fields['dns_suppliers_id'] > 0) {
            $supplier = new Supplier();
            if ($supplier->getFromDB((int) $state->fields['dns_suppliers_id'])) {
                $dns_supplier = $supplier;
            }
        }

        // §9 Phase 10: read-only Punycode/ACE form of the domain's stored
        // (possibly Unicode/IDN) name — shown only when it actually differs
        // from `name`, so a plain ASCII domain's panel isn't cluttered with
        // a redundant duplicate value.
        $punycode = null;
        if (!$is_new) {
            $ascii = IdnNormalizer::toAscii((string) $item->fields['name']);
            if ($ascii !== '' && strcasecmp($ascii, (string) $item->fields['name']) !== 0) {
                $punycode = $ascii;
            }
        }

        // §9 Phase 23: nameserver cross-check (RDAP's reported list vs. a
        // live lookup) is display-only, informational (§9 Phase 22 note —
        // RDAP's nameserver list never feeds DomainRecord/RecordReconciler).
        // Only looked up when RDAP has actually reported something to
        // compare against, so a domain never RDAP-checked doesn't pay for
        // a live DNS query it can't do anything useful with.
        $rdap_nameservers = [];
        if ($state !== null && !empty($state->fields['rdap_nameservers'])) {
            $decoded = json_decode((string) $state->fields['rdap_nameservers'], true);
            if (is_array($decoded)) {
                $rdap_nameservers = $decoded;
            }
        }
        $live_nameservers = $rdap_nameservers !== []
            ? (new NsResolver())->getNameservers((string) ($item->fields['name'] ?? ''))
            : [];

        // §9 Phase 28: mismatch detection moved out of Twig and normalized
        // here — the raw display lists above are kept exactly as reported
        // (order/casing as each source returned them), but *comparing* them
        // needs to ignore harmless differences that aren't a real
        // discrepancy (§9 Phase 28 addendum, found live: RDAP and a live
        // lookup reporting the identical nameserver set in a different
        // order tripped a naive `!=` set comparison; a registrar's RDAP
        // name carrying its legal suffix, e.g. "DINAHOSTING S.L." vs. the
        // Supplier's plain "dinahosting", tripped a naive case-insensitive
        // string comparison).
        $ns_mismatch = $rdap_nameservers !== []
            && $live_nameservers !== []
            && self::normalizeNsList($rdap_nameservers) !== self::normalizeNsList($live_nameservers);

        $registrar_mismatch = $state !== null
            && $state->fields['rdap_registrar_name']
            && $registrar_supplier !== null
            && !self::registrarNamesLikelyMatch((string) $state->fields['rdap_registrar_name'], $registrar_supplier->getName());

        $is_managed = $state !== null && (bool) $state->fields['is_managed'];

        TemplateRenderer::getInstance()->display('@domainmanager/domain_panel.html.twig', [
            'is_new'             => $is_new,
            'can_update'         => $can_update,
            'is_managed'         => $is_managed,
            'state'              => $state?->fields,
            'registrar_supplier' => $registrar_supplier,
            'dns_supplier'       => $dns_supplier,
            'domain_name'        => $item->fields['name'] ?? '',
            'punycode'           => $punycode,
            'status_labels'      => DomainStatusResolver::getStatusLabels(),
            'status_classes'     => DomainStatusResolver::getStatusClasses(),
            'domains_id'         => $domains_id,
            'infocom_tab_url'    => $is_new ? '' : (Domain::getFormURLWithID($domains_id) . '&forcetab=Infocom$1'),
            'repository_url'     => PLUGIN_DOMAINMANAGER_REPOSITORY_URL,
            'locked_fields'      => $locked_fields,
            'provider_unknown'   => NsProviderRegistry::PROVIDER_UNKNOWN,
            'rdap_nameservers'   => $rdap_nameservers,
            'live_nameservers'   => $live_nameservers,
            'ns_mismatch'        => $ns_mismatch,
            'registrar_mismatch' => $registrar_mismatch,
        ]);

        self::renderManagedIndicator($is_managed);
    }

    /**
     * `DomainRecord`'s own edit form (§9 Phase 37) — reached via
     * `inject()`'s dispatch above, not a separate hook registration (GLPI
     * only allows one `POST_ITEM_FORM` callback per plugin). Renders:
     * - a "managed by Domain Manager, this updates live" banner when the
     *   record is plugin-imported, of a writable type (A/AAAA/CNAME/TXT),
     *   the domain's DNS is under write-back, and the user holds the
     *   per-type UPDATE right;
     * - otherwise, whenever the record is plugin-imported at all, a
     *   cosmetic field-lock (same convention as `injectDomain()`'s own
     *   `locked_fields`) — covers both non-writable types (NS/MX/…) and a
     *   writable type the user simply lacks the right for. Prevents the
     *   "edit successfully, only to see it silently stripped after
     *   redirect" experience `LockEnforcer::domainRecordPreUpdate()` already
     *   produces server-side (authoritative, unchanged by this).
     * - a delete/purge confirmation requiring an explicit "yes" before
     *   submitting, whenever the user holds the per-type DELETE right (the
     *   record would otherwise just be silently blocked server-side by
     *   `LockEnforcer::blockRecordRemoval()`, same authoritative check);
     * - the Purge button itself hidden (record is already in the trash)
     *   whenever the user lacks the per-type PURGE right — GLPI renders it
     *   unconditionally from the generic itemtype right, so without this it
     *   silently no-ops server-side instead.
     *
     * @param  DomainRecord $item
     * @return void
     */
    private static function injectDomainRecord(DomainRecord $item): void
    {
        if (!Session::haveRight('domain', READ)) {
            return;
        }

        if ($item->isNewItem()) {
            // §9 Phase 37 addendum (found live, 2026-07-29): the domain isn't
            // known yet on a blank new-item form (it's still a dropdown), so
            // this can only be a generic, always-shown notice — not gated on
            // any specific domain's write-back state/rights the way the
            // existing-record banner below is. Covers the one entry point
            // Phase 37's original scope missed: GLPI's own generic "New
            // Domain record" quick-add (global Domains-records list /
            // top-nav "+"), which reaches the exact same `onPreAdd()` live
            // push as every other entry point (§11.7) but previously had no
            // warning at all before a submit.
            TemplateRenderer::getInstance()->display('@domainmanager/domainrecord_new_notice.html.twig', [
                'rand' => mt_rand(),
            ]);
            return;
        }

        $records_id = (int) $item->getID();
        if (!ImportedRecord::isPluginOwned($records_id)) {
            // Not a plugin-imported record at all — entirely native,
            // nothing for this panel to add.
            return;
        }

        $domains_id = (int) $item->fields['domains_id'];
        $type       = self::recordTypeName((int) $item->fields['domainrecordtypes_id']);
        $state      = DomainState::getForDomain($domains_id);

        // The stored `name` is always the absolute FQDN (matching every
        // driver's own ZoneRecord.name and DnsRecordWriterInterface's
        // contract) — GLPI core's own DomainRecord::getDisplayName() is the
        // sanctioned way to show just the relative label against the
        // linked domain, already used by core's own "link a record"
        // dropdown. The plain generic form was never running the locked
        // `name` field's displayed value through it (found live 2026-08-03:
        // a "www" record under zzz.gal showed "www.zzz.gal" in its own
        // disabled field). Cosmetic only — the field is locked either way,
        // so nothing here can post a different value back.
        $recordDomain = new Domain();
        $displayName  = $recordDomain->getFromDB($domains_id)
            ? DomainRecord::getDisplayName($recordDomain, (string) $item->fields['name'])
            : null;
        $dns_editable = $state !== null && DnsRecordWriteback::isDomainDnsEditable($state);
        $is_writable_type = $type !== null && in_array($type, DnsRecordWriteback::writableTypes(), true);
        // §15.3 Phase 53: the global write kill switch overrides per-type
        // rights everywhere else in this class, so the controls it hides
        // must reflect it too — otherwise a Save/Delete would appear
        // enabled only to be refused server-side by
        // DnsRecordWriteback::readOnlyModeError().
        $read_only = Config::isReadOnlyMode();

        $can_update = $dns_editable && $is_writable_type && !$read_only && DnsRecordWriteback::hasTypeRight($type, UPDATE, $domains_id);
        $can_delete = $dns_editable && $is_writable_type && !$read_only && DnsRecordWriteback::hasTypeRight($type, DELETE, $domains_id);
        // Purge (emptying the trash) is gated purely on the per-type PURGE
        // bit, independent of $dns_editable/$is_writable_type — mirrors
        // LockEnforcer::blockRecordRemoval()'s own unconditional check, so
        // the button is hidden exactly when a submit would otherwise be
        // silently rejected server-side.
        $can_purge = DnsRecordWriteback::hasPurgeRight($item);

        // §9 Phase 49: the proxy-status checkbox is only worth injecting
        // when this specific record could ever be proxied (A/AAAA/CNAME —
        // TXT/MX/NS never are) *and* the user can actually push a change
        // (same $can_update gate as name/data/ttl above).
        $can_toggle_proxy = $can_update
            && DnsRecordWriteback::isProxiableType($type)
            && DnsRecordWriteback::supportsProxyToggle($state);
        $imported = ImportedRecord::getForDomainRecord($records_id);
        $current_proxied = $imported !== null && $imported->fields['is_proxied'] !== null
            ? (bool) $imported->fields['is_proxied']
            : null;
        // Distinct from the generic "locked_fields" notice below: this
        // record would otherwise be writable (right + type both check out)
        // — the kill switch, not a rights gap, is the reason.
        $read_only_locked = $read_only && $dns_editable && $is_writable_type;

        // §9 research "persist proxy addresses + TTL-auto flag": only worth
        // showing when TTL is actually an editable input on this render —
        // a locked TTL field already tells the story on its own, and the
        // fact itself (TTL 1 == automatic) is Cloudflare-specific, checked
        // via the same instanceof-capability pattern as $can_toggle_proxy
        // above, never a hardcoded driver name.
        $ttl_auto_note = $can_update && DnsRecordWriteback::supportsTtlAutoSentinel($state);

        TemplateRenderer::getInstance()->display('@domainmanager/domainrecord_edit_panel.html.twig', [
            'can_update'        => $can_update,
            'can_delete'        => $can_delete,
            'can_purge'         => $can_purge,
            'read_only_locked'  => $read_only_locked,
            'can_toggle_proxy' => $can_toggle_proxy,
            'current_proxied'  => $current_proxied,
            'ttl_auto_note' => $ttl_auto_note,
            'display_name'  => $displayName,
            'supplier_name' => $dns_editable ? DnsRecordWriteback::writableSupplierName($domains_id) : null,
            // Cosmetic-only (server-side is authoritative, see docblock
            // above): every editable field disabled unless the user can
            // actually write this record back.
            'locked_fields' => $can_update ? [] : ['data', 'ttl'],
            // Always locked, even for a user with write-back rights.
            // domains_id/domainrecordtypes_id mirror LockEnforcer's own
            // STRUCTURAL_RECORD_FIELD (domains_id) server-side — changing
            // the domain link or the record type re-parents/retypes a
            // record the plugin is tracking by (domains_id, remote_id).
            // name is cosmetic-only here (DnsRecordWriteback::onPreUpdate()
            // never pushes a name change upstream — it always pushes the
            // record's current DB name, never $item->input['name']), so
            // editing it in the form would silently do nothing; locking it
            // makes that explicit instead of surprising. date_creation is
            // likewise never something an edit should touch.
            'structural_locked_fields' => ['domains_id', 'domainrecordtypes_id', 'name', 'date_creation'],
        ]);
    }

    /**
     * `Hooks::POST_SHOW_TAB` entry point — fires for every *non-main* tab of
     * a Domain item (Records, Historical, Associated items, …), unlike
     * `inject()` above which only runs on the Domain item's own main form
     * tab. Needed so the "managed by Domain Manager" indicator (§9 Phase 36)
     * shows up regardless of which tab a user lands on first, and so the
     * Records tab specifically can also render the custom add panel (§9
     * Phase 37, `renderRecordWritePanel()`).
     *
     * @param  array $params {item, options}
     * @return void
     */
    public static function onShowTab(array $params): void
    {
        $item = $params['item'] ?? null;
        if (!$item instanceof Domain || $item->isNewItem() || !Session::haveRight('domain', READ)) {
            return;
        }

        $domains_id = (int) $item->getID();
        $state      = DomainState::getForDomain($domains_id);
        $is_managed = $state !== null && (bool) $state->fields['is_managed'];

        self::renderManagedIndicator($is_managed);

        $tab_itemtype = $params['options']['itemtype'] ?? '';
        if ($tab_itemtype === DomainRecord::class) {
            if ($state !== null && $is_managed) {
                self::renderRecordWritePanel($domains_id, $state);
            }
            self::renderProxyIndicators($domains_id);
        }
    }

    /**
     * Records tab: `DomainRecord::showForDomain()` is core's own hardcoded
     * table (`components/datatable.html.twig` with a fixed Type/Name/TTL/Target
     * column set) — there's no hook to add a column to it, so proxy status
     * (`is_proxied` on `ImportedRecord`, keyed by `domains_id` directly on
     * that table) is instead overlaid client-side: a cloud icon is appended
     * next to any proxied record's Name link, matched by the record id
     * already present in that link's native `getFormURLWithID()` href.
     *
     * §9 research "persist proxy addresses + TTL-auto flag, then relocate
     * the display": also carries each row's persisted `proxy_addresses`
     * (moved from the Name cell to a second line under the Target cell) and
     * `is_ttl_auto` (rendered as "Automatic" in the TTL cell) — both purely
     * data-presence-gated, never on which driver populated them.
     *
     * @param  int $domains_id
     * @return void
     */
    private static function renderProxyIndicators(int $domains_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'SELECT' => ['glpi_plugin_domainmanager_records.domainrecords_id', 'is_proxied', 'proxy_addresses', 'is_ttl_auto'],
            'FROM'   => 'glpi_plugin_domainmanager_records',
            // Phase 78 (ARCHITECTURE.md §19.8): join native DomainRecord and
            // require is_deleted = 0. Without this, a record trashed by
            // RecordReconciler (upstream deletion) keeps whatever
            // is_proxied/proxy_addresses it last had here — untouched by the
            // trash path, which only soft-deletes the native row — and if
            // GLPI's own trash-bin view for this tab is toggled on, the
            // deleted row still gets overlaid with that stale proxy state.
            'INNER JOIN' => [
                DomainRecord::getTable() => [
                    'FKEY' => [
                        DomainRecord::getTable()                 => 'id',
                        'glpi_plugin_domainmanager_records'      => 'domainrecords_id',
                    ],
                ],
            ],
            'WHERE'  => [
                'glpi_plugin_domainmanager_records.domains_id' => $domains_id,
                DomainRecord::getTable() . '.is_deleted'       => 0,
                'OR'                                           => [
                    'is_proxied'  => 1,
                    'is_ttl_auto' => 1,
                ],
            ],
        ]);

        $proxied_ids     = [];
        $proxy_addresses = [];
        $ttl_auto_ids    = [];
        foreach ($iterator as $row) {
            $id = (int) $row['domainrecords_id'];

            if ((int) $row['is_proxied'] === 1) {
                $proxied_ids[] = $id;
                $addresses     = json_decode((string) $row['proxy_addresses'], true);
                if (is_array($addresses) && $addresses !== []) {
                    $proxy_addresses[$id] = array_values($addresses);
                }
            }

            if ((int) $row['is_ttl_auto'] === 1) {
                $ttl_auto_ids[] = $id;
            }
        }

        if ($proxied_ids === [] && $ttl_auto_ids === []) {
            return;
        }

        TemplateRenderer::getInstance()->display('@domainmanager/domainrecord_proxy_indicators.html.twig', [
            'proxied_ids'     => $proxied_ids,
            'proxy_addresses' => $proxy_addresses,
            'ttl_auto_ids'    => $ttl_auto_ids,
        ]);
    }

    /**
     * Shared by `injectDomain()` (main tab) and `onShowTab()` (every other
     * tab) so the "managed" icon script only ever has one implementation to
     * keep in sync (§9 Phase 36).
     *
     * @param  bool $is_managed
     * @return void
     */
    private static function renderManagedIndicator(bool $is_managed): void
    {
        if (!$is_managed) {
            return;
        }

        TemplateRenderer::getInstance()->display('@domainmanager/domain_managed_indicator.html.twig', []);
    }

    /**
     * Records tab (§9 Phase 37, superseding Phase 36's blanket
     * hide-when-no-rights-at-all approach): whenever this domain's DNS is
     * genuinely under write-back (any driver implementing
     * `DnsRecordWriterInterface` — not IONOS-specific), core's native "Link
     * a record"/"New Domain record for this item" controls
     * (`DomainRecord::showForDomain()`) are always hidden and replaced by a
     * Domain-Manager-branded add form scoped to only the types this user
     * actually holds CREATE for — so a click there always means "this
     * creates a record live", never a silent local-only add or an outright
     * rejection by `DnsRecordWriteback::onPreAdd()`. A domain whose DNS
     * isn't write-back-editable (no supported driver configured, or the
     * driver doesn't implement the interface) leaves the native controls
     * completely untouched — this panel only ever applies where write-back
     * is real.
     *
     * @param  int         $domains_id
     * @param  DomainState $state
     * @return void
     */
    private static function renderRecordWritePanel(int $domains_id, DomainState $state): void
    {
        if (!DnsRecordWriteback::isDomainDnsEditable($state)) {
            return;
        }

        // §15.3 Phase 53: independent of per-type CREATE rights — see
        // injectDomainRecord()'s identical rationale.
        $read_only = Config::isReadOnlyMode();
        $creatable_types = $read_only ? [] : DnsRecordWriteback::creatableTypesForDomain($domains_id);

        $type_options = [];
        foreach ($creatable_types as $name) {
            $type = new DomainRecordType();
            if ($type->getFromDBByCrit(['name' => $name])) {
                $type_options[(int) $type->fields['id']] = $name;
            }
        }

        $supplier_name = DnsRecordWriteback::writableSupplierName($domains_id);
        $live_notice = $supplier_name !== null
            ? sprintf(__('This creates the record live at %s. There is no undo.', 'domainmanager'), $supplier_name)
            : __('This creates the record live at the DNS provider. There is no undo.', 'domainmanager');

        TemplateRenderer::getInstance()->display('@domainmanager/domainrecord_add_panel.html.twig', [
            'domains_id'   => $domains_id,
            'type_options' => $type_options,
            'read_only'    => $read_only,
            'live_notice'  => $live_notice,
            'add_form_url' => DomainRecord::getFormURLWithID(0),
            // §9 research "persist proxy addresses + TTL-auto flag": same
            // instanceof-capability check as injectDomainRecord()'s own
            // $ttl_auto_note — this panel's TTL field is always editable
            // (new record), so only the driver capability needs checking.
            'ttl_auto_note' => DnsRecordWriteback::supportsTtlAutoSentinel($state),
        ]);
    }

    /**
     * @param  int $type_id
     * @return string|null
     */
    private static function recordTypeName(int $type_id): ?string
    {
        if ($type_id <= 0) {
            return null;
        }

        $type = new DomainRecordType();
        if ($type->getFromDB($type_id)) {
            return $type->fields['name'] ?? null;
        }

        return null;
    }

    /**
     * Normalizes a nameserver list for *comparison only* (lowercase,
     * trailing-dot stripped, deduplicated, sorted) — never used for
     * display, where each source's own reported form is shown as-is.
     *
     * @param  string[] $hosts
     * @return string[]
     */
    private static function normalizeNsList(array $hosts): array
    {
        $normalized = array_map(
            static fn($host) => strtolower(rtrim(trim((string) $host), '.')),
            $hosts,
        );
        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * Whether an RDAP-reported registrar name and the Infocom Supplier's
     * name plausibly refer to the same organization — a plain
     * case-insensitive `==` is too strict, since RDAP commonly includes the
     * registrar's full legal form (e.g. "DINAHOSTING S.L.") while the
     * Supplier is usually named just "dinahosting". Strips everything but
     * letters/digits from both and checks substring containment either
     * way, rather than trying to maintain an exhaustive legal-suffix list
     * (S.L./S.A./Inc./LLC/Ltd/GmbH/…) that would never really be complete.
     *
     * @param  string $rdap_name
     * @param  string $supplier_name
     * @return bool
     */
    private static function registrarNamesLikelyMatch(string $rdap_name, string $supplier_name): bool
    {
        $normalize = static fn(string $value): string => strtolower(preg_replace('/[^a-z0-9]/i', '', $value) ?? '');

        $rdap_normalized     = $normalize($rdap_name);
        $supplier_normalized = $normalize($supplier_name);

        if ($rdap_normalized === '' || $supplier_normalized === '') {
            return false;
        }

        return str_contains($rdap_normalized, $supplier_normalized)
            || str_contains($supplier_normalized, $rdap_normalized);
    }
}
