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

use DateTimeImmutable;

/**
 * Immutable registrar lifecycle + administrative-metadata snapshot of one
 * domain (§9 Phase 7). The metadata fields below are deliberately nullable
 * per-driver: `null` means "this registrar's API does not report this",
 * confirmed against each driver's real API (never guessed) — the exact
 * same convention `registrationDate` already established for IONOS.
 */
final class DomainLifecycle
{
    public function __construct(
        public readonly ?DateTimeImmutable $registrationDate,
        public readonly ?DateTimeImmutable $expirationDate,
        public readonly LifecycleStatus $status,
        public readonly ?bool $privacyEnabled = null,
        public readonly ?bool $domainLock = null,
        public readonly ?bool $transferLock = null,
        public readonly ?bool $autoRenew = null,
        public readonly ?string $domainType = null,
        public readonly ?bool $dnsSecEnabled = null,
    ) {
    }
}
