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

namespace GlpiPlugin\Domainmanager\Dto;

/**
 * Outcome of a single connection-test attempt against a provider API (§3.5)
 */
enum ConnectionTestStatus: string
{
    case Success       = 'success';
    case AuthFailed    = 'auth_failed';
    case Forbidden     = 'forbidden';
    case NotFound      = 'not_found';
    case RateLimited   = 'rate_limited';
    case UpstreamError = 'upstream_error';
    case NetworkError  = 'network_error';
    case Timeout       = 'timeout';
    case UnknownError  = 'unknown_error';
    // Required driver configuration is missing (e.g. Cloudflare's Account
    // ID) — no call was attempted at all, so this must never look like an
    // auth/API failure (§addendum "Switch Cloudflare Driver to
    // Account-Scoped API Tokens").
    case NotConfigured = 'not_configured';
}
