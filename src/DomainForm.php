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
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Domainmanager\Service\DomainStatusResolver;
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

        TemplateRenderer::getInstance()->display('@domainmanager/domain_panel.html.twig', [
            'is_new'             => $is_new,
            'can_update'         => $can_update,
            'state'              => $state?->fields,
            'registrar_supplier' => $registrar_supplier,
            'dns_supplier'       => $dns_supplier,
            'punycode'           => $punycode,
            'status_labels'      => DomainStatusResolver::getStatusLabels(),
            'status_classes'     => DomainStatusResolver::getStatusClasses(),
            'domains_id'         => $domains_id,
            'infocom_tab_url'    => $is_new ? '' : (Domain::getFormURLWithID($domains_id) . '&forcetab=Infocom$1'),
            'repository_url'     => PLUGIN_DOMAINMANAGER_REPOSITORY_URL,
            'locked_fields'      => $locked_fields,
            'provider_unknown'   => NsProviderRegistry::PROVIDER_UNKNOWN,
            'domain_type_labels' => self::getDomainTypeLabels(),
        ]);
    }

    /**
     * Cosmetic-only formatting for the raw `domainType` values a registrar
     * driver may report (§9 Phase 7) — currently only IONOS's own
     * `domainLarge.domainType` enum (`DOMAIN`/`X_DOMAIN`/`GENERIC_DOMAIN`).
     * IONOS's spec names these values but never documents what
     * distinguishes them, so this deliberately does not invent a meaning
     * for `X_DOMAIN`/`GENERIC_DOMAIN` — it only title-cases the raw value
     * for readability (`X_DOMAIN` -> "X Domain"). An unrecognized value
     * from a future driver still displays via the same fallback.
     *
     * @return array<string, string>
     */
    private static function getDomainTypeLabels(): array
    {
        $values = ['DOMAIN', 'X_DOMAIN', 'GENERIC_DOMAIN'];
        $labels = [];
        foreach ($values as $value) {
            $labels[$value] = ucwords(strtolower(str_replace('_', ' ', $value)));
        }

        return $labels;
    }

}
