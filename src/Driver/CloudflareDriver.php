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
use GlpiPlugin\Domainmanager\Dto\ConnectionTestStatus;
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
 * Cloudflare driver: Registrar API (lifecycle) + DNS records API (zone records)
 * Credentials: ['account_id' => <Cloudflare Account ID>, 'token' => <API token>]
 *
 * **Only account-scoped API Tokens are supported** (created under Manage
 * Account → API Tokens, `cfat_...`) — a personal/user token (My Profile →
 * API Tokens) is NOT supported and will fail: it's tied to whatever zones
 * the creating human happens to have access to, breaks if that user loses
 * access, and — confirmed live — cannot even authenticate against
 * probeTokenVerify()'s account-scoped verify endpoint below, so it fails
 * Check Connection with a plain auth-failed/401 regardless of how valid
 * or well-scoped it otherwise is. An account token belongs to the
 * Cloudflare Account itself (§addendum "Switch Cloudflare Driver to
 * Account-Scoped API Tokens"). Both token *types* authenticate identically
 * (`Authorization: Bearer <token>`) — the incompatibility is entirely
 * about which *endpoints* accept which token type, not the auth
 * mechanism itself.
 *
 * The stored Account ID is used two ways, confirmed against Cloudflare's
 * current, live OpenAPI spec (`github.com/cloudflare/api-schemas`,
 * `GET /zones` operation) rather than assumed from memory:
 * - `findZone()` passes it as the literal `account.id` query parameter
 *   (confirmed exact name/shape in the spec — not `account_id`, not a
 *   nested `account[id]`/deepObject form) to scope the zone lookup to
 *   this account, rather than trusting whichever zone a broader-scoped
 *   token might otherwise resolve to.
 * - `fetchLifecycle()` uses it directly as the `accounts/{id}/...` path
 *   segment for the Registrar API, instead of the previous approach of
 *   reading `account.id` back out of the zone lookup's own response.
 */
class CloudflareDriver implements RegistrarDriverInterface, DnsPipelineInterface, ConnectionTestableInterface, DomainDiscoveryInterface, DnsRecordWriterInterface
{
    private const BASE_URI = 'https://api.cloudflare.com/client/v4/';

    private const REQUEST_TIMEOUT = 15;

    private const TEST_TIMEOUT = 9;

    private const PER_PAGE = 100;

    // The Registrations list endpoint's `per_page` is capped at 50 (confirmed
    // in the live spec), unlike the DNS records endpoint's 100 (PER_PAGE
    // above) — a genuinely different limit, not reused.
    private const REGISTRATIONS_PER_PAGE = 50;

    private const MAX_PAGES = 50;

    private ?Client $client = null;

    /**
     * @param array<string, string> $credentials
     */
    public function __construct(private array $credentials)
    {
    }

    /**
     * Only the DNS/zone capability is reported: Cloudflare's registrar API
     * requires a specific domain name to query
     * (accounts/{id}/registrar/domains/{domain}), which isn't known at
     * credential-test time — there's no domain-independent registrar
     * endpoint to probe. The DNS/zone capability can be verified
     * independently via accounts/{id}/tokens/verify, so only 'dns' is
     * reported even though this class also implements
     * RegistrarDriverInterface for the real sync pipeline (§3.5).
     *
     * {@inheritDoc}
     */
    public function testConnection(array $credentials): array
    {
        $previous           = $this->credentials;
        $this->credentials  = $credentials;
        $this->client       = null;

        try {
            $missing = self::missingConfigMessage($credentials);
            $result  = $missing !== null
                ? ConnectionTestResult::notConfigured('dns', $missing)
                : $this->probeTokenVerify();

            // tokens/verify only proves the token itself is valid/unrevoked
            // — it says nothing about whether it's actually scoped for
            // Zone:Read/DNS:Read (the Check Connection gap: a token can
            // pass this and still 403 on every real DNS sync). Only run
            // the follow-up zone probe once verify has already succeeded;
            // no point layering a second call on top of a result that's
            // already a definite failure.
            if ($result->status === ConnectionTestStatus::Success) {
                $result = $this->probeZoneScope($this->requireAccountId());
            }
        } catch (Throwable $e) {
            $result = ConnectionTestResult::fromException('dns', $e);
        } finally {
            $this->credentials = $previous;
            $this->client       = null;
        }

        return ['dns' => $result];
    }

    /**
     * Checked explicitly before attempting any network call, rather than
     * left to throw and fall into fromException()'s generic classifier —
     * a missing Account ID is a distinct, actionable "you haven't finished
     * configuring this" state, not the same thing as an invalid/rejected
     * token (§addendum "Switch Cloudflare Driver to Account-Scoped API
     * Tokens").
     *
     * @param  array<string, string> $credentials
     * @return string|null null when both required fields are present
     */
    private static function missingConfigMessage(array $credentials): ?string
    {
        if (trim((string) ($credentials['account_id'] ?? '')) === '') {
            return __('Cloudflare Account ID is not configured', 'domainmanager');
        }

        if (trim((string) ($credentials['token'] ?? '')) === '') {
            return __('Cloudflare API token is not configured', 'domainmanager');
        }

        return null;
    }

    /**
     * Direct HTTP probe used only by testConnection(): unlike request(), it
     * inspects the raw status code itself so the result can be classified
     * precisely (401 -> AuthFailed, etc.) instead of collapsing into the
     * generic DriverException messages used by the sync pipelines.
     *
     * Uses `accounts/{account_id}/tokens/verify`, **not** `user/tokens/verify`
     * — confirmed live (real account token, real 401) and against Cloudflare's
     * current OpenAPI spec that these are two distinct endpoints and an
     * account-owned token (`cfat_...`) only verifies against the former.
     * `user/tokens/verify` is scoped to a *user* identity, which an
     * account-owned token has none of — it rejects an otherwise perfectly
     * valid account token with a plain 401, which read as "authentication
     * failed" even though nothing was actually wrong with the token
     * (§addendum "Switch Cloudflare Driver to Account-Scoped API Tokens" —
     * this was a real, initially-missed bug in that change, not a
     * credentials mistake on the reporter's part).
     *
     * @return ConnectionTestResult
     * @throws DriverException when the token/account id is empty or the API is unreachable
     */
    private function probeTokenVerify(): ConnectionTestResult
    {
        $accountId = $this->requireAccountId();
        $client    = $this->getClient();
        $path      = 'accounts/' . rawurlencode($accountId) . '/tokens/verify';

        try {
            $response = $client->request('GET', $path, ['timeout' => self::TEST_TIMEOUT]);
        } catch (GuzzleException $e) {
            PluginLogger::error('Cloudflare connection test HTTP failure: ' . $e->getMessage());
            throw $e;
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();
        $data   = json_decode($body, true);
        $success = is_array($data) && ($data['success'] ?? false) === true;

        $raw_detail = $success ? '' : ('Cloudflare ' . $path . ' (HTTP ' . $status . '): ' . self::sanitizeMessage($body));

        return ConnectionTestResult::fromHttpResponse('dns', $status, $success, $raw_detail);
    }

    /**
     * Closes the actual Check Connection gap: `probeTokenVerify()` above
     * only proves the token itself hasn't been revoked, not that it
     * carries `Zone:Read`/`DNS:Read` for this account's zones — a token
     * can pass that probe and still 403 on every real sync call
     * (`findZone()`, `fetchZoneRecords()`). This issues the same
     * account-scoped `GET /zones` lookup `findZone()` uses for a real
     * sync (`account.id` + `per_page=1`, no `name` filter — the goal here
     * is only to prove the *scope* exists, not that any particular zone
     * does), and classifies the raw HTTP status directly rather than via
     * `request()` — `request()`'s own 401/403 handling throws a
     * `DriverException` with no status code attached, which
     * `fromException()` would then have to reclassify blind.
     *
     * A `200` with an empty `result` (this account genuinely has zero
     * zones) still counts as scope confirmed — that's a "nothing to sync
     * yet" state, not a permissions problem, and must not be reported as
     * a failure.
     *
     * @param  string $accountId
     * @return ConnectionTestResult
     * @throws DriverException when the API is unreachable
     */
    private function probeZoneScope(string $accountId): ConnectionTestResult
    {
        $client = $this->getClient();
        $path   = 'zones';
        $query  = ['account.id' => $accountId, 'per_page' => 1];

        try {
            $response = $client->request('GET', $path, ['query' => $query, 'timeout' => self::TEST_TIMEOUT]);
        } catch (GuzzleException $e) {
            PluginLogger::error('Cloudflare connection test HTTP failure: ' . $e->getMessage());
            throw $e;
        }

        $status  = $response->getStatusCode();
        $body    = (string) $response->getBody();
        $data    = json_decode($body, true);
        $success = is_array($data) && ($data['success'] ?? false) === true;

        $raw_detail = $success ? '' : ('Cloudflare ' . $path . ' (HTTP ' . $status . '): ' . self::sanitizeMessage($body));

        $result = ConnectionTestResult::fromHttpResponse('dns', $status, $success, $raw_detail);

        // fromHttpResponse()'s generic 403 message ("lack the required
        // permission/scope") doesn't name the scope — reuse the exact,
        // actionable wording request()'s own 403 branch already gives
        // real sync failures, so Check Connection and a sync failure read
        // as the same diagnosis instead of two different sentences for
        // the same root cause.
        if ($status === 403) {
            $result = new ConnectionTestResult(
                $result->status,
                'dns',
                $status,
                __('This Cloudflare API token lacks DNS:Read permission for this zone', 'domainmanager'),
                $raw_detail,
                $result->checkedAt,
            );
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchLifecycle(string $domain): DomainLifecycle
    {
        $domain    = self::normalizeDomain($domain);
        $accountId = $this->requireAccountId();

        // Uses the newer `/registrar/registrations` API, not
        // `/registrar/domains` (which this method used until 2026-07-27):
        // confirmed against Cloudflare's current, live OpenAPI spec that
        // the older Registrar Domains endpoints (list + get) are marked
        // `deprecated: true` with an EOL of 2026-09-27 and an explicit
        // `x-stainless-deprecation-message` pointing at
        // domain-search/domain-check/registrations as the replacement —
        // migrated ahead of that date rather than waiting for it to break.
        // No zone lookup needed here: the account id comes directly from
        // stored config, and Registrar/DNS are independent Cloudflare
        // products, so requiring a DNS zone to exist before a registrar
        // lookup was an incidental coupling, not a real requirement.
        // A 4XX "Domain not found" response is surfaced by request()
        // itself (it throws on `success !== true`, using the API's own
        // error message) — no separate empty-result check needed, unlike
        // the old endpoint's shape.
        $data = $this->request(
            'GET',
            'accounts/' . rawurlencode($accountId) . '/registrar/registrations/' . rawurlencode($domain),
        );

        $result = $data['result'] ?? null;
        if (!is_array($result)) {
            throw new DriverException(
                __('Domain is not managed by Cloudflare Registrar on this account', 'domainmanager'),
            );
        }

        $registration = self::parseDate($result['created_at'] ?? null);
        $expiration   = self::parseDate($result['expires_at'] ?? null);

        // `privacy_mode` (confirmed enum: `false` (literal boolean) or the
        // string `"redaction"`) replaces the old endpoint's plain `privacy`
        // bool — normalized to the same bool-or-null shape this DTO expects.
        $privacyMode = $result['privacy_mode'] ?? null;
        $privacy     = $privacyMode === null ? null : ($privacyMode === 'redaction' || $privacyMode === true);

        return new DomainLifecycle(
            $registration,
            $expiration,
            self::mapStatus($result),
            // §9 Phase 7 (still true on the new schema, re-confirmed
            // 2026-07-27): `authInfo`/`domainLock`/`domainType`/
            // `dnsSecEnabled` remain genuinely absent — `locked` is still
            // the only lock concept exposed, so it still maps to
            // `transferLock`, never `domainLock`.
            null,
            $privacy,
            null,
            isset($result['locked']) ? (bool) $result['locked'] : null,
            isset($result['auto_renew']) ? (bool) $result['auto_renew'] : null,
            null,
            null,
        );
    }

    /**
     * {@inheritDoc}
     *
     * §9 Phase 8 addendum (2026-07-27): uses the same `/registrar/registrations`
     * list endpoint fetchLifecycle() was just migrated to — cursor-paginated
     * (confirmed live spec: `result_info.cursor`, empty string = last page),
     * unlike IONOS/Dinahosting's offset/flat pagination. `previewStatus`
     * mirrors Dinahosting's pattern (a free, genuinely useful preview field
     * this API happens to include) rather than IONOS's left-null gap.
     */
    public function listAccountDomains(): array
    {
        $accountId = $this->requireAccountId();
        $domains   = [];
        $cursor    = '';

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['per_page' => self::REGISTRATIONS_PER_PAGE];
            if ($cursor !== '') {
                $query['cursor'] = $cursor;
            }

            $data = $this->request(
                'GET',
                'accounts/' . rawurlencode($accountId) . '/registrar/registrations',
                $query,
            );

            foreach ($data['result'] ?? [] as $row) {
                if (!is_array($row) || empty($row['domain_name'])) {
                    continue;
                }

                $expiresAt      = trim((string) ($row['expires_at'] ?? ''));
                $previewStatus  = $expiresAt !== ''
                    ? sprintf(__('Expires %s', 'domainmanager'), $expiresAt)
                    : null;

                $domains[] = new DiscoveredDomain((string) $row['domain_name'], $previewStatus);
            }

            $cursor = (string) ($data['result_info']['cursor'] ?? '');
            if ($cursor === '') {
                break;
            }
        }

        return $domains;
    }

    /**
     * {@inheritDoc}
     */
    public function fetchZoneRecords(string $domain): array
    {
        $domain    = self::normalizeDomain($domain);
        $accountId = $this->requireAccountId();
        $zoneId    = $this->findZone($domain, $accountId);

        $records = [];
        $page    = 1;

        do {
            $data = $this->request('GET', 'zones/' . rawurlencode($zoneId) . '/dns_records', [
                'per_page' => self::PER_PAGE,
                'page'     => $page,
            ]);

            foreach ($data['result'] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $type = strtoupper((string) ($row['type'] ?? ''));
                if (!in_array($type, ZoneRecord::TYPES, true)) {
                    continue; // read-only scope: unknown types skipped
                }

                $content = (string) ($row['content'] ?? '');
                if ($type === 'MX' && isset($row['priority'])) {
                    $content = (int) $row['priority'] . ' ' . $content;
                }

                // `proxiable` is Cloudflare's own live per-record answer to
                // "can this specific record be proxied" (confirmed in its
                // current API docs — present on every record type, not just
                // A/AAAA/CNAME) — trusted over a hardcoded type list, same
                // "the API's own answer wins" principle used elsewhere in
                // this plugin. `proxied` (the actual orange/grey cloud
                // state) is only meaningful when `proxiable` is true.
                $isProxied = ($row['proxiable'] ?? false) ? (bool) ($row['proxied'] ?? false) : null;

                try {
                    $records[] = new ZoneRecord(
                        $type,
                        (string) ($row['name'] ?? ''),
                        $content,
                        (int) ($row['ttl'] ?? 0),
                        (string) ($row['id'] ?? ''),
                        $isProxied,
                    );
                } catch (InvalidArgumentException $e) {
                    PluginLogger::activity("Cloudflare record skipped for $domain: " . $e->getMessage());
                }
            }

            $total_pages = (int) ($data['result_info']['total_pages'] ?? 1);
            $page++;
        } while ($page <= $total_pages && $page <= self::MAX_PAGES);

        return $records;
    }

    /**
     * {@inheritDoc}
     *
     * ARCHITECTURE.md §12.4 Phase 42: `POST /zones/{zoneId}/dns_records` with
     * `{name, type, content, ttl, proxied}` — `proxied` always sent
     * explicitly, `false` here since a brand-new record has no prior proxy
     * state to preserve (§12.2 item 2). `id` is read straight off the
     * `201` response, no follow-up GET (§11.9's convention, kept — §12.4
     * point 1).
     *
     * Cloudflare's create-conflict code `81057` ("record already exists") is
     * not treated as a failure (§12.7): the existing record is looked up by
     * type+name and adopted, rather than surfacing an error for a record
     * that, from the caller's point of view, already exists in the desired
     * state.
     */
    public function createRecord(string $domain, string $type, string $name, string $data, int $ttl): ZoneRecord
    {
        $type      = self::assertWritableType($type);
        $domain    = self::normalizeDomain($domain);
        $accountId = $this->requireAccountId();
        $zoneId    = $this->findZone($domain, $accountId);

        $result = $this->writeRequest('POST', 'zones/' . rawurlencode($zoneId) . '/dns_records', [
            'name'    => $name,
            'type'    => $type,
            'content' => $data,
            'ttl'     => $ttl,
            'proxied' => false,
        ]);

        if ($result['status'] === 200 || $result['status'] === 201) {
            $row = $result['data']['result'] ?? null;
            if (!is_array($row)) {
                throw new DriverException(__('Cloudflare did not return the created record', 'domainmanager'));
            }

            return self::rowToZoneRecord($type, $row);
        }

        if (self::hasErrorCode($result['data'], 81057)) {
            $existing = self::findRecordByTypeName($zoneId, $type, $name);
            if ($existing !== null) {
                return $existing;
            }
        }

        throw self::toException($result, 'create');
    }

    /**
     * {@inheritDoc}
     *
     * §12.4 point 2: `PUT /zones/{zoneId}/dns_records/{remoteId}` with the
     * full known field set (§12.2 item 1's design stance — safer against an
     * unverified full-replace than the reverse). The record's current
     * `proxied` value is read live first and preserved, rather than assuming
     * a default, so an update never silently toggles the proxy cloud
     * (§12.2 item 2); a failed pre-read is non-fatal and falls back to
     * `false`, logged rather than aborting the update over it.
     */
    public function updateRecord(string $domain, string $remoteId, string $type, string $name, string $data, int $ttl): ZoneRecord
    {
        $type      = self::assertWritableType($type);
        $domain    = self::normalizeDomain($domain);
        $accountId = $this->requireAccountId();
        $zoneId    = $this->findZone($domain, $accountId);

        $proxied = self::currentProxiedState($zoneId, $remoteId);

        $result = $this->writeRequest('PUT', 'zones/' . rawurlencode($zoneId) . '/dns_records/' . rawurlencode($remoteId), [
            'name'    => $name,
            'type'    => $type,
            'content' => $data,
            'ttl'     => $ttl,
            'proxied' => $proxied,
        ]);

        if ($result['status'] === 200) {
            $row = $result['data']['result'] ?? null;
            if (!is_array($row)) {
                throw new DriverException(__('Cloudflare did not return the updated record', 'domainmanager'));
            }

            return self::rowToZoneRecord($type, $row);
        }

        throw self::toException($result, 'update');
    }

    /**
     * {@inheritDoc}
     *
     * §12.4 point 3 / §12.7: `DELETE /zones/{zoneId}/dns_records/{remoteId}`;
     * a `404` (already gone) is treated as success, not an error — the
     * desired end state (no such record) already holds.
     */
    public function deleteRecord(string $domain, string $remoteId): void
    {
        $domain    = self::normalizeDomain($domain);
        $accountId = $this->requireAccountId();
        $zoneId    = $this->findZone($domain, $accountId);

        $result = $this->writeRequest('DELETE', 'zones/' . rawurlencode($zoneId) . '/dns_records/' . rawurlencode($remoteId));

        if ($result['status'] === 200 || $result['status'] === 404) {
            return;
        }

        throw self::toException($result, 'delete');
    }

    /**
     * {@inheritDoc}
     */
    public function fetchRecord(string $domain, string $remoteId): ZoneRecord
    {
        $domain    = self::normalizeDomain($domain);
        $accountId = $this->requireAccountId();
        $zoneId    = $this->findZone($domain, $accountId);

        $data = $this->request('GET', 'zones/' . rawurlencode($zoneId) . '/dns_records/' . rawurlencode($remoteId));
        $row  = $data['result'] ?? null;
        if (!is_array($row)) {
            throw new DriverException(__('Cloudflare did not return this record', 'domainmanager'));
        }

        return self::rowToZoneRecord(strtoupper((string) ($row['type'] ?? '')), $row);
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
     * Live-read a record's current `proxied` value before an update, so the
     * write never has to guess or default one away (§12.2 item 2). Failure
     * to read is deliberately non-fatal — logged and treated as `false`
     * rather than aborting the whole update over a read that isn't the
     * actual operation being requested.
     *
     * @param  string $zoneId
     * @param  string $remoteId
     * @return bool
     */
    private function currentProxiedState(string $zoneId, string $remoteId): bool
    {
        try {
            $data = $this->request('GET', 'zones/' . rawurlencode($zoneId) . '/dns_records/' . rawurlencode($remoteId));
            $row  = $data['result'] ?? null;
            if (is_array($row) && ($row['proxiable'] ?? false)) {
                return (bool) ($row['proxied'] ?? false);
            }
        } catch (Throwable $e) {
            PluginLogger::activity('Could not read current Cloudflare proxied state for record ' . $remoteId . ': ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Adopt an already-existing record on a `81057` create-conflict (§12.7).
     *
     * @param  string $zoneId
     * @param  string $type
     * @param  string $name
     * @return ZoneRecord|null null when no matching record is found
     */
    private function findRecordByTypeName(string $zoneId, string $type, string $name): ?ZoneRecord
    {
        $data = $this->request('GET', 'zones/' . rawurlencode($zoneId) . '/dns_records', [
            'type'     => $type,
            'name'     => $name,
            'per_page' => 1,
        ]);

        $row = $data['result'][0] ?? null;

        return is_array($row) ? self::rowToZoneRecord($type, $row) : null;
    }

    /**
     * @param  string $type
     * @param  array  $row decoded Cloudflare DNS record object
     * @return ZoneRecord
     */
    private static function rowToZoneRecord(string $type, array $row): ZoneRecord
    {
        $isProxied = ($row['proxiable'] ?? false) ? (bool) ($row['proxied'] ?? false) : null;

        return new ZoneRecord(
            $type,
            (string) ($row['name'] ?? ''),
            (string) ($row['content'] ?? ''),
            (int) ($row['ttl'] ?? 0),
            (string) ($row['id'] ?? ''),
            $isProxied,
        );
    }

    /**
     * @param  array|null $data decoded response body
     * @param  int        $code Cloudflare error code to look for
     * @return bool
     */
    private static function hasErrorCode(?array $data, int $code): bool
    {
        foreach ($data['errors'] ?? [] as $error) {
            if (is_array($error) && (int) ($error['code'] ?? 0) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Classify a failed write result into a safe-to-persist `DriverException`
     * (§12.4 "error mapping stays inside the driver", §12.6). Only a `403`
     * is treated as a genuine write-permission denial — the one case that
     * flips `dns_write_status` to `managed_readonly` (§12.3) — with a
     * message that names the missing permission rather than a bare
     * "403"/"forbidden". Every other failure is a one-off warning surfaced
     * via the generic message below, not a permission signal.
     *
     * @param  array  $result ['status' => int, 'data' => array|null]
     * @param  string $operation 'create'|'update'|'delete', for the generic message only
     * @return DriverException
     */
    private static function toException(array $result, string $operation): DriverException
    {
        $status = $result['status'];

        if ($status === 403) {
            return new DriverException(
                __('This Cloudflare API token lacks DNS:Edit permission for this zone', 'domainmanager'),
                true,
            );
        }

        $messages = [];
        foreach ($result['data']['errors'] ?? [] as $error) {
            if (is_array($error) && isset($error['message'])) {
                $messages[] = (string) $error['message'];
            }
        }
        $summary = self::sanitizeMessage(implode('; ', $messages));

        return new DriverException(sprintf(
            __('Cloudflare %1$s failed: %2$s', 'domainmanager'),
            $operation,
            $summary !== '' ? $summary : sprintf('HTTP %d', $status),
        ));
    }

    /**
     * Direct HTTP call for the write methods above: unlike request(), it
     * returns the raw status/body pair instead of throwing on a non-2xx
     * status, so each write method can classify 403/404/81057 itself
     * (§12.4, §12.6) rather than collapsing into request()'s generic
     * auth-failed/unavailable messages.
     *
     * @param  string     $method
     * @param  string     $path
     * @param  array|null $json request body, sent as `json` when not null
     * @return array{status:int, data:array|null}
     * @throws DriverException when the API is unreachable or the response is not JSON
     */
    private function writeRequest(string $method, string $path, ?array $json = null): array
    {
        $options = $json !== null ? ['json' => $json] : [];

        try {
            $response = $this->getClient()->request($method, $path, $options);
        } catch (GuzzleException $e) {
            PluginLogger::error("Cloudflare HTTP failure on $path", $e->getMessage());
            throw new DriverException(__('Cloudflare API is unreachable', 'domainmanager'));
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();
        $data   = json_decode($body, true);

        if (!is_array($data)) {
            PluginLogger::error("Cloudflare non-JSON response on $path (HTTP $status)");
            throw new DriverException(__('Unexpected response from the Cloudflare API', 'domainmanager'));
        }

        if (($data['success'] ?? false) !== true && !in_array($status, [403, 404], true)) {
            PluginLogger::error(
                "Cloudflare API error on $path (HTTP $status): " . self::sanitizeMessage($body),
            );
        }

        return ['status' => $status, 'data' => $data];
    }

    /**
     * Resolve the zone id of a domain, scoped to the configured account.
     * `account.id` is the literal, confirmed query parameter name (live
     * Cloudflare OpenAPI spec, `GET /zones` — not `account_id`, not a
     * nested/deepObject form) — filtering by it, rather than trusting the
     * first name match across whatever the token can see, is the real fix
     * here: previously a broader-scoped token could silently resolve to a
     * zone under a *different* account than intended (§addendum "Switch
     * Cloudflare Driver to Account-Scoped API Tokens").
     *
     * @param  string $domain
     * @param  string $accountId
     * @return string zone id
     * @throws DriverException
     */
    private function findZone(string $domain, string $accountId): string
    {
        $data = $this->request('GET', 'zones', [
            'name'       => $domain,
            'account.id' => $accountId,
            'per_page'   => 1,
        ]);

        $zone = $data['result'][0] ?? null;
        if (!is_array($zone) || empty($zone['id'])) {
            throw new DriverException(
                sprintf(__('No Cloudflare zone found for %s under this account', 'domainmanager'), $domain),
            );
        }

        return (string) $zone['id'];
    }

    /**
     * Perform an API call; technical detail goes to the plugin log file,
     * thrown messages are safe to persist
     *
     * @param  string $method
     * @param  string $path
     * @param  array  $query
     * @return array decoded body
     * @throws DriverException
     */
    private function request(string $method, string $path, array $query = []): array
    {
        try {
            $response = $this->getClient()->request($method, $path, ['query' => $query]);
        } catch (GuzzleException $e) {
            PluginLogger::error("Cloudflare HTTP failure on $path", $e->getMessage());
            throw new DriverException(__('Cloudflare API is unreachable', 'domainmanager'));
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();
        $data   = json_decode($body, true);

        // 401 and 403 are genuinely different failures, not one "auth
        // failed" bucket (found live, ARCHITECTURE.md §12.3's zone-scoped-
        // token reasoning applies here too, not just to writes): a 401
        // means the token itself is invalid/revoked, but a 403 here
        // typically means a perfectly valid token that simply isn't scoped
        // for DNS:Read on this zone — telling the user to "check the API
        // token" in that case is actively misleading, since the token
        // doesn't need replacing, it needs the zone/DNS scope added.
        if ($status === 401) {
            PluginLogger::error(
                "Cloudflare authentication failed on $path (HTTP $status): " . self::sanitizeMessage($body),
            );
            throw new DriverException(__('Cloudflare authentication failed, check the API token', 'domainmanager'));
        }

        if ($status === 403) {
            PluginLogger::error(
                "Cloudflare authorization failed on $path (HTTP $status): " . self::sanitizeMessage($body),
            );
            throw new DriverException(
                __('This Cloudflare API token lacks DNS:Read permission for this zone', 'domainmanager'),
            );
        }

        if ($status >= 500) {
            throw new DriverException(
                sprintf(__('Cloudflare API unavailable (HTTP %d)', 'domainmanager'), $status),
            );
        }

        if (!is_array($data)) {
            PluginLogger::error("Cloudflare non-JSON response on $path (HTTP $status)");
            throw new DriverException(__('Unexpected response from the Cloudflare API', 'domainmanager'));
        }

        if (($data['success'] ?? false) !== true) {
            $messages = [];
            foreach ($data['errors'] ?? [] as $error) {
                if (is_array($error) && isset($error['message'])) {
                    $messages[] = (string) $error['message'];
                }
            }
            $summary = self::sanitizeMessage(implode('; ', $messages));
            PluginLogger::error("Cloudflare API error on $path (HTTP $status): $summary");
            throw new DriverException(
                sprintf(
                    __('Cloudflare API error: %s', 'domainmanager'),
                    $summary !== '' ? $summary : sprintf('HTTP %d', $status),
                ),
            );
        }

        return $data;
    }

    /**
     * @return Client
     * @throws DriverException
     */
    private function getClient(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $token = trim((string) ($this->credentials['token'] ?? ''));
        if ($token === '') {
            throw new DriverException(__('Cloudflare API token is not configured', 'domainmanager'));
        }

        $this->client = Toolbox::getGuzzleClient([
            'base_uri'    => self::BASE_URI,
            'timeout'     => self::REQUEST_TIMEOUT,
            'http_errors' => false,
            'headers'     => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ]);

        return $this->client;
    }

    /**
     * @return string
     * @throws DriverException
     */
    private function requireAccountId(): string
    {
        $accountId = trim((string) ($this->credentials['account_id'] ?? ''));
        if ($accountId === '') {
            throw new DriverException(__('Cloudflare Account ID is not configured', 'domainmanager'));
        }

        return $accountId;
    }

    /**
     * Converts a possibly-Unicode/IDN domain name (GLPI's stored `name`) to
     * Punycode/ACE before it ever reaches the Cloudflare API (§9 Phase 10)
     * — no documented Unicode-vs-Punycode requirement was found for this
     * API, so Punycode is used as the safe universal outbound form.
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

    /**
     * Map the new Registrations API's `status` enum (confirmed exhaustive
     * in the live spec: active | registration_pending | expired | suspended
     * | redemption_period | pending_delete) to the plugin's lifecycle enum.
     * Replaces the old endpoint's free-text `registry_statuses` substring
     * match ("contains 'hold'") — this API reports the state directly,
     * no guessing needed. `redemption_period`/`pending_delete` are both
     * post-expiration registry states, mapped to Expired same as `expired`
     * itself; there's no existing case finer-grained than that.
     *
     * @param  array $result
     * @return LifecycleStatus
     */
    private static function mapStatus(array $result): LifecycleStatus
    {
        return match ((string) ($result['status'] ?? '')) {
            'suspended'                            => LifecycleStatus::Suspended,
            'expired', 'redemption_period', 'pending_delete' => LifecycleStatus::Expired,
            'registration_pending'                 => LifecycleStatus::Pending,
            default                                => LifecycleStatus::Ok,
        };
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
}
