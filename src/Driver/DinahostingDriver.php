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
use GlpiPlugin\Domainmanager\Dto\ConnectionTestStatus;
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
 * Dinahosting driver: registrar API (lifecycle) + DNS zone records API
 * Credentials: ['user' => <username>, 'password' => <password>]
 *
 * API docs: https://en.dinahosting.com/api/documentation (command index
 * only — the site doesn't inline example request/response bodies). The
 * exact JSON envelope and field names used below were verified against:
 * - the official docs' example request URL (query param names, base path)
 * - github.com/libdns/dinahosting's provider.go (a maintained, working
 *   third-party client), for the JSON envelope shape
 *   ({trId, responseCode, message, data, errors, command}), the success
 *   check (message === "Success." || responseCode === 1000), and confirmed
 *   field names for A/AAAA ("ip"), CNAME ("destinationHostname"), TXT
 *   ("text") zone records.
 *
 * Known gaps (flagged rather than guessed, verify against a real account):
 * - MX/NS field names for Domain_Zone_GetAll are NOT confirmed by the
 *   reference client above (it only writes A/AAAA/TXT/CNAME, so it never
 *   models MX/NS specifically) — see extractContent().
 * - Domain_GetExpirationDate/Domain_GetRegistrationDate are confirmed to
 *   take a single `domain` param and return a string, but the exact date
 *   format is not documented; parseDate() is defensive about this.
 * - There is no confirmed way to detect a registrar-hold/suspended status
 *   (no documented response shape for Domain_Status_Get was found), so
 *   fetchLifecycle() only distinguishes Ok/Expired via the expiration date.
 */
class DinahostingDriver implements RegistrarDriverInterface, DnsPipelineInterface, ConnectionTestableInterface
{
    private const BASE_URI = 'https://dinahosting.com/special/';

    private const REQUEST_TIMEOUT = 15;

    private const TEST_TIMEOUT = 9;

    // Dinahosting always returns HTTP 200 for business-logic outcomes; the
    // real signal is this envelope's responseCode/message. Verified against
    // the official command index + github.com/libdns/dinahosting.
    private const CODE_SUCCESS           = 1000;
    private const CODE_AUTH_ERROR_USER   = 2200;
    private const CODE_AUTH_ERROR_OBJECT = 2201;
    private const CODE_OBJECT_NOT_EXISTS = 2303;
    private const CODE_COMMAND_TIMEOUT   = 2501;

    private ?Client $client = null;

    /**
     * @param array<string, string> $credentials
     */
    public function __construct(private array $credentials)
    {
    }

    /**
     * Dinahosting auth is a single account-wide username/password, not
     * capability-specific, so one lightweight probe (System_GetRequestTypes
     * — listed as domain-independent in the official command index, no
     * `domain` param in its documented example request) speaks for both
     * 'registrar' and 'dns'.
     *
     * {@inheritDoc}
     */
    public function testConnection(array $credentials): array
    {
        $previous          = $this->credentials;
        $this->credentials = $credentials;
        $this->client       = null;

        try {
            $results = $this->probeAuth();
        } catch (Throwable $e) {
            $result  = ConnectionTestResult::fromException('registrar', $e);
            $results = [
                'registrar' => $result,
                'dns'       => new ConnectionTestResult(
                    $result->status,
                    'dns',
                    $result->httpStatusCode,
                    $result->userMessage,
                    $result->rawDetail,
                    $result->checkedAt
                ),
            ];
        } finally {
            $this->credentials = $previous;
            $this->client       = null;
        }

        return $results;
    }

