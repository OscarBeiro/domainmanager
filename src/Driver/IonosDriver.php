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

use GlpiPlugin\Domainmanager\Contract\ConnectionTestableInterface;
use GlpiPlugin\Domainmanager\Contract\DnsPipelineInterface;
use GlpiPlugin\Domainmanager\Contract\RegistrarDriverInterface;
use GlpiPlugin\Domainmanager\Dto\ConnectionTestResult;
use GlpiPlugin\Domainmanager\Dto\DomainLifecycle;
use GlpiPlugin\Domainmanager\Dto\ZoneRecord;
use GlpiPlugin\Domainmanager\Exception\DriverException;
use GlpiPlugin\Domainmanager\Exception\NotImplementedException;
use GlpiPlugin\Domainmanager\Service\PluginLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Throwable;
use Toolbox;

/**
 * IONOS driver: DNS zone records API is real; the registrar/lifecycle API
 * is NOT implemented (see fetchLifecycle()) — this is a documented gap, not
 * an oversight (§3.9).
 * Credentials: ['key' => <API key prefix>, 'secret' => <API secret>]
 *
 * DNS API docs: https://developer.hosting.ionos.com/docs/dns (a JS-rendered
 * SPA portal with no inline example bodies). Field names and error shapes
 * below were verified two ways:
 * - the maintained third-party client github.com/libdns/ionos (base URI,
 *   zone/record JSON field names, TXT-content quoting behavior)
 * - direct live probing of https://api.hosting.ionos.com/dns/v1 with no/bad
 *   credentials (confirmed the `X-API-Key: <prefix>.<secret>` header format,
 *   and the `{"message": "..."}` error envelope with real HTTP status codes
 *   — 400 "Invalid API key format.", 401 "Missing or invalid API key."/
 *   "Missing or invalid credentials.")
 */
class IonosDriver implements RegistrarDriverInterface, DnsPipelineInterface, ConnectionTestableInterface
{
    private const BASE_URI = 'https://api.hosting.ionos.com/dns/v1/';

    private const REQUEST_TIMEOUT = 15;

    private const TEST_TIMEOUT = 9;

    private ?Client $client = null;

    /**
     * @param array<string, string> $credentials
     */
    public function __construct(private array $credentials)
    {
    }

    /**
     * Only 'dns' is really tested — no verifiable registrar/domain-info API
     * documentation was found (see fetchLifecycle()), so 'registrar' still
     * reports "not implemented" rather than being silently dropped from the
     * UI (an admin should be able to see that gap, not just fail to find a
     * button for it).
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
                __('Not yet implemented for this driver', 'domainmanager')
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
     * Not implemented: no verifiable public documentation exists for
     * IONOS's registrar/domain-info API. `developer.hosting.ionos.com`'s
     * "Domains" product is a JS-rendered Angular SPA with no accessible
     * OpenAPI/Swagger spec found (unlike its DNS product, which has a
     * maintained community client to cross-check against); no official or
     * community SDK/client for it was found either; and its auth gateway
     * returns a uniform 401 for any path under it, so even endpoint
     * *existence* can't be confirmed by probing without real credentials.
     * Flagged rather than guessed — see ARCHITECTURE.md §3.9. Revisit if
     * IONOS ever publishes an accessible spec for this product, or if
     * real credentials become available to explore it directly.
     *
     * {@inheritDoc}
     */
    public function fetchLifecycle(string $domain): DomainLifecycle
    {
        throw new NotImplementedException(
            __('IONOS registrar/domain lifecycle API is not implemented — no verifiable public API documentation was found for it', 'domainmanager')
        );
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
                    (string) ($row['id'] ?? '')
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
            if (is_array($zone) && strcasecmp((string) ($zone['name'] ?? ''), $domain) === 0) {
                return (string) ($zone['id'] ?? '');
            }
        }

        throw new DriverException(
            sprintf(__('No IONOS DNS zone found for %s with this key', 'domainmanager'), $domain)
        );
    }

    /**
     * Perform an API call; technical detail goes to the plugin log file,
     * thrown messages are safe to persist
     *
     * @param  string $method
     * @param  string $path
     * @return array decoded body (a list for `zones`, a map for `zones/{id}`)
     * @throws DriverException
     */
    private function request(string $method, string $path): array
    {
        try {
            $response = $this->getClient()->request($method, $path);
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
                sprintf(__('IONOS API unavailable (HTTP %d)', 'domainmanager'), $status)
            );
        }

        $decoded = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            $summary = self::describeError($body);
            PluginLogger::error("IONOS API error on $path (HTTP $status): $summary");
            throw new DriverException(
                sprintf(
                    __('IONOS API error: %s', 'domainmanager'),
                    $summary !== '' ? $summary : sprintf('HTTP %d', $status)
                )
            );
        }

        if (!is_array($decoded)) {
            PluginLogger::error("IONOS non-JSON response on $path (HTTP $status)");
            throw new DriverException(__('Unexpected response from the IONOS API', 'domainmanager'));
        }

        return $decoded;
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

        $key    = trim((string) ($this->credentials['key'] ?? ''));
        $secret = trim((string) ($this->credentials['secret'] ?? ''));
        if ($key === '' || $secret === '') {
            throw new DriverException(__('IONOS API key/secret are not configured', 'domainmanager'));
        }

        $this->client = Toolbox::getGuzzleClient([
            'base_uri'    => self::BASE_URI,
            'timeout'     => self::REQUEST_TIMEOUT,
            'http_errors' => false,
            'headers'     => [
                'X-API-Key' => $key . '.' . $secret,
                'Accept'    => 'application/json',
            ],
        ]);

        return $this->client;
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
     * @param  string $message
     * @return string
     */
    private static function sanitizeMessage(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', $message) ?? '';

        return mb_substr(trim($message), 0, 250);
    }
}
