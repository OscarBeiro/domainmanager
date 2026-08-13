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

namespace GlpiPlugin\Domainmanager\Dto;

use DateTimeImmutable;
use Throwable;

/**
 * Immutable outcome of testing one capability ('registrar' or 'dns') of a
 * driver's credentials (§3.5). `userMessage` is safe to persist/display;
 * `rawDetail` is log-only and must never reach the browser (see toArray()).
 */
final class ConnectionTestResult
{
    public function __construct(
        public readonly ConnectionTestStatus $status,
        public readonly string $capability,
        public readonly ?int $httpStatusCode,
        public readonly string $userMessage,
        public readonly string $rawDetail,
        public readonly DateTimeImmutable $checkedAt,
    ) {
    }

    /**
     * Classify an HTTP response into a status + safe user message
     *
     * @param  string $capability     'registrar' | 'dns'
     * @param  int    $httpStatusCode
     * @param  bool   $success        whether the provider considered the call successful
     * @param  string $rawDetail      log-only technical detail
     * @return self
     */
    public static function fromHttpResponse(
        string $capability,
        int $httpStatusCode,
        bool $success,
        string $rawDetail = ''
    ): self {
        if ($success && $httpStatusCode >= 200 && $httpStatusCode < 300) {
            return new self(
                ConnectionTestStatus::Success,
                $capability,
                $httpStatusCode,
                __('Connection successful.', 'domainmanager'),
                $rawDetail,
                new DateTimeImmutable(),
            );
        }

        [$status, $message] = match (true) {
            $httpStatusCode === 401 => [
                ConnectionTestStatus::AuthFailed,
                __('Authentication failed — the API token or credentials were rejected.', 'domainmanager'),
            ],
            $httpStatusCode === 403 => [
                ConnectionTestStatus::Forbidden,
                __('Authentication succeeded but the credentials lack the required permission/scope.', 'domainmanager'),
            ],
            $httpStatusCode === 404 => [
                ConnectionTestStatus::NotFound,
                __('The API endpoint was not found — check the account/zone identifier or base URL.', 'domainmanager'),
            ],
            $httpStatusCode === 429 => [
                ConnectionTestStatus::RateLimited,
                __('The provider is rate-limiting requests — try again shortly.', 'domainmanager'),
            ],
            $httpStatusCode >= 500 => [
                ConnectionTestStatus::UpstreamError,
                sprintf(__("The provider's API is currently unavailable (HTTP %d).", 'domainmanager'), $httpStatusCode),
            ],
            default => [
                ConnectionTestStatus::UnknownError,
                sprintf(__('Unexpected response from the provider API (HTTP %d).', 'domainmanager'), $httpStatusCode),
            ],
        };

        return new self($status, $capability, $httpStatusCode, $message, $rawDetail, new DateTimeImmutable());
    }

    /**
     * Classify a thrown exception into a status + safe user message
     *
     * @param  string    $capability 'registrar' | 'dns'
     * @param  Throwable $e
     * @return self
     */
    public static function fromException(string $capability, Throwable $e): self
    {
        $detail = $e::class . ': ' . $e->getMessage();

        if (
            $e instanceof \GuzzleHttp\Exception\ConnectException
            || stripos($e->getMessage(), 'could not resolve host') !== false
            || stripos($e->getMessage(), 'connection refused') !== false
            || stripos($e->getMessage(), 'ssl') !== false
            || stripos($e->getMessage(), 'cURL error 6') !== false
            || stripos($e->getMessage(), 'cURL error 7') !== false
        ) {
            return new self(
                ConnectionTestStatus::NetworkError,
                $capability,
                null,
                __('Could not reach the provider API — check network/firewall egress and the API base URL.', 'domainmanager'),
                $detail,
                new DateTimeImmutable(),
            );
        }

        if (
            stripos($e->getMessage(), 'timed out') !== false
            || stripos($e->getMessage(), 'cURL error 28') !== false
        ) {
            return new self(
                ConnectionTestStatus::Timeout,
                $capability,
                null,
                __('The connection to the provider API timed out.', 'domainmanager'),
                $detail,
                new DateTimeImmutable(),
            );
        }

        return new self(
            ConnectionTestStatus::UnknownError,
            $capability,
            null,
            __('Unexpected error while testing the connection — see the plugin error log.', 'domainmanager'),
            $detail,
            new DateTimeImmutable(),
        );
    }

    /**
     * Turn a provider's own error code/message into one shared, generic
     * sentence, instead of every driver writing its own guessed-cause
     * string (found live: Cloudflare's "lacks DNS:Read permission" fallback
     * fired for a 403 whose real cause wasn't scope at all). Drivers own
     * only the extraction of `$code`/`$message` from their provider's own
     * error envelope shape — this is the one place the resulting sentence
     * is built, so a new supplier never needs a new bespoke sentence, only
     * a small extractor feeding into this.
     *
     * @param  string      $supplierName literal driver name, not translated
     *                                    (matches the existing "Cloudflare
     *                                    %1$s failed" convention)
     * @param  string|null $code         the provider's own error code, if any
     * @param  string|null $message      the provider's own error message, if any
     * @param  int         $httpStatus   used as the code fallback when the
     *                                    provider gave neither
     * @return string
     */
    public static function formatApiError(
        string $supplierName,
        ?string $code,
        ?string $message,
        int $httpStatus
    ): string {
        $code    = $code !== null && $code !== '' ? $code : sprintf('HTTP %d', $httpStatus);
        $message = $message !== null && $message !== '' ? $message : sprintf('HTTP %d', $httpStatus);

        return sprintf(__('%1$s error %2$s: %3$s', 'domainmanager'), $supplierName, $code, $message);
    }

    /**
     * Build a "driver not implemented yet" result (IONOS stub)
     *
     * @param  string $capability 'registrar' | 'dns'
     * @param  string $message
     * @return self
     */
    public static function notImplemented(string $capability, string $message): self
    {
        return new self(
            ConnectionTestStatus::UnknownError,
            $capability,
            null,
            $message,
            $message,
            new DateTimeImmutable(),
        );
    }

    /**
     * Build a "required configuration is missing" result — no call was
     * attempted, so this must render distinctly from a real auth/API
     * failure (§addendum "Switch Cloudflare Driver to Account-Scoped API
     * Tokens": a missing Account ID is not the same thing as an invalid
     * token).
     *
     * @param  string $capability 'registrar' | 'dns'
     * @param  string $message
     * @return self
     */
    public static function notConfigured(string $capability, string $message): self
    {
        return new self(
            ConnectionTestStatus::NotConfigured,
            $capability,
            null,
            $message,
            $message,
            new DateTimeImmutable(),
        );
    }

    /**
     * Browser-safe representation — never includes rawDetail
     *
     * @return array{status: string, capability: string, http_status_code: ?int,
     *               user_message: string, checked_at: string}
     */
    public function toArray(): array
    {
        return [
            'status'           => $this->status->value,
            'capability'       => $this->capability,
            'http_status_code' => $this->httpStatusCode,
            'user_message'     => $this->userMessage,
            'checked_at'       => $this->checkedAt->format('Y-m-d H:i:s'),
        ];
    }
}
