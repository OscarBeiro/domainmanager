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

namespace GlpiPlugin\Domainmanager\Driver;

use DateTimeImmutable;
use GlpiPlugin\Domainmanager\Contract\ConnectionTestableInterface;
use GlpiPlugin\Domainmanager\Contract\DnsPipelineInterface;
use GlpiPlugin\Domainmanager\Contract\DnsRecordWriterInterface;
use GlpiPlugin\Domainmanager\Contract\DomainDiscoveryInterface;
use GlpiPlugin\Domainmanager\Contract\RegistrarDriverInterface;
use GlpiPlugin\Domainmanager\Dto\ConnectionTestResult;
use GlpiPlugin\Domainmanager\Dto\DiscoveredDomain;
use GlpiPlugin\Domainmanager\Dto\DomainLifecycle;
use GlpiPlugin\Domainmanager\Dto\LifecycleStatus;
use GlpiPlugin\Domainmanager\Dto\ZoneRecord;
use GlpiPlugin\Domainmanager\Exception\DriverException;
use GlpiPlugin\Domainmanager\IdnNormalizer;
use GlpiPlugin\Domainmanager\Service\PluginLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Throwable;
use Toolbox;

/**
 * IONOS driver: DNS zone records API + Domains (registrar/lifecycle) API,
 * both real implementations. These are two separate IONOS Hosting/Developer
 * products (developer.hosting.ionos.com) — NOT to be confused with IONOS
 * Cloud's unrelated IAM federation-domains API (iam.ionos.com), which only
 * verifies SSO domain ownership and has nothing to do with this plugin.
 * Credentials: ['key' => <API key prefix>, 'secret' => <API secret>]
 *
 * DNS API docs: https://developer.hosting.ionos.com/docs/dns (a JS-rendered
 * SPA portal with no inline example bodies). Field names and error shapes
 * for the DNS pipeline were verified two ways:
 * - the maintained third-party client github.com/libdns/ionos (base URI,
 *   zone/record JSON field names, TXT-content quoting behavior)
 * - direct live probing of https://api.hosting.ionos.com/dns/v1 with no/bad
 *   credentials (confirmed the `X-API-Key: <prefix>.<secret>` header format,
 *   and the `{"message": "..."}` error envelope with real HTTP status codes
 *   — 400 "Invalid API key format.", 401 "Missing or invalid API key."/
 *   "Missing or invalid credentials.")
 *
 * Domains API (registrar/lifecycle) — the docs portal is the same JS-only
 * shell, but its actual OpenAPI 3.0.2 spec is a static asset the portal
 * loads client-side and was fetched and read directly (not reconstructed
 * from memory): `https://developer.hosting.ionos.com/assets/kms-swagger-specs/domains.yaml`,
 * spec version **1.0.4**, confirmed live on 2026-07-21. Key facts from that
 * spec, plus live probing of the base URL with no/bad credentials (same
 * `X-API-Key`, same gateway, confirmed identical
 * `{"message":"Missing or invalid credentials."}` /
 * `{"message":"Missing or invalid API key."}` responses as the DNS API):
 * - Base URL: `https://api.hosting.ionos.com/domains/v1` (`servers.url` +
 *   the `/v1/domainitems...` paths in the spec).
 * - Auth: the spec's formal `securitySchemes`/`security` section requires
 *   only `X-Api-Key` (same header this driver already sends for DNS, case
 *   differs cosmetically only — HTTP header names are case-insensitive).
 *   The spec's *prose* intro also mentions "Every endpoint uses the
 *   `X-Tenant-Id` header", but that header is **not** modeled anywhere in
 *   the machine-readable `parameters`/`security` sections, and this
 *   plugin's credential shape has no tenant-id field to source one from —
 *   sent without it. Flagged as an unconfirmed detail rather than guessed:
 *   if a real reseller/multi-tenant IONOS account gets an auth error this
 *   driver can't otherwise explain, a `tenant_id` credential field may
 *   need adding — see ARCHITECTURE.md §3.9.
 * - No exact "look up by full domain name" filter exists on the list
 *   endpoint (`GET /v1/domainitems`); its `name` parameter is a *substring*
 *   match (documented minimum 3 characters), not equality — so
 *   findDomainId() fetches candidates and matches `name` exactly,
 *   case-insensitively, the same pattern findZoneId() already uses for the
 *   DNS zone list.
 * - The Domains API has no `registrationDate`/`creationDate`/equivalent
 *   field anywhere in its schema (confirmed absent from the full spec, not
 *   merely undocumented) — `fetchLifecycle()` always returns a null
 *   `registrationDate` for this driver, which `DomainLifecycle` already
 *   supports as a nullable field.
 * - Error envelope is genuinely two-shaped depending on which layer
 *   rejects the request: a gateway-level auth rejection (malformed/absent
 *   key, shared with the DNS API) returns a single `{"message": "..."}"`
 *   object; an application-level error from the Domains backend itself
 *   (confirmed via the spec's own `error` schema and response examples)
 *   returns a JSON **array** of `{"code": "...", "message": "..."}`
 *   objects instead. describeDomainsApiError() handles both.
 */
