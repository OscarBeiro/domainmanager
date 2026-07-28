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
 * Determines which RDAP-fillable fields (§9 Phase 21 field-scope table) are
 * still unreported for a given Domain — reuses the existing "driver doesn't
 * report this field, leave it blank" pattern (`RegistrarDriverInterface`,
 * `DomainLifecycle`'s nullable metadata fields) rather than a new concept: a
 * field already populated (by the native Domain columns for dates, or by the
 * registrar driver for dnssec) is never gap-eligible, and a field this
 * plugin's drivers never report at all (pending delete/transfer) is always
 * gap-eligible until the first successful RDAP check populates it.
 *
 * Registrar-of-record (`rdap_registrar_name`/`rdap_registrar_iana_id`) and
 * the nameserver cross-check (`rdap_nameservers`) are deliberately excluded
 * from this gap set: §9 Phase 22 populates them unconditionally on every
 * successful lookup regardless of prior value, so they must never be the
 * sole reason a domain is considered "still has a gap" (that would make
 * every already-fully-enriched domain eligible forever).
 */
class RdapGapChecker
{
    public const GAP_REGISTRATION_DATE = 'registration_date';
    public const GAP_EXPIRATION_DATE   = 'expiration_date';
    public const GAP_LAST_CHANGED      = 'last_changed';
    public const GAP_TRANSFER_DATE     = 'transfer_date';
    public const GAP_PENDING_DELETE    = 'pending_delete';
    public const GAP_PENDING_TRANSFER  = 'pending_transfer';
    public const GAP_DNSSEC            = 'dnssec';
    public const GAP_TRANSFER_LOCK     = 'transfer_lock';
    public const GAP_DOMAIN_LOCK       = 'domain_lock';

    /**
     * @param  int $domains_id
     * @return string[] gap keys (self::GAP_* constants) still unreported for this domain
     */
    public static function getGaps(int $domains_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($domains_id <= 0) {
            return [];
        }

        $domain = $DB->request([
            'SELECT' => ['date_domaincreation', 'date_expiration'],
            'FROM'   => 'glpi_domains',
            'WHERE'  => ['id' => $domains_id],
        ])->current();

        $gaps = [];

        if ($domain === null || self::isEmptyDate($domain['date_domaincreation'] ?? null)) {
            $gaps[] = self::GAP_REGISTRATION_DATE;
        }
        if ($domain === null || self::isEmptyDate($domain['date_expiration'] ?? null)) {
            $gaps[] = self::GAP_EXPIRATION_DATE;
        }

        $state = DomainState::getForDomain($domains_id);

        if ($state === null || self::isEmptyDate($state->fields['rdap_last_changed_date'] ?? null)) {
            $gaps[] = self::GAP_LAST_CHANGED;
        }
        if ($state === null || self::isEmptyDate($state->fields['rdap_transfer_date'] ?? null)) {
            $gaps[] = self::GAP_TRANSFER_DATE;
        }
        if ($state === null || $state->fields['rdap_pending_delete'] === null) {
            $gaps[] = self::GAP_PENDING_DELETE;
        }
        if ($state === null || $state->fields['rdap_pending_transfer'] === null) {
            $gaps[] = self::GAP_PENDING_TRANSFER;
        }

        // DNSSEC has two possible sources: the registrar driver may already
        // report it (registrar_dnssec_enabled), in which case RDAP would
        // only duplicate a known value — only a gap when neither source has it.
        $driverReportsDnssec = $state !== null && $state->fields['registrar_dnssec_enabled'] !== null;
        $rdapHasDnssec       = $state !== null && $state->fields['rdap_dnssec_signed'] !== null;
        if (!$driverReportsDnssec && !$rdapHasDnssec) {
            $gaps[] = self::GAP_DNSSEC;
        }

        // §9 Phase 26: same dual-source rule as DNSSEC above, for
        // Transfer lock/Domain lock.
        $driverReportsTransferLock = $state !== null && $state->fields['registrar_transfer_lock'] !== null;
        $rdapHasTransferLock       = $state !== null && $state->fields['rdap_transfer_lock'] !== null;
        if (!$driverReportsTransferLock && !$rdapHasTransferLock) {
            $gaps[] = self::GAP_TRANSFER_LOCK;
        }

        $driverReportsDomainLock = $state !== null && $state->fields['registrar_domain_lock'] !== null;
        $rdapHasDomainLock       = $state !== null && $state->fields['rdap_domain_lock'] !== null;
        if (!$driverReportsDomainLock && !$rdapHasDomainLock) {
            $gaps[] = self::GAP_DOMAIN_LOCK;
        }

        return $gaps;
    }

    /**
     * @param  int $domains_id
     * @return bool whether any RDAP-fillable field is still unreported
     */
    public static function hasGap(int $domains_id): bool
    {
        return self::getGaps($domains_id) !== [];
    }

    /**
     * @param  mixed $value raw DB datetime/date value
     * @return bool
     */
    private static function isEmptyDate(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        $value = (string) $value;

        return $value === '' || str_starts_with($value, '0000-00-00');
    }
}
