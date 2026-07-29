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
use Glpi\Application\View\TemplateRenderer;
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
     * post_item_form hook entry point (fires for every itemtype form)
     *
     * @param  array $params {item, options}
     * @return void
     */
    public static function inject(array $params): void
    {
        $item = $params['item'] ?? null;
        if (!$item instanceof Domain || !Session::haveRight('domain', READ)) {
            return;
        }

        $domains_id = (int) $item->getID();
        $is_new     = $item->isNewItem();
        $can_update = $is_new
            ? Domain::canCreate()
            : $item->can($domains_id, UPDATE);

        $state = $is_new ? null : DomainState::getForDomain($domains_id);

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

        TemplateRenderer::getInstance()->display('@domainmanager/domain_panel.html.twig', [
            'is_new'             => $is_new,
            'can_update'         => $can_update,
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

        self::renderManagedIndicator($state !== null && (bool) $state->fields['is_managed'], false);
    }

    /**
     * `Hooks::POST_SHOW_TAB` entry point — fires for every *non-main* tab of
     * a Domain item (Records, Historical, Associated items, …), unlike
     * `inject()` above which only runs on the Domain item's own main form
     * tab. Needed so the "managed by Domain Manager" indicator (§9 Phase 36)
     * shows up regardless of which tab a user lands on first, and so the
     * Records tab specifically can also decide whether to hide GLPI core's
     * own native "Link a record"/"New Domain record for this item"
     * controls (`DomainRecord::showForDomain()`) — confusing on an
     * IONOS-managed domain when the user holds none of the per-type DNS
     * write-back rights (§11.6), since a native add there would either be
     * silently local-only or get rejected outright by
     * `DnsRecordWriteback::onPreAdd()`. Left alone whenever the user *does*
     * hold at least one such right, per the maintainer's own call: buttons
     * stay if editing is actually possible.
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

        $hide_native_buttons = false;
        $tab_itemtype        = $params['options']['itemtype'] ?? '';
        if ($is_managed && $state !== null && $tab_itemtype === DomainRecord::class) {
            $hide_native_buttons = DnsRecordWriteback::isDomainDnsEditable($state)
                && !DnsRecordWriteback::userMayCreateAnyType();
        }

        self::renderManagedIndicator($is_managed, $hide_native_buttons);
    }

    /**
     * Shared by `inject()` (main tab) and `onShowTab()` (every other tab) so
     * the "managed" icon/hide-buttons script only ever has one
     * implementation to keep in sync (§9 Phase 36).
     *
     * @param  bool $is_managed
     * @param  bool $hide_native_buttons
     * @return void
     */
    private static function renderManagedIndicator(bool $is_managed, bool $hide_native_buttons): void
    {
        if (!$is_managed && !$hide_native_buttons) {
            return;
        }

        TemplateRenderer::getInstance()->display('@domainmanager/domain_managed_indicator.html.twig', [
            'is_managed'          => $is_managed,
            'hide_native_buttons' => $hide_native_buttons,
        ]);
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