class IonosDriver implements RegistrarDriverInterface, DnsPipelineInterface, DnsRecordWriterInterface, ConnectionTestableInterface, DomainDiscoveryInterface
{
    private const BASE_URI = 'https://api.hosting.ionos.com/dns/v1/';

    private const DOMAINS_BASE_URI = 'https://api.hosting.ionos.com/domains/v1/';

    private const REQUEST_TIMEOUT = 15;

    private const TEST_TIMEOUT = 9;

    private const DOMAINS_PAGE_SIZE = 100;

    private const DOMAINS_MAX_PAGES = 10;

    private ?Client $client = null;

    private ?Client $domainsClient = null;

    /**
     * @param array<string, string> $credentials
     */
    public function __construct(private array $credentials)
    {
    }

    /**
     * Only 'dns' is really tested. `fetchLifecycle()` (RegistrarDriverInterface)
     * is now a real implementation against the Domains API (see class
     * docblock), but adding a real 'registrar' connection-test probe here
     * was out of scope for that change — deliberately deferred, not because
     * no documentation exists. 'registrar' still reports "not implemented"
     * rather than being silently dropped from the UI (an admin should be
     * able to see that gap, not just fail to find a button for it).
     *
     * {@inheritDoc}
     */
    public function testConnection(array $credentials): array
    {
        $previous          = $this->credentials;
        $this->credentials = $credentials;
        $this->client       = null;

        try {
            $dns = $this->probeZonesList();
        } catch (Throwable $e) {
            $dns = ConnectionTestResult::fromException('dns', $e);
        } finally {
            $this->credentials = $previous;
            $this->client       = null;
        }

        return [
            'registrar' => ConnectionTestResult::notImplemented(
                'registrar',
                __('Not yet implemented for this driver', 'domainmanager'),
            ),
            'dns' => $dns,
        ];
    }

    /**
     * Direct HTTP probe used only by testConnection(): unlike request(), it
     * inspects the raw status code itself so the result can be classified
     * precisely (401 -> AuthFailed, etc.) instead of collapsing into the
     * generic DriverException messages used by the sync pipeline.
     *
     * @return ConnectionTestResult
     * @throws DriverException when the key/secret are empty or the API is unreachable
     */
    private function probeZonesList(): ConnectionTestResult
    {
        $client = $this->getClient();

        try {
            $response = $client->request('GET', 'zones', ['timeout' => self::TEST_TIMEOUT]);
        } catch (GuzzleException $e) {
            PluginLogger::error('IONOS connection test HTTP failure: ' . $e->getMessage());
            throw $e;
        }

        $status  = $response->getStatusCode();
        $body    = (string) $response->getBody();
        $success = $status >= 200 && $status < 300;

        $raw_detail = $success ? '' : ('IONOS GET zones (HTTP ' . $status . '): ' . self::describeError($body));

        return ConnectionTestResult::fromHttpResponse('dns', $status, $success, $raw_detail);
    }

