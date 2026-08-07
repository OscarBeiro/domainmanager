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

use GlpiPlugin\Domainmanager\DomainState;

/**
 * Human-readable label / badge CSS class per `registrar_status`/`dns_status`
 * value (the `DomainState::STATUS_*` enum) — the single source of truth
 * shared by the Domain form panel's status badge (`DomainForm`), the
 * Supplier "Domains" list (`SupplierTab`), and the "Registrar sync
 * status"/"DNS sync status" search options' dropdown/display
 * (`DomainState::getSpecificValueToSelect()`/`getSpecificValueToDisplay()`).
 *
 * Extracted from `DomainState` (§9 Phase 16) so the itemtype class owns the
 * enum's constants and search-option wiring while this class owns the
 * enum's presentation, without changing behavior.
 */
class DomainStatusResolver
{
    /**
     * @return array<string, string>
     */
    public static function getStatusLabels(): array
    {
        return [
            DomainState::STATUS_NEVER             => __('Never synchronized', 'domainmanager'),
            DomainState::STATUS_OK                => __('OK', 'domainmanager'),
            DomainState::STATUS_ERROR             => __('Error', 'domainmanager'),
            DomainState::STATUS_UNCONFIGURED      => __('Not configured', 'domainmanager'),
            DomainState::STATUS_UNSUPPORTED       => __('Provider not supported', 'domainmanager'),
            DomainState::STATUS_UNKNOWN           => __('Unknown provider', 'domainmanager'),
            DomainState::STATUS_SUPPLIER_INACTIVE => __('Supplier inactive', 'domainmanager'),
            DomainState::STATUS_REASSIGNED        => __('Registrar changed, not yet verified', 'domainmanager'),
            DomainState::STATUS_SOURCE_CONFLICT   => __('DNS provider changed, records untouched pending confirmation', 'domainmanager'),
            DomainState::STATUS_SYNC_SAFETY_GUARD => __('Blocked: too many records would be trashed', 'domainmanager'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getStatusClasses(): array
    {
        return [
            DomainState::STATUS_NEVER             => 'text-bg-secondary',
            DomainState::STATUS_OK                => 'text-bg-success',
            DomainState::STATUS_ERROR             => 'text-bg-danger',
            DomainState::STATUS_UNCONFIGURED      => 'text-bg-secondary',
            DomainState::STATUS_UNSUPPORTED       => 'text-bg-warning',
            DomainState::STATUS_UNKNOWN           => 'text-bg-warning',
            DomainState::STATUS_SUPPLIER_INACTIVE => 'text-bg-secondary',
            // Distinct from STATUS_ERROR (text-bg-danger, a failed API
            // call) and STATUS_SUPPLIER_INACTIVE (text-bg-secondary, an
            // intentional, calm state) — this reflects genuinely
            // incorrect/outdated stored data that needs a fresh sync,
            // without implying anything actually failed (§9 Phase 8
            // addendum).
            DomainState::STATUS_REASSIGNED        => 'text-bg-info',
            // Same reasoning as STATUS_REASSIGNED just above: a deliberate
            // pause pending a confirming re-sync, not a failure.
            DomainState::STATUS_SOURCE_CONFLICT   => 'text-bg-info',
            // A guard doing its job, not a failure — but unlike
            // STATUS_REASSIGNED/STATUS_SOURCE_CONFLICT above (a routine
            // "re-sync to confirm" pause), this one means the run actually
            // found something alarming (a would-be mass deletion), so it
            // gets the warning color rather than info.
            DomainState::STATUS_SYNC_SAFETY_GUARD => 'text-bg-warning',
        ];
    }
}
