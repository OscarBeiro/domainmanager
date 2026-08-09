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

/**
 * Maintenance script (not part of the plugin's runtime code path): re-fetches
 * the Public Suffix List and overwrites `resources/public_suffix_list.dat`,
 * the data `TldExtractor` resolves every domain's effective TLD against via
 * `jeremykendall/php-domain-parser` (Phase 83 "per-TLD dashboard breakdown"
 * follow-up — the PSL's ICANN section is the actual authority both IANA's
 * root zone list and this plugin's own hand-rolled multi-part-suffix list
 * used to approximate, and rejects reserved/private-use names like
 * `.internal`/`.local`/`.test`/`.lab` for free since none of those are in
 * it).
 *
 * The PSL changes more often than IANA's root zone (new ccTLD delegation
 * rules, private-section additions) but still isn't a fast-moving target —
 * there's no need to run this automatically. Run it by hand before cutting
 * a release:
 *
 *   php bin/update-public-suffix-list.php
 *
 * and commit the result if it changed (`git diff resources/public_suffix_list.dat`).
 */

const PSL_URL = 'https://publicsuffix.org/list/public_suffix_list.dat';

$target = __DIR__ . '/../resources/public_suffix_list.dat';

$context = stream_context_create(['http' => ['timeout' => 20]]);
$content = file_get_contents(PSL_URL, false, $context);

if ($content === false || !str_contains($content, 'BEGIN ICANN DOMAINS')) {
    fwrite(STDERR, "Failed to fetch a well-formed Public Suffix List from " . PSL_URL . "\n");
    exit(1);
}

// Sanity check: this should be many thousands of lines — if publicsuffix.org
// ever serves something truncated/malformed, fail loudly instead of
// silently shipping a near-empty list that would reject every real domain
// (TldExtractor fails closed on a suffix it can't resolve).
$lineCount = substr_count($content, "\n");
if ($lineCount < 5000) {
    fwrite(STDERR, "Fetched Public Suffix List only contains {$lineCount} lines — refusing to overwrite (expected 5000+).\n");
    exit(1);
}

file_put_contents($target, $content);

fwrite(STDOUT, "Updated {$target} ({$lineCount} lines).\n");