    /**
     * {@inheritDoc}
     *
     * §9 Phase 7: `authInfo`/`privacyEnabled`/`domainLock`/`transferLock`/
     * `autoRenew`/`domainType`/`dnsSecEnabled` are all confirmed top-level
     * fields of the `domainLarge` schema returned by this exact call
     * (`GET /v1/domainitems/{domainId}`, live spec re-read 2026-07-21) —
     * the same response this method already fetches for
     * `expirationDate`/`status`, no extra request needed. `authInfo` is
     * itself optionally absent per IONOS's own doc (hidden when Domain
     * Guard is active; not set by default for .eu/.de) — `?? null`
     * handles that the same way a missing key would.
     */
    public function fetchLifecycle(string $domain): DomainLifecycle
    {
        $domain   = self::normalizeDomain($domain);
        $domainId = $this->findDomainId($domain);

        $data = $this->requestDomainsApi(
            'GET',
            'domainitems/' . rawurlencode($domainId),
            ['includeDomainStatus' => 'true'],
        );

        // No registration/creation date field exists anywhere in the
        // Domains API schema (confirmed, see class docblock) — always null
        // for this driver, which DomainLifecycle already supports.
        $expiration = self::parseDate($data['expirationDate'] ?? null);

        return new DomainLifecycle(
            null,
            $expiration,
            self::mapLifecycleStatus($data['status'] ?? []),
            isset($data['authInfo']) ? (string) $data['authInfo'] : null,
            isset($data['privacyEnabled']) ? (bool) $data['privacyEnabled'] : null,
            isset($data['domainLock']) ? (bool) $data['domainLock'] : null,
            isset($data['transferLock']) ? (bool) $data['transferLock'] : null,
            isset($data['autoRenew']) ? (bool) $data['autoRenew'] : null,
            isset($data['domainType']) ? (string) $data['domainType'] : null,
            isset($data['dnsSecEnabled']) ? (bool) $data['dnsSecEnabled'] : null,
        );
    }

    /**
     * Resolve the IONOS-internal domainId of a domain by name. For a plain
     * ASCII domain, uses the list endpoint's `name` filter (a substring
     * match, documented minimum 3 characters, no exact-match-by-full-name
     * filter exists — see class docblock) as a server-side narrowing hint,
     * then matches candidate rows' `name` field exactly, case-insensitively
     * — the same pattern findZoneId() already uses for the DNS zone list.
     *
     * §9 Phase 10 addendum — two live findings against `viñamoraima.com`
     * (2026-07-22) drove this to skip the `name` filter entirely for IDN
     * domains:
     * 1. Querying with the Punycode form (`xn--viamoraima-u9a.com`)
     *    returned zero rows ("No IONOS domain item found") for a domain
     *    that *was* registered on the account — the filter apparently
     *    doesn't match Punycode against IONOS's own (Unicode) record.
     * 2. Querying with the literal Unicode form instead made things worse:
     *    a raw, non-JSON HTML "400 Bad request" page from IONOS's own
     *    gateway — even though the request line itself was valid,
     *    correctly percent-encoded UTF-8 (confirmed directly against
     *    Guzzle's query builder). The gateway's own edge validation for
     *    this `name` parameter evidently rejects non-ASCII outright,
     *    encoded or not.
     * Since neither form of the filter can be trusted for an IDN domain,
     * and the endpoint's exact-match filter unreliability was already the
     * reason full-page fetch-and-compare was in use, this method now
     * simply omits `name` for every lookup and paginates through the full
     * list unfiltered — bandwidth cost is bounded by
     * DOMAINS_MAX_PAGES/DOMAINS_PAGE_SIZE, same as listAccountDomains().
     * Each row is matched against $domain (canonical Punycode) after
     * normalizing the row's own `name` to Punycode first, since a
     * provider is free to echo a name back in either form (§9 Phase 10
     * point 2).
     *
     * @param  string $domain Punycode/ACE form (as produced by normalizeDomain())
     * @return string
     * @throws DriverException
     */
    private function findDomainId(string $domain): string
    {
        for ($page = 0; $page < self::DOMAINS_MAX_PAGES; $page++) {
            $offset = $page * self::DOMAINS_PAGE_SIZE;
            $data   = $this->requestDomainsApi('GET', 'domainitems', [
                'limit'  => self::DOMAINS_PAGE_SIZE,
                'offset' => $offset,
            ]);

            foreach ($data['domains'] ?? [] as $row) {
                // Normalize the returned name to Punycode before comparing:
                // some providers are known to echo a queried domain back in
                // Unicode even when the request itself used Punycode (§9
                // Phase 10 point 2) — comparing two un-normalized forms
                // could silently read as "not found".
                if (is_array($row) && strcasecmp(IdnNormalizer::toAscii((string) ($row['name'] ?? '')), $domain) === 0) {
                    return (string) ($row['id'] ?? '');
                }
            }

            $count = (int) ($data['count'] ?? 0);
            if ($offset + self::DOMAINS_PAGE_SIZE >= $count) {
                break;
            }
        }

        throw new DriverException(
            sprintf(__('No IONOS domain item found for %s with this account', 'domainmanager'), $domain),
        );
    }

