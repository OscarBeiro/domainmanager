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

use DateTimeImmutable;
use GlpiPlugin\Domainmanager\Dto\RdapLookupResult;
use GlpiPlugin\Domainmanager\Exception\DriverException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;
use Toolbox;

/**
 * RDAP lookup client (§9 Phase 21): queries rdap.org directly rather than
 * caching the IANA bootstrap file and hitting authoritative servers
 * ourselves — at the cron design's 1-domain-per-10-minute-tick cadence
 * (§9 Phase 22), worst case is ~6 requests/hour, nowhere near rdap.org's
 * 10-req/10s limit, so the bootstrap-cache machinery buys nothing today
 * (see PHASE21_PLAN.md decision 1).
 *
 * TLD/domain support is detected reactively: a 404 (rdap.org has no
 * redirect target for this domain's TLD, or the registry itself returned
 * 404) is the ordinary "no RDAP data" outcome, not an error — there is no
 * maintained exclusion list to consult first (decision 2).
 */
class RdapClient
{
    private const BASE_URI = 'https://rdap.org/';
    private const TIMEOUT  = 15;

    private ?Client $client = null;

    /**
     * @param  string $domain FQDN, e.g. "example.com"
     * @return RdapLookupResult
     * @throws DriverException on any failure other than "no data for this
     *         domain/TLD" (a plain 404), which is not an error (see class docblock)
     */
    public function lookup(string $domain): RdapLookupResult
    {
        try {
            $response = $this->getClient()->request('GET', 'domain/' . rawurlencode($domain));
        } catch (GuzzleException $e) {
            throw new DriverException(__('RDAP lookup failed: connection error', 'domainmanager'), 0, $e);
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();

        if ($status === 404) {
            return RdapLookupResult::notFound();
        }

        if ($status < 200 || $status >= 300) {
            throw new DriverException(
                sprintf(__('RDAP lookup failed: HTTP %d', 'domainmanager'), $status),
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new DriverException(__('RDAP lookup failed: invalid response body', 'domainmanager'));
        }

        try {
            return self::parse($decoded);
        } catch (Throwable $e) {
            throw new DriverException(__('RDAP lookup failed: unable to parse response', 'domainmanager'), 0, $e);
        }
    }

    /**
     * Pure parser, no I/O — the RDAP JSON envelope (RFC 9083) into a
     * {@see RdapLookupResult}. Every lookup value is read defensively
     * (missing/malformed sub-structures degrade to null/empty, never a
     * thrown error) since RDAP servers vary in which optional members they
     * actually populate.
     *
     * @param  array<string, mixed> $data decoded JSON body of a found domain
     * @return RdapLookupResult
     */
    public static function parse(array $data): RdapLookupResult
    {
        $events = is_array($data['events'] ?? null) ? $data['events'] : [];
        $status = is_array($data['status'] ?? null) ? $data['status'] : [];

        $registrationDate = self::eventDate($events, 'registration');
        $expirationDate    = self::eventDate($events, 'expiration');
        $lastChangedDate   = self::eventDate($events, 'last changed');
        $transferDate      = self::eventDate($events, 'transfer');

        // IANA RDAP status registry values are lowercase, space-separated
        // strings (e.g. "client transfer prohibited"), not camelCase.
        $hasStatus = static fn(string $value): bool => in_array($value, $status, true);

        $transferLock = $hasStatus('client transfer prohibited') || $hasStatus('server transfer prohibited')
            ? true
            : null;
        $domainLock = $hasStatus('client delete prohibited') || $hasStatus('server delete prohibited')
            ? true
            : null;
        $pendingDelete   = $hasStatus('pending delete') ? true : null;
        $pendingTransfer = $hasStatus('pending transfer') ? true : null;

        $secureDns     = is_array($data['secureDNS'] ?? null) ? $data['secureDNS'] : [];
        $dnssecSigned  = array_key_exists('delegationSigned', $secureDns)
            ? (bool) $secureDns['delegationSigned']
            : null;

        [$registrarName, $registrarIanaId] = self::registrarEntity(
            is_array($data['entities'] ?? null) ? $data['entities'] : [],
        );

        $nameservers = [];
        foreach (is_array($data['nameservers'] ?? null) ? $data['nameservers'] : [] as $ns) {
            if (is_array($ns) && isset($ns['ldhName']) && is_string($ns['ldhName'])) {
                $nameservers[] = $ns['ldhName'];
            }
        }

        $notices = [];
        foreach (is_array($data['notices'] ?? null) ? $data['notices'] : [] as $notice) {
            if (!is_array($notice)) {
                continue;
            }
            $title       = isset($notice['title']) && is_string($notice['title']) ? $notice['title'] : '';
            $description = is_array($notice['description'] ?? null)
                ? implode(' ', array_map('strval', $notice['description']))
                : '';
            $text = trim($title . ': ' . $description, ': ');
            if ($text !== '') {
                $notices[] = $text;
            }
        }

        return new RdapLookupResult(
            found: true,
            registrationDate: $registrationDate,
            expirationDate: $expirationDate,
            lastChangedDate: $lastChangedDate,
            transferDate: $transferDate,
            transferLock: $transferLock,
            domainLock: $domainLock,
            pendingDelete: $pendingDelete,
            pendingTransfer: $pendingTransfer,
            dnssecSigned: $dnssecSigned,
            registrarName: $registrarName,
            registrarIanaId: $registrarIanaId,
            nameservers: $nameservers,
            notices: $notices,
        );
    }

    /**
     * @param  array<int, mixed> $events
     * @param  string            $action RDAP eventAction value to find
     * @return DateTimeImmutable|null
     */
    private static function eventDate(array $events, string $action): ?DateTimeImmutable
    {
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            if (($event['eventAction'] ?? null) !== $action) {
                continue;
            }
            $date = $event['eventDate'] ?? null;
            if (!is_string($date) || $date === '') {
                return null;
            }
            try {
                return new DateTimeImmutable($date);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Find the registrar entity (role=registrar) and extract its display
     * name (vCard `fn`) and IANA Registrar ID (`publicIds`), both purely
     * informational (§9 Phase 21 "Registrar-of-record note" — never a
     * second source of truth for who the registrar is)
     *
     * @param  array<int, mixed> $entities
     * @return array{0: ?string, 1: ?string}
     */
    private static function registrarEntity(array $entities): array
    {
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $roles = is_array($entity['roles'] ?? null) ? $entity['roles'] : [];
            if (!in_array('registrar', $roles, true)) {
                continue;
            }

            $name = null;
            $vcard = $entity['vcardArray'][1] ?? null;
            if (is_array($vcard)) {
                foreach ($vcard as $field) {
                    if (is_array($field) && ($field[0] ?? null) === 'fn' && isset($field[3])) {
                        $name = (string) $field[3];
                        break;
                    }
                }
            }

            $ianaId = null;
            foreach (is_array($entity['publicIds'] ?? null) ? $entity['publicIds'] : [] as $publicId) {
                if (is_array($publicId) && ($publicId['type'] ?? null) === 'IANA Registrar ID') {
                    $ianaId = (string) ($publicId['identifier'] ?? '');
                    break;
                }
            }

            return [$name, $ianaId];
        }

        return [null, null];
    }

    /**
     * @return Client
     */
    private function getClient(): Client
    {
        if ($this->client === null) {
            $this->client = Toolbox::getGuzzleClient([
                'base_uri'        => self::BASE_URI,
                'timeout'         => self::TIMEOUT,
                'http_errors'     => false,
                'allow_redirects' => ['max' => 5],
                'headers'         => [
                    'Accept' => 'application/rdap+json, application/json',
                ],
            ]);
        }

        return $this->client;
    }
}
