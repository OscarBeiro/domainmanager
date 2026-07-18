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
use GlpiPlugin\Domainmanager\Contract\DnsPipelineInterface;
use GlpiPlugin\Domainmanager\Contract\RegistrarDriverInterface;
use GlpiPlugin\Domainmanager\Dto\DomainLifecycle;
use GlpiPlugin\Domainmanager\Dto\LifecycleStatus;
use GlpiPlugin\Domainmanager\Dto\ZoneRecord;
use GlpiPlugin\Domainmanager\Exception\DriverException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Throwable;
use Toolbox;

/**
 * Cloudflare driver: Registrar API (lifecycle) + DNS records API (zone records)
 * Credentials: ['token' => <API token>]
 */
class CloudflareDriver implements RegistrarDriverInterface, DnsPipelineInterface
{
    private const BASE_URI = 'https://api.cloudflare.com/client/v4/';

    private const REQUEST_TIMEOUT = 15;

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
     * {@inheritDoc}
     */
    public function testConnection(): void
    {
        $this->request('GET', 'user/tokens/verify');
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
                    Toolbox::logInFile(
                        'plugin_domainmanager',
                        "Cloudflare record skipped for $domain: " . $e->getMessage() . "\n"
                    );
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
            Toolbox::logInFile('plugin_domainmanager', "Cloudflare HTTP failure on $path: " . $e->getMessage() . "\n");
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
            Toolbox::logInFile('plugin_domainmanager', "Cloudflare non-JSON response on $path (HTTP $status)\n");
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
            Toolbox::logInFile('plugin_domainmanager', "Cloudflare API error on $path (HTTP $status): $summary\n");
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
