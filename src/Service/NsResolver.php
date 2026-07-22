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
 * Live NS lookup, isolated as a testable seam
 */
class NsResolver
{
    /**
     * Nameserver hosts of a domain ([] = lookup failed / none found)
     *
     * @param  string $fqdn GLPI's stored (possibly Unicode/IDN) domain name
     * @return string[]
     */
    public function getNameservers(string $fqdn): array
    {
        // dns_get_record() operates on the DNS wire form: a Unicode label
        // (e.g. "viñamoraima.com") simply fails to resolve — must convert
        // to Punycode/ACE first (§9 Phase 10).
        $fqdn = IdnNormalizer::toAscii(strtolower(rtrim(trim($fqdn), '.')));
        if ($fqdn === '' || !preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z0-9-]{2,}$/', $fqdn)) {
            return [];
        }

        $records = @dns_get_record($fqdn, DNS_NS);
        if (!is_array($records)) {
            return [];
        }

        $hosts = [];
        foreach ($records as $record) {
            $target = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));
            if ($target !== '') {
                $hosts[] = $target;
            }
        }

        return array_values(array_unique($hosts));
    }
}
