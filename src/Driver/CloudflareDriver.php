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
 * Credentials: ['token' => <API token>]
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
     * independently via user/tokens/verify, so only 'dns' is reported even
     * though this class also implements RegistrarDriverInterface for the
     * real sync pipeline (§3.5).
     *
     * {@inheritDoc}
     */
    public function testConnection(array $credentials): array
    {
        $previous           = $this->credentials;
        $this->credentials  = $credentials;
        $this->client       = null;

        try {
            $result = $this->probeTokenVerify();
        } catch (Throwable $e) {
            $result = ConnectionTestResult::fromException('dns', $e);
        } finally {
            $this->credentials = $previous;
            $this->client       = null;
        }

        return ['dns' => $result];
    }

    /**
     * Direct HTTP probe used only by testConnection(): unlike request(), it
     * inspects the raw status code itself so the result can be classified
     * precisely (401 -> AuthFailed, etc.) instead of collapsing into the
     * generic DriverException messages used by the sync pipelines.
     *
     * @return ConnectionTestResult
     * @throws DriverException when the token is empty or the API is unreachable
     */
    private function probeTokenVerify(): ConnectionTestResult
    {
        $client = $this->getClient();

        try {
            $response = $client->request('GET', 'user/tokens/verify', ['timeout' => self::TEST_TIMEOUT]);
        } catch (GuzzleException $e) {
            PluginLogger::error('Cloudflare connection test HTTP failure: ' . $e->getMessage());
            throw $e;
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();
        $data   = json_decode($body, true);
        $success = is_array($data) && ($data['success'] ?? false) === true;

        $raw_detail = $success ? '' : ('Cloudflare user/tokens/verify (HTTP ' . $status . '): ' . self::sanitizeMessage($body));

        return ConnectionTestResult::fromHttpResponse('dns', $status, $success, $raw_detail);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchLifecycle(string $domain): DomainLifecycle
    {
        $domain = self::normalizeDomain($domain);
        $zone   = $this->findZone($domain);

        if ($zone['account_id'] === '') {
            throw new DriverException(__('Cloudflare zone has no readable account', 'domainmanager'));
        }

        $data = $this->request(
            'GET',
            'accounts/' . rawurlencode($zone['account_id']) . '/registrar/domains/' . rawurlencode($domain)
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
            self::mapStatus($result, $expiration)
        );
    }

    /**
     * {@inheritDoc}
     */
    public function fetchZoneRecords(string $domain): array
    {
        $domain = self::normalizeDomain($domain);
        $zone   = $this->findZone($domain);

        $records = [];
        $page    = 1;

        do {
            $data = $this->request('GET', 'zones/' . rawurlencode($zone['id']) . '/dns_records', [
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

                try {
                    $records[] = new ZoneRecord(
                        $type,
                        (string) ($row['name'] ?? ''),
                        $content,
                        (int) ($row['ttl'] ?? 0),
                        (string) ($row['id'] ?? '')
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
     * Resolve the zone (and owning account) of a domain
     *
     * @param  string $domain
     * @return array{id: string, account_id: string}
     * @throws DriverException
     */
    private function findZone(string $domain): array
    {
        $data = $this->request('GET', 'zones', ['name' => $domain, 'per_page' => 1]);

        $zone = $data['result'][0] ?? null;
        if (!is_array($zone) || empty($zone['id'])) {
            throw new DriverException(
                sprintf(__('No Cloudflare zone found for %s with this token', 'domainmanager'), $domain)
            );
        }

        return [
            'id'         => (string) $zone['id'],
            'account_id' => (string) ($zone['account']['id'] ?? ''),
        ];
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
