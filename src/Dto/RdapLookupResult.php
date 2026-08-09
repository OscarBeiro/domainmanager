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
 * Immutable RDAP snapshot of one domain (§9 Phase 21). `$found = false`
 * (all other fields null/empty) is the normal "this TLD/domain has no RDAP
 * data" outcome — a 404 from rdap.org — not an error (§9 Phase 21 decision 2).
 * Every field is nullable independently of `$found`: a *found* RDAP object
 * legitimately omits individual events/status values just as a registrar
 * driver's own API can (`DomainLifecycle`'s same convention) — `null` means
 * "RDAP didn't report this", never "unknown due to failure".
 */
final class RdapLookupResult
{
    public function __construct(
        public readonly bool $found,
        public readonly ?DateTimeImmutable $registrationDate = null,
        public readonly ?DateTimeImmutable $expirationDate = null,
        public readonly ?DateTimeImmutable $lastChangedDate = null,
        public readonly ?DateTimeImmutable $transferDate = null,
        public readonly ?bool $transferLock = null,
        public readonly ?bool $domainLock = null,
        public readonly ?bool $pendingDelete = null,
        public readonly ?bool $pendingTransfer = null,
        public readonly ?bool $dnssecSigned = null,
        public readonly ?string $registrarName = null,
        public readonly ?string $registrarIanaId = null,
        /** @var string[] */
        public readonly array $nameservers = [],
        /** @var string[] */
        public readonly array $notices = [],
    ) {
    }

    /**
     * The normal "no RDAP data for this TLD/domain" outcome (a 404)
     *
     * @return self
     */
    public static function notFound(): self
    {
        return new self(found: false);
    }

    /**
     * §9: a thin registry RDAP response (e.g. Verisign for `.com`) never
     * reports a `transfer` event at all — only the registrar's own
     * ("thick") RDAP server does, reachable only via that response's own
     * `related` link. {@see RdapClient::lookup()} follows it as a
     * best-effort fallback and merges the date in via this wither, leaving
     * every other field exactly as the primary (registry) lookup reported
     * it.
     *
     * @param  DateTimeImmutable $date
     * @return self
     */
    public function withTransferDate(DateTimeImmutable $date): self
    {
        return new self(
            found: $this->found,
            registrationDate: $this->registrationDate,
            expirationDate: $this->expirationDate,
            lastChangedDate: $this->lastChangedDate,
            transferDate: $date,
            transferLock: $this->transferLock,
            domainLock: $this->domainLock,
            pendingDelete: $this->pendingDelete,
            pendingTransfer: $this->pendingTransfer,
            dnssecSigned: $this->dnssecSigned,
            registrarName: $this->registrarName,
            registrarIanaId: $this->registrarIanaId,
            nameservers: $this->nameservers,
            notices: $this->notices,
        );
    }
}
