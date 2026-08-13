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
use GlpiPlugin\Domainmanager\Driver\Concern\ValidatesCredentialsTrait;
use GlpiPlugin\Domainmanager\Exception\DriverException;
use GlpiPlugin\Domainmanager\IdnNormalizer;
use GuzzleHttp\Client;
use Throwable;
use Toolbox;

/**
 * Shared base for registrar/DNS drivers (ARCHITECTURE.md §18).
 *
 * Extracted once a fourth driver became imminent and the duplication cost
 * flagged in §18 compounded again: `CloudflareDriver`, `DinahostingDriver`,
 * and `IonosDriver` each independently re-implemented byte-identical
 * `normalizeDomain()`/`sanitizeMessage()`/`parseDate()` helpers and their own
 * lazy Guzzle client bootstrap (same `Toolbox::getGuzzleClient()` options,
 * only the auth header/option shape and required credential fields differ).
 *
 * Deliberately NOT unified here: each driver's `request()`/response error
 * classification (401 vs 403 handling, envelope shape, per-status message
 * wording). Those genuinely differ per API today and unifying them
 * speculatively — before a real fourth driver's shape is known — risks
 * silently changing behavior for three drivers whose exact wording is
 * already relied on. Revisit once a concrete new driver shows what, if
 * anything, is actually shared there.
 */
abstract class AbstractDriver
{
    use ValidatesCredentialsTrait;

    protected ?Client $client = null;

    /**
     * @param array<string, string> $credentials
     */
    public function __construct(protected array $credentials)
    {
    }

    /**
     * @return array<string, string> credential key => human label, used both
     *                                for the pre-flight check below and by
     *                                testConnection() implementations
     */
    abstract protected static function requiredCredentialFields(): array;

    /**
     * @param  array<string, string> $credentials already-validated (all of
     *                                             requiredCredentialFields()
     *                                             present and non-empty)
     * @return array{base_uri: string, timeout?: int, headers?: array, auth?: array}
     *               extra Guzzle client options merged on top of the shared
     *               base_uri/timeout/http_errors defaults
     */
    abstract protected function buildClientOptions(array $credentials): array;

    /**
     * Lazy Guzzle client, shared bootstrap for every driver: runs the same
     * credential pre-flight `testConnection()` already used
     * (`ValidatesCredentialsTrait::missingConfigMessage()`) before building
     * the client, instead of each driver's `getClient()` re-checking fields
     * with its own hardcoded message and bypassing the trait.
     *
     * @return Client
     * @throws DriverException when a required credential field is missing
     */
    protected function getClient(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $this->assertCredentialsPresent();

        $options = $this->buildClientOptions($this->credentials);
        $options += ['timeout' => 15, 'http_errors' => false];

        $this->client = Toolbox::getGuzzleClient($options);

        return $this->client;
    }

    /**
     * Exposed separately from getClient() for drivers needing more than one
     * lazy client against the same credentials (e.g. IonosDriver's DNS +
     * Domains API clients) — those build their extra client(s) directly but
     * still share this one pre-flight check.
     *
     * @throws DriverException when a required credential field is missing
     */
    protected function assertCredentialsPresent(): void
    {
        $missing = self::missingConfigMessage($this->credentials, static::requiredCredentialFields());
        if ($missing !== null) {
            throw new DriverException($missing);
        }
    }

    /**
     * Converts a possibly-Unicode/IDN domain name (GLPI's stored `name`) to
     * Punycode/ACE before it ever reaches a registrar/DNS API (§9 Phase 10).
     *
     * @param  string $domain
     * @return string
     * @throws DriverException
     */
    protected static function normalizeDomain(string $domain): string
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
    protected static function sanitizeMessage(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', $message) ?? '';

        return mb_substr(trim($message), 0, 250);
    }

    /**
     * @param  mixed $value
     * @return DateTimeImmutable|null
     */
    protected static function parseDate(mixed $value): ?DateTimeImmutable
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