    /**
     * {@inheritDoc}
     *
     * §9 Phase 8: same `GET /v1/domainitems` list endpoint and pagination
     * shape findDomainId() already uses, just without its `name` filter —
     * this fetches every domain item on the account rather than searching
     * for one. `DiscoveredDomain::$previewStatus` is left null: the list
     * response's per-domain fields beyond `id`/`name` were not re-verified
     * against a live account for this slice (see class docblock's existing
     * "confirmed vs unconfirmed" bar) — a real, honest gap rather than a
     * guess, to be revisited if a genuinely useful preview field turns up.
     */
    public function listAccountDomains(): array
    {
        $domains = [];

        for ($page = 0; $page < self::DOMAINS_MAX_PAGES; $page++) {
            $offset = $page * self::DOMAINS_PAGE_SIZE;
            $data   = $this->requestDomainsApi('GET', 'domainitems', [
                'limit'  => self::DOMAINS_PAGE_SIZE,
                'offset' => $offset,
            ]);

            foreach ($data['domains'] ?? [] as $row) {
                if (is_array($row) && !empty($row['name'])) {
                    $domains[] = new DiscoveredDomain((string) $row['name']);
                }
            }

            $count = (int) ($data['count'] ?? 0);
            if ($offset + self::DOMAINS_PAGE_SIZE >= $count) {
                break;
            }
        }

        return $domains;
    }

