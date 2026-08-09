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

use Pdp\Rules;
use Pdp\UnableToLoadPublicSuffixList;
use Throwable;

/**
 * Single conversion point between a full domain name (`glpi_domains.name`,
 * e.g. "example.co.uk") and its effective TLD (`co.uk`), for the cached
 * `tld` column on `DomainState` (Phase 83 "per-TLD dashboard breakdown").
 *
 * Delegates the actual resolution to `jeremykendall/php-domain-parser`
 * against a locally-vendored copy of the Public Suffix List
 * (`resources/public_suffix_list.dat`) — a hand-rolled multi-part-suffix
 * list (`.co.uk`, `.com.au`, …) and a hand-maintained reserved/private-use
 * denylist (`.internal`, `.local`, …) were tried first, but the PSL is the
 * actual authority both of those were trying to approximate: `resolve()`
 * against its ICANN section returns the correct effective TLD for every
 * real suffix (including ones this plugin's own list never enumerated) and
 * throws for anything not delegated at all — `.internal`/`.local`/`.test`/
 * `.lab` rejected for free, without naming them. `getICANNDomain()`
 * specifically (not the wider `resolve()`) — the PSL's PRIVATE_DOMAINS
 * section covers things like `github.io`/`blogspot.com` wildcard hosting,
 * which would otherwise make e.g. "myproject.github.io" bucket under
 * "github.io" instead of the real registrar-level ".io" TLD this dashboard
 * breakdown actually wants.
 *
 * The PSL snapshot needs refreshing only when it changes upstream — see
 * `bin/update-public-suffix-list.php`, run by hand before cutting a release.
 */
class TldExtractor
{
    private const PSL_PATH = __DIR__ . '/../resources/public_suffix_list.dat';

    private static ?Rules $rules = null;

    /**
     * Full domain name -> effective TLD, lowercase, no leading dot. Returns
     * '' for empty input, a malformed/single-label input, or a suffix not
     * found in the PSL's ICANN section (reserved/private-use names, a typo,
     * a non-internet domain) — callers (the dashboard cards) treat '' as
     * "exclude from the breakdown" rather than a bucket of its own.
     *
     * @param  string $domain
     * @return string
     */
    public static function extract(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return '';
        }

        $rules = self::getRules();
        if ($rules === null) {
            // Missing/unreadable/corrupt PSL resource: fail closed (no TLD
            // counts as valid) rather than crashing every dashboard render
            // or domain save — same "degrade, don't break the pipeline"
            // reasoning as IdnNormalizer's own conversion-failure fallback.
            return '';
        }

        try {
            return $rules->getICANNDomain($domain)->suffix()->toString();
        } catch (Throwable $e) {
            // UnableToResolveDomain (no ICANN suffix at all — reserved/
            // private-use/non-existent TLD) or SyntaxError (malformed
            // input: spaces, stray punctuation, dot-less name) both mean
            // the same thing here: not a real, filterable internet TLD.
            return '';
        }
    }

    private static function getRules(): ?Rules
    {
        if (self::$rules === null) {
            try {
                self::$rules = Rules::fromPath(self::PSL_PATH);
            } catch (UnableToLoadPublicSuffixList $e) {
                return null;
            }
        }

        return self::$rules;
    }
}
