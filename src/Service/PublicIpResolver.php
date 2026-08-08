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

use GlpiPlugin\Domainmanager\IdnNormalizer;

/**
 * Live public-facing IP lookup, isolated as a testable seam (§17.9-§17.11,
 * Phase 70): Cloudflare's API only ever returns the origin IP in `content` —
 * this resolves the live, publicly-visible address instead (the Cloudflare
 * anycast address, for a proxied record). Same shape as NsResolver: a
 * request-scoped `dns_get_record()` wrapper that tolerates failure by
 * returning [] rather than throwing, since a lookup failure here is just
 * "nothing to show", never an error the caller needs to propagate.
 */
class PublicIpResolver
{
    /**
     * Publicly-resolving addresses for a record ([] = lookup failed / none found)
     *
     * @param  string $fqdn GLPI's stored (possibly Unicode/IDN) record name
     * @param  string $type 'A' or 'AAAA' (CNAME is resolved transitively by
     *                      querying the same DNS type on the same name)
     * @return string[]
     */
    public function resolve(string $fqdn, string $type): array
    {
        $fqdn = IdnNormalizer::toAscii(strtolower(rtrim(trim($fqdn), '.')));
        if ($fqdn === '' || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z0-9-]{2,}$/', $fqdn)) {
            return [];
        }

        $dnsType = $type === 'AAAA' ? DNS_AAAA : DNS_A;
        $field   = $type === 'AAAA' ? 'ipv6' : 'ip';

        $records = @dns_get_record($fqdn, $dnsType);
        if (!is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = (string) ($record[$field] ?? '');
            if ($address !== '') {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
