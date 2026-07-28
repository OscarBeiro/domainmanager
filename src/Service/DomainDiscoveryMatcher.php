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

namespace GlpiPlugin\Domainmanager\Service;

use Domain;
use Dropdown;
use GlpiPlugin\Domainmanager\Dto\DiscoveredDomain;
use GlpiPlugin\Domainmanager\IdnNormalizer;
use Supplier;

/**
 * Enriches a driver's raw discovered-domain list with existence/mismatch
 * info against GLPI's own inventory (§9 Phase 8's Import Domains modal).
 * The existence check is deliberately **global** (cross-entity) and
 * case-insensitive/trimmed, per the phase's own spec — unlike
 * DomainState::getDomainsForSupplier()'s entity-scoped listing, this is
 * answering "does this domain exist anywhere in GLPI at all", not "is it
 * visible to me".
 */
class DomainDiscoveryMatcher
{
    /**
     * @param  DiscoveredDomain[] $discovered
     * @param  int                $suppliers_id the registrar account being browsed
     * @return array<int, array{name:string, preview_status:?string,
     *                exists:bool, domains_id:?int, url:?string,
     *                existing_suppliers_id:int, existing_supplier_name:?string,
     *                mismatch:bool}>
     */
    public static function match(array $discovered, int $suppliers_id): array
    {
        $existing = self::loadExistingDomains();

        $rows = [];
        foreach ($discovered as $domain) {
            $key      = self::normalize($domain->name);
            $match    = $existing[$key] ?? null;
            $exists   = $match !== null;
            $existing_suppliers_id = $exists ? $match['suppliers_id'] : 0;

            $rows[] = [
                'name'                   => $domain->name,
                'preview_status'         => $domain->previewStatus,
                'exists'                 => $exists,
                'domains_id'             => $exists ? $match['domains_id'] : null,
                'url'                    => $exists ? Domain::getFormURLWithID($match['domains_id']) : null,
                'existing_suppliers_id'  => $existing_suppliers_id,
                'existing_supplier_name' => $existing_suppliers_id > 0
                    ? Dropdown::getDropdownName(Supplier::getTable(), $existing_suppliers_id)
                    : null,
                'mismatch'               => $exists && $existing_suppliers_id !== $suppliers_id,
            ];
        }

        return $rows;
    }

    /**
     * Every non-deleted, non-template Domain in the whole instance
     * (deliberately unrestricted by entity, see class docblock), keyed by
     * normalized name — a single query, matched against the discovered
     * list in PHP rather than building a case-insensitive/trimmed SQL
     * WHERE (this plugin has no existing precedent for raw SQL expressions
     * in a WHERE clause, and the Domain itemtype is narrow enough that
     * loading it in full is cheap). Public: DomainImportController reuses
     * this exact same map to re-check existence at submit time (a
     * concurrent change since the modal was first rendered must not be
     * trusted), rather than one query per submitted name.
     *
     * @return array<string, array{domains_id:int, suppliers_id:int}>
     */
    public static function loadExistingDomains(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'SELECT'    => [
                'glpi_domains.id AS domains_id',
                'glpi_domains.name AS name',
                'infocom.suppliers_id AS suppliers_id',
            ],
            'FROM'      => 'glpi_domains',
            'LEFT JOIN' => [
                'glpi_infocoms AS infocom' => [
                    'ON' => [
                        'infocom'      => 'items_id',
                        'glpi_domains' => 'id',
                        [
                            'AND' => ['infocom.itemtype' => Domain::class],
                        ],
                    ],
                ],
            ],
            'WHERE'     => [
                'glpi_domains.is_deleted'  => 0,
                'glpi_domains.is_template' => 0,
            ],
        ]);

        $existing = [];
        foreach ($iterator as $row) {
            $key = self::normalize((string) $row['name']);
            if ($key === '') {
                continue;
            }
            $existing[$key] = [
                'domains_id'   => (int) $row['domains_id'],
                'suppliers_id' => (int) ($row['suppliers_id'] ?? 0),
            ];
        }

        return $existing;
    }

    /**
     * Every soft-deleted (trashed), non-template Domain in the whole
     * instance, keyed by normalized name. Used by DomainImportController to
     * restore a previously-trashed domain on reimport instead of creating a
     * duplicate — which would silently orphan whatever tickets, contracts,
     * infocom, etc. were still linked to the trashed item. When several
     * trashed domains share a name (each reimport-then-delete cycle would
     * otherwise pile up more), only the oldest (lowest id, i.e. the first
     * ever created) is kept, per the plugin's "restore the first one"
     * policy — the rest are left in the trash untouched.
     *
     * @return array<string, int> normalized name => domains_id
     */
    public static function loadTrashedDomains(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'SELECT'  => ['id AS domains_id', 'name'],
            'FROM'    => 'glpi_domains',
            'WHERE'   => [
                'is_deleted'  => 1,
                'is_template' => 0,
            ],
            'ORDER'   => 'id ASC',
        ]);

        $trashed = [];
        foreach ($iterator as $row) {
            $key = self::normalize((string) $row['name']);
            if ($key === '' || isset($trashed[$key])) {
                continue;
            }
            $trashed[$key] = (int) $row['domains_id'];
        }

        return $trashed;
    }

    /**
     * Public: shared by DomainImportController so "already exists" checks
     * use the exact same normalization on both sides. Canonicalized to
     * Punycode/ACE, not left as raw Unicode (§9 Phase 10 point 2) — a
     * driver's discovered-domain name and GLPI's own stored `name` can each
     * independently be in either form (a registrar's list endpoint may
     * return Unicode or Punycode; GLPI always stores Unicode), so comparing
     * un-normalized strings risks a false "does not exist" match for any
     * IDN domain.
     *
     * @param  string $name
     * @return string
     */
    public static function normalize(string $name): string
    {
        return IdnNormalizer::toAscii(strtolower(trim($name)));
    }
}
