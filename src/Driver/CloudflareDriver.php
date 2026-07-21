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
use GlpiPlugin\Domainmanager\Contract\RegistrarDriverInterface;
use GlpiPlugin\Domainmanager\Dto\ConnectionTestResult;
use GlpiPlugin\Domainmanager\Dto\DomainLifecycle;
use GlpiPlugin\Domainmanager\Dto\LifecycleStatus;
use GlpiPlugin\Domainmanager\Dto\ZoneRecord;
use GlpiPlugin\Domainmanager\Exception\DriverException;
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
class CloudflareDriver implements RegistrarDriverInterface, DnsPipelineInterface, ConnectionTestableInterface
{
    private const BASE_URI = 'https://api.cloudflare.com/client/v4/';

    private const REQUEST_TIMEOUT = 15;

    private const TEST_TIMEOUT = 9;

    private const PER_PAGE = 100;

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
     * {@inheritDoc}
     */
    public function fetchLifecycle(string $domain): DomainLifecycle
    {
        $domain    = self::normalizeDomain($domain);
        $accountId = $this->requireAccountId();

        // No zone lookup needed here anymore: the account id now comes
        // directly from stored config (was previously only reachable by
        // reading it back out of a zone lookup's response) — and
        // Registrar/DNS are independent Cloudflare products, so requiring
        // a DNS zone to exist before a registrar lookup was an incidental
        // coupling, not a real requirement. request() below already
        // reports a clear "not managed" error if this account has no such
        // registrar domain.
        $data = $this->request(
            'GET',
            'accounts/' . rawurlencode($accountId) . '/registrar/domains/' . rawurlencode($domain)
        );

        $result = $data['result'] ?? null;
        if (!is_array($result) || $result === []) {
            throw new DriverException(
                __('Domain is not managed by Cloudflare Registrar on this account', 'domainmanager')
            );
        }

        $registration = self::parseDate($result['created_at'] ?? null);
        $expiration   = self::parseDate($result['expires_at'] ?? null);

        return new DomainLifecycle(
            $registration,
            $expiration,
            self::mapStatus($result, $expiration),
            // §9 Phase 7: confirmed live against Cloudflare's current
            // Registrar API OpenAPI spec (`registrar-api_domain_properties`,
            // 2026-07-21) — `authInfo`/`domainLock`/`domainType`/
            // `dnsSecEnabled` are genuinely absent from this schema, not
            // merely undocumented (same "confirmed absent" bar already
            // applied to IONOS's missing registrationDate), so they stay
            // null for this driver. `locked` is the *only* lock concept
            // Cloudflare's Registrar API exposes — no separate
            // general-edit-lock field distinct from transfer protection —
            // so it maps to `transferLock`, never `domainLock`.
            null,
            isset($result['privacy']) ? (bool) $result['privacy'] : null,
            null,
            isset($result['locked']) ? (bool) $result['locked'] : null,
            isset($result['auto_renew']) ? (bool) $result['auto_renew'] : null,
            null,
            null
        );
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
                        $isProxied
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
                sprintf(__('No Cloudflare zone found for %s under this account', 'domainmanager'), $domain)
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

        if (in_array($status, [401, 403], true)) {
            throw new DriverException(__('Cloudflare authentication failed, check the API token', 'domainmanager'));
        }

        if ($status >= 500) {
            throw new DriverException(
                sprintf(__('Cloudflare API unavailable (HTTP %d)', 'domainmanager'), $status)
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
                    $summary !== '' ? $summary : sprintf('HTTP %d', $status)
                )
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
     * @param  string $domain
     * @return string
     * @throws DriverException
     */
    private static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
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
     * Map registrar payload to the lifecycle status enum:
     * hold statuses → Suspended, past expiration → Expired, else OK
     *
     * @param  array                  $result
     * @param  DateTimeImmutable|null $expiration
     * @return LifecycleStatus
     */
    private static function mapStatus(array $result, ?DateTimeImmutable $expiration): LifecycleStatus
    {
        $statuses = $result['registry_statuses'] ?? '';
        if (is_array($statuses)) {
            $statuses = implode(',', array_map('strval', $statuses));
        }
        $statuses = strtolower((string) $statuses);

        if (str_contains($statuses, 'hold')) {
            return LifecycleStatus::Suspended;
        }

        if ($expiration !== null && $expiration < new DateTimeImmutable('now')) {
            return LifecycleStatus::Expired;
        }

        return LifecycleStatus::Ok;
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
