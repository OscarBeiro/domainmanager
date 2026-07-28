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

/**
 * Single conversion point between GLPI's stored, human-readable Unicode
 * domain name (`glpi_domains.name`, e.g. "viñamoraima.com") and the
 * ASCII-compatible Punycode/ACE form (`xn--...`) that DNS itself and every
 * driver's registrar/DNS API actually operate on (§9 Phase 10). None of the
 * three provider APIs this plugin calls document an explicit Unicode-vs-
 * Punycode expectation (verified by web research against Cloudflare/IONOS/
 * Dinahosting's docs, 2026-07-22 — see ARCHITECTURE.md §3.11) — Punycode is
 * used as the universal outbound form for all three since it's the only
 * form guaranteed to be DNS-wire-compatible, and third-party reports
 * (cloudflare-go #347, terraform-provider-cloudflare #1310) confirm at
 * least Cloudflare can echo a domain name back in Unicode even when queried
 * with Punycode, making round-trip comparison in Punycode (not Unicode) the
 * safer canonical form (§9 Phase 10 point 2).
 *
 * Requires the `intl` PHP extension (`idn_to_ascii()`/`idn_to_utf8()`) — a
 * hard dependency for this plugin from Phase 10 onward, not something to
 * route around with a hand-rolled encoder.
 */
class IdnNormalizer
{
    /**
     * Unicode (or already-ASCII) domain name -> Punycode/ACE form.
     * Uses the modern UTS46 variant with nontransitional processing
     * (`IDNA_NONTRANSITIONAL_TO_ASCII`) — the WHATWG/ICANN-recommended mode
     * since 2017, not the deprecated `INTL_IDNA_VARIANT_2003`.
     *
     * A plain ASCII domain round-trips unchanged (confirmed:
     * `idn_to_ascii('example.com', ...)` returns `'example.com'`).
     * Conversion failure (malformed label, disallowed codepoint, etc.)
     * falls back to a trimmed/lowercased copy of the input rather than
     * throwing — callers already validate the result against their own
     * FQDN regex afterward, so a failed conversion simply fails that
     * validation instead of crashing the sync/lookup pipeline.
     *
     * @param  string $domain
     * @return string
     */
    public static function toAscii(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return $domain;
        }

        $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return $ascii !== false ? $ascii : $domain;
    }

    /**
     * Punycode/ACE (or already-Unicode) domain name -> human-readable
     * Unicode form, for display only (e.g. the Domain form's read-only
     * "Punycode / ASCII form" field shows the inverse of this). A plain
     * ASCII domain round-trips unchanged.
     *
     * @param  string $domain
     * @return string
     */
    public static function toUnicode(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return $domain;
        }

        $unicode = idn_to_utf8($domain, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);

        return $unicode !== false ? $unicode : $domain;
    }
}
