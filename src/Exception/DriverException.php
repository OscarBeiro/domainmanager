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

namespace GlpiPlugin\Domainmanager\Exception;

use RuntimeException;

/**
 * Driver failure whose message is safe to persist and display
 * (payload/technical detail belongs in the plugin log file, never here)
 */
class DriverException extends RuntimeException
{
    /**
     * Whether this failure is a genuine write-permission denial (e.g.
     * Cloudflare's zone-scoped-token 403, ARCHITECTURE.md §12.3) — as
     * opposed to a transient, validation, or idempotent-already-satisfied
     * failure. `DnsRecordWriteback` uses this flag to decide whether to call
     * `DomainState::recordWriteOutcome()`, instead of any shared layer ever
     * inspecting a status code or provider error body itself (§12.6).
     */
    public readonly bool $isPermissionDenied;

    public function __construct(string $message, bool $isPermissionDenied = false)
    {
        parent::__construct($message);
        $this->isPermissionDenied = $isPermissionDenied;
    }
}
