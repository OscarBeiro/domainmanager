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

use Toolbox;

/**
 * Consolidated plugin logging on two files, both visible in
 * Setup > Logs (Glpi\System\Log\LogParser::getLogsFilesList() enumerates
 * GLPI_LOG_DIR generically via *.log — no allow-list, no registration
 * needed; verified on 11.0/bugfixes, §3.6):
 * - domainmanager.log        activity trail: one entry per attempt, success or failure
 * - domainmanager-errors.log errors only, with technical detail (redacted of secrets)
 */
class PluginLogger
{
    /**
     * @param  string $message
     * @return void
     */
    public static function activity(string $message): void
    {
        Toolbox::logInFile('domainmanager', self::redact($message) . "\n", true);
    }

    /**
     * @param  string $message
     * @param  string $rawDetail technical detail, log-only (redacted defensively)
     * @return void
     */
    public static function error(string $message, string $rawDetail = ''): void
    {
        $text = self::redact($message);
        if ($rawDetail !== '') {
            $text .= "\n" . self::redact($rawDetail);
        }

        Toolbox::logInFile('domainmanager-errors', $text . "\n", true);
    }

    /**
     * Touch the error log file so it is visible in Setup > Logs even before
     * the first real error — LogParser::getLogsFilesList() only lists files
     * that already exist on disk (plain scandir(), no allow-list)
     *
     * @return void
     */
    public static function ensureErrorLogExists(): void
    {
        $path = GLPI_LOG_DIR . '/domainmanager-errors.log';
        if (!file_exists($path)) {
            touch($path);
        }
    }

    /**
     * Defensive belt-and-suspenders redaction: callers should never pass raw
     * secrets in the first place (audited 2026-08-03, ARCHITECTURE.md §15.2
     * Phase 51 — every `PluginLogger` call site and driver `DriverException`/
     * `GuzzleException::getMessage()` path funnels through here; none of them
     * currently carry a decrypted credential, since all three drivers send
     * auth via a Guzzle `headers`/`auth` client option rather than the
     * request URI or body, and Guzzle's own `RequestException::create()`
     * message is built only from the redacted URI, method and a truncated
     * response-body summary — never the request headers or body). This only
     * guards against accidental future leaks (e.g. a token embedded in an
     * upstream error message/body dump).
     *
     * @param  string $text
     * @return string
     */
    private static function redact(string $text): string
    {
        $text = preg_replace('/Bearer\s+\S+/i', 'Bearer [REDACTED]', $text) ?? $text;
        $text = preg_replace(
            '/((?:token|secret|password|pwd|api[_-]?key|auth[_-]?code|credential)s?\s*[=:]\s*"?)([^"\s,}]+)/i',
            '$1[REDACTED]',
            $text,
        ) ?? $text;

        return $text;
    }
}