    /**
     * Direct HTTP probe used only by testConnection(): classifies the
     * envelope's responseCode precisely instead of collapsing into the
     * generic DriverException messages used by the sync pipelines.
     *
     * @return array{registrar: ConnectionTestResult, dns: ConnectionTestResult}
     */
    private function probeAuth(): array
    {
        $client = $this->getClient();

        try {
            $response = $client->request('GET', 'api.php', [
                'query'   => $this->query('System_GetRequestTypes'),
                'timeout' => self::TEST_TIMEOUT,
            ]);
        } catch (GuzzleException $e) {
            PluginLogger::error('Dinahosting connection test HTTP failure: ' . $e->getMessage());
            throw $e;
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();

        if ($status >= 500) {
            return $this->bothCapabilities(
                ConnectionTestStatus::UpstreamError,
                $status,
                sprintf(__("The provider's API is currently unavailable (HTTP %d).", 'domainmanager'), $status),
                'Dinahosting System_GetRequestTypes (HTTP ' . $status . '): ' . self::sanitizeMessage($body)
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            PluginLogger::error("Dinahosting non-JSON response on System_GetRequestTypes (HTTP $status)");

            return $this->bothCapabilities(
                ConnectionTestStatus::UnknownError,
                $status,
                __('Unexpected response from the provider API.', 'domainmanager'),
                'Non-JSON body: ' . self::sanitizeMessage($body)
            );
        }

        return $this->classifyEnvelope($decoded, 'System_GetRequestTypes');
    }

    /**
     * @param  array  $decoded envelope: {responseCode, message, errors, ...}
     * @param  string $command for the log-only raw detail
     * @return array{registrar: ConnectionTestResult, dns: ConnectionTestResult}
     */
    private function classifyEnvelope(array $decoded, string $command): array
    {
        if (self::envelopeSucceeded($decoded)) {
            return $this->bothCapabilities(
                ConnectionTestStatus::Success,
                null,
                __('Connection successful.', 'domainmanager'),
                ''
            );
        }

        $responseCode = (int) ($decoded['responseCode'] ?? 0);
        $message      = (string) ($decoded['message'] ?? '');
        $errorDetail  = self::summarizeErrors($decoded);

        [$status, $userMessage] = match ($responseCode) {
            self::CODE_AUTH_ERROR_USER => [
                ConnectionTestStatus::AuthFailed,
                __('Authentication failed — the username or password was rejected.', 'domainmanager'),
            ],
            self::CODE_AUTH_ERROR_OBJECT => [
                ConnectionTestStatus::Forbidden,
                __('Authentication succeeded but the credentials lack the required permission/scope.', 'domainmanager'),
            ],
            self::CODE_COMMAND_TIMEOUT => [
                ConnectionTestStatus::Timeout,
                __('The connection to the provider API timed out.', 'domainmanager'),
            ],
            default => [
                ConnectionTestStatus::UnknownError,
                sprintf(__('Unexpected response from the provider API (code %d).', 'domainmanager'), $responseCode),
            ],
        };

        $rawDetail = sprintf(
            'Dinahosting %s responseCode=%d message="%s"%s',
            $command,
            $responseCode,
            $message,
            $errorDetail !== '' ? " errors=[$errorDetail]" : ''
        );

        return $this->bothCapabilities($status, null, $userMessage, $rawDetail);
    }

    /**
     * @param  ConnectionTestStatus $status
     * @param  int|null             $httpStatusCode
     * @param  string               $userMessage
     * @param  string               $rawDetail
     * @return array{registrar: ConnectionTestResult, dns: ConnectionTestResult}
     */
    private function bothCapabilities(
        ConnectionTestStatus $status,
        ?int $httpStatusCode,
        string $userMessage,
        string $rawDetail
    ): array {
        $now = new DateTimeImmutable();

        return [
            'registrar' => new ConnectionTestResult($status, 'registrar', $httpStatusCode, $userMessage, $rawDetail, $now),
            'dns'       => new ConnectionTestResult($status, 'dns', $httpStatusCode, $userMessage, $rawDetail, $now),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function fetchLifecycle(string $domain): DomainLifecycle
    {
        $domain = self::normalizeDomain($domain);

        $expirationData   = $this->request('Domain_GetExpirationDate', ['domain' => $domain]);
        $registrationData = $this->request('Domain_GetRegistrationDate', ['domain' => $domain]);

        $expiration   = self::parseDate($expirationData['data'] ?? null);
        $registration = self::parseDate($registrationData['data'] ?? null);

        // No confirmed response shape for a registrar-hold/suspended status
        // exists in the available documentation (see class docblock) — only
        // Ok/Expired can be determined here.
        $status = ($expiration !== null && $expiration < new DateTimeImmutable('now'))
            ? LifecycleStatus::Expired
            : LifecycleStatus::Ok;

        return new DomainLifecycle($registration, $expiration, $status);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchZoneRecords(string $domain): array
    {
        $domain = self::normalizeDomain($domain);
        $data   = $this->request('Domain_Zone_GetAll', ['domain' => $domain]);

        $records = [];
        foreach ($data['data'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $type = strtoupper((string) ($row['type'] ?? ''));
            if (!in_array($type, ZoneRecord::TYPES, true)) {
                continue; // read-only scope: unknown/unsupported types skipped
            }

            $name    = (string) ($row['hostname'] ?? $row['host'] ?? '');
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
                PluginLogger::activity("Dinahosting record skipped for $domain: " . $e->getMessage());
            }
        }

        return $records;
    }

    /**
     * Field names verified against github.com/libdns/dinahosting for
     * A/AAAA/CNAME/TXT. That client never writes MX/NS records, so it
     * doesn't model their fields either — MX/NS fall back to the same
     * generic fields the reference client uses for types it doesn't know
     * about; MX priority ordering in particular is unconfirmed and should
     * be checked against a real account before being relied on.
     *
     * @param  string $type
     * @param  array  $row
     * @return string
     */
    private static function extractContent(string $type, array $row): string
    {
        return match ($type) {
            'A', 'AAAA'   => (string) ($row['ip'] ?? ''),
            'CNAME', 'NS' => (string) ($row['destinationHostname'] ?? ''),
            'TXT'         => (string) ($row['text'] ?? ''),
            'MX'          => trim(
                ((string) ($row['priority'] ?? '')) . ' ' . (string) ($row['destinationHostname'] ?? $row['address'] ?? '')
            ),
            default => (string) (
                $row['destinationHostname']
                ?? $row['destinationUrl']
                ?? $row['ip']
                ?? $row['address']
                ?? $row['text']
                ?? ''
            ),
        };
    }

    /**
     * Perform an API call; technical detail goes to the plugin log file,
     * thrown messages are safe to persist
     *
     * @param  string $command
     * @param  array  $extraParams
     * @return array  decoded envelope
     * @throws DriverException
     */
    private function request(string $command, array $extraParams = []): array
    {
        try {
            $response = $this->getClient()->request('GET', 'api.php', [
                'query' => $this->query($command, $extraParams),
            ]);
        } catch (GuzzleException $e) {
            PluginLogger::error("Dinahosting HTTP failure on $command", $e->getMessage());
            throw new DriverException(__('Dinahosting API is unreachable', 'domainmanager'));
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();

        if ($status >= 500) {
            throw new DriverException(
                sprintf(__('Dinahosting API unavailable (HTTP %d)', 'domainmanager'), $status)
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            PluginLogger::error("Dinahosting non-JSON response on $command (HTTP $status)");
            throw new DriverException(__('Unexpected response from the Dinahosting API', 'domainmanager'));
        }

        if (!self::envelopeSucceeded($decoded)) {
            $responseCode = (int) ($decoded['responseCode'] ?? 0);

            if ($responseCode === self::CODE_AUTH_ERROR_USER || $responseCode === self::CODE_AUTH_ERROR_OBJECT) {
                throw new DriverException(__('Dinahosting authentication failed, check the username/password', 'domainmanager'));
            }

            if ($responseCode === self::CODE_OBJECT_NOT_EXISTS) {
                throw new DriverException(__('Domain is not managed by this Dinahosting account', 'domainmanager'));
            }

            $summary = self::sanitizeMessage(self::summarizeErrors($decoded) ?: (string) ($decoded['message'] ?? ''));
            PluginLogger::error("Dinahosting API error on $command (code $responseCode): $summary");
            throw new DriverException(
                sprintf(
                    __('Dinahosting API error: %s', 'domainmanager'),
                    $summary !== '' ? $summary : sprintf('code %d', $responseCode)
                )
            );
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

        $user     = trim((string) ($this->credentials['user'] ?? ''));
        $password = trim((string) ($this->credentials['password'] ?? ''));
        if ($user === '' || $password === '') {
            throw new DriverException(__('Dinahosting username/password are not configured', 'domainmanager'));
        }

        $this->client = Toolbox::getGuzzleClient([
            'base_uri'    => self::BASE_URI,
            'timeout'     => self::REQUEST_TIMEOUT,
            'http_errors' => false,
            // HTTP Basic Auth (documented as an alternative to the AUTH_USER/
            // AUTH_PWD query parameters shown in Dinahosting's own examples)
            // is used deliberately so credentials never appear in a request
            // URI that could end up in a proxy/CDN access log.
            'auth'        => [$user, $password],
            'headers'     => ['Accept' => 'application/json'],
        ]);

        return $this->client;
    }

    /**
     * @param  string $command
     * @param  array  $extraParams
     * @return array
     */
    private function query(string $command, array $extraParams = []): array
    {
        return array_merge(['command' => $command, 'responseType' => 'json'], $extraParams);
    }

    /**
     * @param  array $decoded
     * @return bool
     */
    private static function envelopeSucceeded(array $decoded): bool
    {
        $message = (string) ($decoded['message'] ?? '');

        return $message === 'Success.' || (int) ($decoded['responseCode'] ?? 0) === self::CODE_SUCCESS;
    }

    /**
     * @param  array $decoded
     * @return string
     */
    private static function summarizeErrors(array $decoded): string
    {
        $parts = [];
        foreach ($decoded['errors'] ?? [] as $error) {
            if (is_array($error)) {
                $parts[] = sprintf('code=%s msg=%s', $error['code'] ?? '?', $error['message'] ?? '?');
            }
        }

        return implode('; ', $parts);
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
     * @param  string $message
     * @return string
     */
    private static function sanitizeMessage(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', $message) ?? '';

        return mb_substr(trim($message), 0, 250);
    }
}
