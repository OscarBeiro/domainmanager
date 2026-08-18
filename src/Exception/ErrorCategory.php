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

/**
 * Shared, driver-agnostic error categories (DESIGN-error-consolidation.md).
 *
 * Drivers own the mapping from their own provider-specific codes to one of
 * these categories (as data, e.g. a `match` in each driver's own
 * `classifyError()`); this enum and its labels are the only translatable
 * surface shared across drivers — ARCHITECTURE.md §12.6 is preserved because
 * no shared code here ever asserts what a given HTTP status or provider code
 * means, only what a *category a driver already picked* is called.
 */
enum ErrorCategory: string
{
    case Unreachable      = 'unreachable';
    case AuthFailed       = 'auth_failed';
    case AuthScoped       = 'auth_scoped';
    case DomainNotFound   = 'domain_not_found';
    case RecordNotFound   = 'record_not_found';
    case ConfigError      = 'config_error';
    case ValidationError  = 'validation_error';
    case WriteVerification = 'write_verification';
    case Unsupported      = 'unsupported';

    /**
     * @return string translated, short noun-phrase label — never advice
     */
    public function label(): string
    {
        return match ($this) {
            self::Unreachable       => __('unreachable', 'domainmanager'),
            self::AuthFailed        => __('wrong credentials', 'domainmanager'),
            self::AuthScoped        => __('insufficient permissions', 'domainmanager'),
            self::DomainNotFound    => __('domain not managed by this account', 'domainmanager'),
            self::RecordNotFound    => __('record not found', 'domainmanager'),
            self::ConfigError       => __('configuration error', 'domainmanager'),
            self::ValidationError   => __('validation failed', 'domainmanager'),
            self::WriteVerification => __('write not confirmed', 'domainmanager'),
            self::Unsupported       => __('not supported', 'domainmanager'),
        };
    }
}