    /**
     * Maps the Domains API's `itemStatus` object (confirmed shape: live
     * OpenAPI spec's `components.schemas.itemStatus`, see class docblock)
     * to the plugin's `LifecycleStatus` enum:
     * - `provisioningStatus.type = REGISTRATION_IN_PROGRESS` → `Pending`
     *   (added to the enum for this driver — the domain is mid
     *   registration/transfer, not yet live; no existing case fit without
     *   a lossy guess)
     * - any `complianceStatus` present (EMAIL_VERIFICATION_RUNNING,
     *   DATA_QUALITY_RUNNING, NOMINET_LOCKED, EMAIL_VERIFICATION_LOCK) →
     *   `Suspended` (a hold/compliance-block state), unless already Pending
     * - `provisioningStatus.type = EXPIRING` → `Expired` (IONOS's own
     *   terminal wind-down state before deletion)
     * - otherwise (`ACTIVE`, no compliance hold) → `Ok`
     *
     * @param  array $status decoded `itemStatus`, or [] if
     *                       `includeDomainStatus` wasn't honored
     * @return LifecycleStatus
     */
    private static function mapLifecycleStatus(array $status): LifecycleStatus
    {
        $provisioningType = (string) ($status['provisioningStatus']['type'] ?? '');

        if ($provisioningType === 'REGISTRATION_IN_PROGRESS') {
            return LifecycleStatus::Pending;
        }

        if (isset($status['complianceStatus'])) {
            return LifecycleStatus::Suspended;
        }

        if ($provisioningType === 'EXPIRING') {
            return LifecycleStatus::Expired;
        }

        return LifecycleStatus::Ok;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchZoneRecords(string $domain): array
    {
        $domain = self::normalizeDomain($domain);
        $zoneId = $this->findZoneId($domain);

        $data = $this->request('GET', 'zones/' . rawurlencode($zoneId));

        $records = [];
        foreach ($data['records'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $type = strtoupper((string) ($row['type'] ?? ''));
            if (!in_array($type, ZoneRecord::TYPES, true)) {
                continue; // read-only scope: unknown/unsupported types skipped
            }

            $name    = (string) ($row['name'] ?? '');
            $content = self::extractContent($type, $row);

            try {
                $records[] = new ZoneRecord(
                    $type,
                    $name,
                    $content,
                    (int) ($row['ttl'] ?? 0),
                    (string) ($row['id'] ?? ''),
                );
            } catch (InvalidArgumentException $e) {
                PluginLogger::activity("IONOS record skipped for $domain: " . $e->getMessage());
            }
        }

        return $records;
    }

    /**
     * Field names verified against github.com/libdns/ionos: MX priority is
     * `prio` (prepended to `content`, matching this plugin's MX convention
     * of "priority target" already used by CloudflareDriver); TXT `content`
     * is returned double-quoted by the API and must be unquoted.
     *
     * Verified 2026-07-29 against the live spec (dns.yaml): the IONOS
     * `record` schema has no per-type sub-fields beyond `prio` — ALIAS,
     * PTR, SOA, SRV and CAA all arrive fully serialized in `content`
     * (e.g. CAA's own spec example is `"0 issuewild \"example.org\""`),
     * so the raw-`content` fallback below is correct for them as-is; no
     * further parsing is needed for this driver specifically. Cloudflare
     * and Dinahosting were NOT verified the same way and may need
     * per-type handling of their own before relying on this pattern.
     *
     * @param  string $type
     * @param  array  $row
     * @return string
     */
    private static function extractContent(string $type, array $row): string
    {
        $content = (string) ($row['content'] ?? '');

        if ($type === 'TXT' && strlen($content) >= 2 && $content[0] === '"' && str_ends_with($content, '"')) {
            $content = stripslashes(substr($content, 1, -1));
        }

        if ($type === 'MX') {
            $content = ((int) ($row['prio'] ?? 0)) . ' ' . $content;
        }

        return $content;
    }

    /**
     * {@inheritDoc}
     *
     * §9 Phase 33, verified against the live spec fetched from
     * `https://developer.hosting.ionos.de/assets/kms-swagger-specs/dns.yaml`
     * (openapi 1.0.2, confirmed 2026-07-29): `POST /v1/zones/{zoneId}/records`
     * takes a JSON **array** of `record` objects and replies `201` with a
     * JSON array of `record-response` objects (which carry `id`) — one
     * element each here, since this interface writes one record at a time.
     * `remote_id` is read straight off that response, no follow-up GET.
     */
    public function createRecord(string $domain, string $type, string $name, string $data, int $ttl): ZoneRecord
    {
        $type   = self::assertWritableType($type);
        $domain = self::normalizeDomain($domain);
        $zoneId = $this->findZoneId($domain);

        $response = $this->request('POST', 'zones/' . rawurlencode($zoneId) . '/records', [
            [
                'name'     => rtrim($name, '.'),
                'type'     => $type,
                'content'  => self::toWireContent($type, $data),
                'ttl'      => $ttl,
                'prio'     => 0,
                'disabled' => false,
            ],
        ]);

        $row = $response[0] ?? null;
        if (!is_array($row)) {
            throw new DriverException(__('IONOS did not return the created record', 'domainmanager'));
        }

        return self::toZoneRecord($type, $row);
    }

    /**
     * {@inheritDoc}
     *
     * §9 Phase 33: the live spec's `record-update` request schema is
     * `{disabled, content, ttl, prio}` only — **no `name`/`type`** — and the
     * `200` response is a full `record-response` body. This corrects
     * ARCHITECTURE.md §11.9, which assumed (from the reference client alone)
     * that `PUT` is a full replace with no response body; the authoritative
     * spec says otherwise on both counts. `$name`/`$type` are accepted here
     * only to shape the returned `ZoneRecord` and because IONOS's own
     * response body already includes them regardless.
     */
    public function updateRecord(string $domain, string $remoteId, string $type, string $name, string $data, int $ttl): ZoneRecord
    {
        $type   = self::assertWritableType($type);
        $domain = self::normalizeDomain($domain);
        $zoneId = $this->findZoneId($domain);

        $row = $this->request('PUT', 'zones/' . rawurlencode($zoneId) . '/records/' . rawurlencode($remoteId), [
            'content'  => self::toWireContent($type, $data),
            'ttl'      => $ttl,
            'prio'     => 0,
            'disabled' => false,
        ]);

        return self::toZoneRecord($type, $row);
    }

    /**
     * {@inheritDoc}
     *
     * `DELETE /v1/zones/{zoneId}/records/{recordId}` replies `200` with no
     * documented response body (confirmed against the live spec).
     */
    public function deleteRecord(string $domain, string $remoteId): void
    {
        $domain = self::normalizeDomain($domain);
        $zoneId = $this->findZoneId($domain);

        $this->request('DELETE', 'zones/' . rawurlencode($zoneId) . '/records/' . rawurlencode($remoteId), null, true);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchRecord(string $domain, string $remoteId): ZoneRecord
    {
        $domain = self::normalizeDomain($domain);
        $zoneId = $this->findZoneId($domain);

        $row  = $this->request('GET', 'zones/' . rawurlencode($zoneId) . '/records/' . rawurlencode($remoteId));
        $type = strtoupper((string) ($row['type'] ?? ''));

        return self::toZoneRecord($type, $row);
    }

    /**
     * @param  string $type
     * @return string upper-cased, validated $type
     * @throws DriverException when $type is outside self::WRITABLE_TYPES
     */
    private static function assertWritableType(string $type): string
    {
        $type = strtoupper(trim($type));
        if (!in_array($type, self::WRITABLE_TYPES, true)) {
            throw new DriverException(
                sprintf(__('Record type %s is not writable through Domain Manager', 'domainmanager'), $type),
            );
        }

        return $type;
    }

    /**
     * Inverse of extractContent()'s TXT unquoting: this driver's own
     * ZoneRecord::$data convention stores TXT content unquoted (§9 Phase
     * 33, see DnsRecordWriterInterface docblock), IONOS's wire format
     * requires it quoted — the two representations must stay exact inverses
     * of each other or a round trip (create/update, then the next sync)
     * would churn record_hash.
     *
     * @param  string $type
     * @param  string $data
     * @return string
     */
    private static function toWireContent(string $type, string $data): string
    {
        if ($type === 'TXT') {
            return '"' . addcslashes($data, '"\\') . '"';
        }

        return $data;
    }

    /**
     * @param  string $type
     * @param  array  $row decoded record-response
     * @return ZoneRecord
     */
    private static function toZoneRecord(string $type, array $row): ZoneRecord
    {
        return new ZoneRecord(
            $type,
            (string) ($row['name'] ?? ''),
            self::extractContent($type, $row),
            (int) ($row['ttl'] ?? 0),
            (string) ($row['id'] ?? ''),
        );
    }

    /**
     * Resolve the zone id of a domain. The API has no filter-by-name query
     * parameter (confirmed against the reference client, which also fetches
     * all zones and filters client-side), so this fetches the full zone
     * list and matches case-insensitively.
     *
     * @param  string $domain
     * @return string
     * @throws DriverException
     */
    private function findZoneId(string $domain): string
    {
        $zones = $this->request('GET', 'zones');

        foreach ($zones as $zone) {
            // See findDomainId()'s identical comment: normalize the
            // returned zone name to Punycode before comparing (§9 Phase 10
            // point 2).
            if (is_array($zone) && strcasecmp(IdnNormalizer::toAscii((string) ($zone['name'] ?? '')), $domain) === 0) {
                return (string) ($zone['id'] ?? '');
            }
        }

        throw new DriverException(
            sprintf(__('No IONOS DNS zone found for %s with this key', 'domainmanager'), $domain),
        );
    }

    /**
     * Perform an API call; technical detail goes to the plugin log file,
     * thrown messages are safe to persist
     *
     * @param  string     $method
     * @param  string     $path
     * @param  array|null $json           request body, sent as `json` when not null (§9 Phase 33)
     * @param  bool       $allowEmptyBody accept a 2xx response with no body, returning [] (§9 Phase 33, deleteRecord())
     * @return array decoded body (a list for `zones`/create, a map for `zones/{id}`/update/fetch, [] for an allowed empty body)
     * @throws DriverException
     */
    private function request(string $method, string $path, ?array $json = null, bool $allowEmptyBody = false): array
    {
        try {
            $options  = $json !== null ? ['json' => $json] : [];
            $response = $this->getClient()->request($method, $path, $options);
        } catch (GuzzleException $e) {
            PluginLogger::error("IONOS HTTP failure on $path", $e->getMessage());
            throw new DriverException(__('IONOS API is unreachable', 'domainmanager'));
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();

        if (in_array($status, [401, 403], true)) {
            throw new DriverException(__('IONOS authentication failed, check the API key', 'domainmanager'));
        }

        if ($status >= 500) {
            throw new DriverException(
                sprintf(__('IONOS API unavailable (HTTP %d)', 'domainmanager'), $status),
            );
        }

        if ($status < 200 || $status >= 300) {
            $summary = self::describeError($body);
            PluginLogger::error("IONOS API error on $path (HTTP $status): $summary");
            throw new DriverException(
                sprintf(
                    __('IONOS API error: %s', 'domainmanager'),
                    $summary !== '' ? $summary : sprintf('HTTP %d', $status),
                ),
            );
        }

        if ($allowEmptyBody && trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            PluginLogger::error("IONOS non-JSON response on $path (HTTP $status)");
            throw new DriverException(__('Unexpected response from the IONOS API', 'domainmanager'));
        }

        return $decoded;
    }

    /**
     * Perform a Domains API call; technical detail goes to the plugin log
     * file, thrown messages are safe to persist. Distinct from request()
     * (DNS API): the Domains API's error envelope is a JSON array of
     * {code, message} objects for application-level errors, not a single
     * {message} object — see describeDomainsApiError() and the class
     * docblock.
     *
     * @param  string $method
     * @param  string $path
     * @param  array  $query
     * @return array  decoded body (the {count, domains} envelope for
     *                `domainitems`, a map for `domainitems/{id}`)
     * @throws DriverException
     */
    private function requestDomainsApi(string $method, string $path, array $query = []): array
    {
        try {
            $response = $this->getDomainsClient()->request($method, $path, ['query' => $query]);
        } catch (GuzzleException $e) {
            PluginLogger::error("IONOS Domains API HTTP failure on $path", $e->getMessage());
            throw new DriverException(__('IONOS Domains API is unreachable', 'domainmanager'));
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();

        if (in_array($status, [401, 403], true)) {
            throw new DriverException(__('IONOS authentication failed, check the API key', 'domainmanager'));
        }

        if ($status === 404) {
            throw new DriverException(__('Domain is not managed by this IONOS account', 'domainmanager'));
        }

        if ($status >= 500) {
            throw new DriverException(
                sprintf(__('IONOS Domains API unavailable (HTTP %d)', 'domainmanager'), $status),
            );
        }

        $decoded = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            $summary = self::describeDomainsApiError($decoded, $body);
            PluginLogger::error("IONOS Domains API error on $path (HTTP $status): $summary");
            throw new DriverException(
                sprintf(
                    __('IONOS Domains API error: %s', 'domainmanager'),
                    $summary !== '' ? $summary : sprintf('HTTP %d', $status),
                ),
            );
        }

        if (!is_array($decoded)) {
            PluginLogger::error("IONOS Domains API non-JSON response on $path (HTTP $status)");
            throw new DriverException(__('Unexpected response from the IONOS Domains API', 'domainmanager'));
        }

        return $decoded;
    }

    /**
     * @return Client
     * @throws DriverException
     */
    private function getClient(): Client
    {
        if ($this->client === null) {
            $this->client = $this->buildClient(self::BASE_URI);
        }

        return $this->client;
    }

    /**
     * @return Client
     * @throws DriverException
     */
    private function getDomainsClient(): Client
    {
        if ($this->domainsClient === null) {
            $this->domainsClient = $this->buildClient(self::DOMAINS_BASE_URI);
        }

        return $this->domainsClient;
    }

    /**
     * Shared client builder for both the DNS and Domains API roots — same
     * credentials, same `X-API-Key: <prefix>.<secret>` header format
     * confirmed for both (see class docblock), different base URI.
     *
     * @param  string $baseUri
     * @return Client
     * @throws DriverException
     */
    private function buildClient(string $baseUri): Client
    {
        $key    = trim((string) ($this->credentials['key'] ?? ''));
        $secret = trim((string) ($this->credentials['secret'] ?? ''));
        if ($key === '' || $secret === '') {
            throw new DriverException(__('IONOS API key/secret are not configured', 'domainmanager'));
        }

        return Toolbox::getGuzzleClient([
            'base_uri'    => $baseUri,
            'timeout'     => self::REQUEST_TIMEOUT,
            'http_errors' => false,
            'headers'     => [
                'X-API-Key' => $key . '.' . $secret,
                'Accept'    => 'application/json',
            ],
        ]);
    }

    /**
     * @param  string $body
     * @return string
     */
    private static function describeError(string $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['message'])) {
            return self::sanitizeMessage((string) $decoded['message']);
        }

        return self::sanitizeMessage($body);
    }

    /**
     * Handles both of the Domains API's error shapes (see class docblock):
     * a documented application-level JSON array of {code, message}
     * objects, or a gateway-level single {message} object shared with the
     * DNS API (returned when the request never reaches the Domains
     * backend at all, e.g. a missing/malformed API key).
     *
     * @param  mixed  $decoded json_decode() result, may be non-array/null
     * @param  string $body    raw body, used when $decoded isn't usable
     * @return string
     */
    private static function describeDomainsApiError(mixed $decoded, string $body): string
    {
        if (is_array($decoded) && isset($decoded[0]['message'])) {
            return self::sanitizeMessage((string) $decoded[0]['message']);
        }

        if (is_array($decoded) && isset($decoded['message'])) {
            return self::sanitizeMessage((string) $decoded['message']);
        }

        return self::sanitizeMessage($body);
    }

    /**
     * Converts a possibly-Unicode/IDN domain name (GLPI's stored `name`) to
     * Punycode/ACE (§9 Phase 10) — the canonical form used throughout this
     * driver for validation and comparison, and what's sent on the wire to
     * the DNS zone API (findZoneId()/fetchZoneRecords()). The Domains
     * (registrar) API's `name` filter doesn't reliably accept *either*
     * form for an IDN domain (Punycode silently matches nothing; literal
     * Unicode gets rejected outright by IONOS's own gateway) — see
     * findDomainId()'s docblock — so that lookup skips the filter and
     * matches client-side instead of relying on a converted query value.
     *
     * @param  string $domain
     * @return string
     * @throws DriverException
     */
    private static function normalizeDomain(string $domain): string
    {
        $domain = IdnNormalizer::toAscii(strtolower(rtrim(trim($domain), '.')));
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z0-9-]+$/i', $domain)) {
            throw new DriverException(__('Domain name is not a valid FQDN', 'domainmanager'));
        }

        return $domain;
    }

    /**
     * @param  string $message
     * @return string
     */
    private static function sanitizeMessage(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', $message) ?? '';

        return mb_substr(trim($message), 0, 250);
    }

    /**
     * @param  mixed $value
     * @return DateTimeImmutable|null
     */
    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
