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
 *
 * §9 Phase 8 addendum (2026-07-27) — bulk-import discovery: `Services_GetDomains`
 * (Services category, no parameters) is a real, confirmed command — the docs
 * site's own "Simulator" (which actually round-trips a live API call rather
 * than faking a response, confirmed by it returning a real responseCode=2200
 * auth error for fake credentials) only exposes the auth-error envelope, not
 * a success example. The real success shape was confirmed with a live,
 * read-only call against a real account (2026-07-27, Óscar's explicit
 * one-time permission — same precedent as the earlier live driver
 * verification work):
 * `{"responseCode":1000,"message":"Success.","data":[{"domain":"...",
 * "tld":"...","startDate":"YYYY-MM-DD","endDate":"YYYY-MM-DD"}, ...]}` — a
 * flat, unpaginated list (Óscar's account: 9 domains in one response, no
 * `count`/`limit`/`offset` fields present at all, unlike IONOS's discovery
 * endpoint). See listAccountDomains().
 */
class DinahostingDriver implements RegistrarDriverInterface, DnsPipelineInterface, ConnectionTestableInterface, DomainDiscoveryInterface, DnsRecordWriterInterface
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
                    $result->checkedAt,
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
                'Dinahosting System_GetRequestTypes (HTTP ' . $status . '): ' . self::sanitizeMessage($body),
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            PluginLogger::error("Dinahosting non-JSON response on System_GetRequestTypes (HTTP $status)");

            return $this->bothCapabilities(
                ConnectionTestStatus::UnknownError,
                $status,
                __('Unexpected response from the provider API.', 'domainmanager'),
                'Non-JSON body: ' . self::sanitizeMessage($body),
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
                '',
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
            $errorDetail !== '' ? " errors=[$errorDetail]" : '',
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
     *
     * §9 Phase 7: only `authInfo` is populated for this driver, via
     * `Domain_GetAuthcode` — confirmed to exist as a real command (live
     * probe 2026-07-21 returned the account-wide auth-error envelope, not
     * "Unknown command", the same signal used to confirm every other
     * command this driver already calls) and inferred to share the exact
     * `Domain_Get*` → single-string-in-`data` shape already trusted for
     * `Domain_GetExpirationDate`/`Domain_GetRegistrationDate` above. A
     * failure fetching it (including "not found") is swallowed, not
     * thrown — it's an enrichment, not core lifecycle data the rest of
     * the sync depends on.
     *
     * `privacyEnabled`/`domainLock`/`transferLock`/`autoRenew` each have a
     * real, live-confirmed-to-exist Dinahosting command
     * (`Domain_WhoisPrivacy_Get`, `Domain_Status_IsLocked`/`Domain_Status_Get`,
     * `Billing_Autorenew_GetAll`) but none publish an example response
     * body anywhere (same documentation gap already noted for
     * `Domain_GetExpirationDate`'s date format), and none share
     * `Domain_GetAuthcode`'s safe-to-infer shape — `Billing_Autorenew_GetAll`
     * in particular is named as a bulk *list* endpoint, not obviously
     * domain-scoped. Left null rather than guessed, pending live
     * verification against a real account. `domainType`/`dnsSecEnabled`
     * have no matching command anywhere in the documented command index
     * at all — confirmed absent, not just unconfirmed.
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

        return new DomainLifecycle($registration, $expiration, $status, self::fetchAuthInfo($domain));
    }

    /**
     * @param  string $domain already-normalized FQDN
     * @return string|null
     */
    private function fetchAuthInfo(string $domain): ?string
    {
        try {
            $data = $this->request('Domain_GetAuthcode', ['domain' => $domain]);
        } catch (Throwable $e) {
            PluginLogger::activity("Dinahosting auth code fetch skipped for $domain: " . $e->getMessage());
            return null;
        }

        $authInfo = trim((string) ($data['data'] ?? ''));

        return $authInfo !== '' ? $authInfo : null;
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

            $name    = self::qualifyHostname($domain, (string) ($row['hostname'] ?? $row['host'] ?? ''));
            $content = self::extractContent($type, $row);

            try {
                $records[] = new ZoneRecord(
                    $type,
                    $name,
                    $content,
                    (int) ($row['ttl'] ?? 0),
                    self::encodeRemoteId($type, $name),
                );
            } catch (InvalidArgumentException $e) {
                PluginLogger::activity("Dinahosting record skipped for $domain: " . $e->getMessage());
            }
        }

        return $records;
    }

    /**
     * {@inheritDoc}
     *
     * §11-dinahosting: Dinahosting's write API has no update command and no
     * per-record id — see class docblock addendum below assertSingleRecordAtName()
     * for the full rationale. Creation refuses to add a second record at a
     * `type`+`name` that already has one, since Dinahosting's delete
     * commands for A/AAAA/CNAME act on hostname alone (no value filter),
     * making a second record at the same name unsafe to ever clean up
     * individually afterwards.
     */
    public function createRecord(string $domain, string $type, string $name, string $data, int $ttl): ZoneRecord
    {
        $type   = self::assertWritableType($type);
        $domain = self::normalizeDomain($domain);

        $this->assertSingleRecordAtName($domain, $type, $name);

        return $this->createRecordRaw($domain, $type, $name, $data);
    }

    /**
     * {@inheritDoc}
     *
     * §11-dinahosting: synthesized as delete-then-add, the only shape
     * Dinahosting's API supports. Not atomic — if the add fails after the
     * delete succeeds, the record is left deleted with no automatic
     * rollback (Dinahosting has no transaction to roll back). Both the
     * source and (if different) destination `type`+`name` are checked for
     * siblings first, so this never fires the delete half of the update
     * against a name it can't safely also recreate.
     */
    public function updateRecord(string $domain, string $remoteId, string $type, string $name, string $data, int $ttl): ZoneRecord
    {
        $old    = self::decodeRemoteId($remoteId);
        $type   = self::assertWritableType($type);
        $domain = self::normalizeDomain($domain);

        $this->assertSingleRecordAtName($domain, $old['type'], $old['name'], $remoteId);
        if ($old['type'] !== $type || $old['name'] !== $name) {
            $this->assertSingleRecordAtName($domain, $type, $name);
        }

        $this->deleteByIdentity($domain, $old['type'], $old['name']);

        return $this->createRecordRaw($domain, $type, $name, $data);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteRecord(string $domain, string $remoteId): void
    {
        $id     = self::decodeRemoteId($remoteId);
        $domain = self::normalizeDomain($domain);

        $this->deleteByIdentity($domain, $id['type'], $id['name']);
    }

    /**
     * {@inheritDoc}
     *
     * §11-dinahosting: no single-record read command exists — this always
     * re-scans the whole zone via fetchZoneRecords() and filters, same as
     * assertSingleRecordAtName().
     */
    public function fetchRecord(string $domain, string $remoteId): ZoneRecord
    {
        $id     = self::decodeRemoteId($remoteId);
        $domain = self::normalizeDomain($domain);

        $found = $this->findByIdentity($domain, $id['type'], $id['name']);
        if ($found === null) {
            throw new DriverException(__('Dinahosting no longer reports this record', 'domainmanager'));
        }

        return $found;
    }

    /**
     * Shared create body for createRecord()/updateRecord(), used by the
     * latter without re-running the collision guard a second time (it
     * already ran it against both the source and destination identity).
     *
     * Command/param names verified against github.com/libdns/dinahosting;
     * `hostname` is passed as the record's absolute name exactly as read
     * back from Domain_Zone_GetAll (§ fetchZoneRecords()), matching the
     * relative-vs-absolute form Dinahosting itself reports — unconfirmed
     * against a real write call, verify against a live account before
     * relying on it (same caveat class as the MX/NS field gap).
     *
     * @param  string $domain already-normalized FQDN
     * @param  string $type   one of self::WRITABLE_TYPES, already validated
     * @param  string $name   absolute record name
     * @param  string $data   see DnsRecordWriterInterface docblock
     * @return ZoneRecord
     * @throws DriverException
     */
    private function createRecordRaw(string $domain, string $type, string $name, string $data): ZoneRecord
    {
        $command = match ($type) {
            'A'     => 'Domain_Zone_AddTypeA',
            'AAAA'  => 'Domain_Zone_AddTypeAAAA',
            'CNAME' => 'Domain_Zone_AddTypeCname',
            'TXT'   => 'Domain_Zone_AddTypeTXT',
            default => throw new DriverException(sprintf(__('Record type %s is not writable through Domain Manager', 'domainmanager'), $type)),
        };

        // No `default` throw here: the match above already narrowed $type to
        // exactly {A,AAAA,CNAME,TXT} (anything else threw), so TXT is the
        // only value left once the first three arms are excluded — PHPStan
        // flags an explicit 'TXT' arm plus a further default as dead code.
        $params = ['domain' => $domain, 'hostname' => $name] + match ($type) {
            'A', 'AAAA' => ['ip' => $data],
            'CNAME'     => ['destinationHostname' => $data],
            default     => ['text' => $data],
        };

        $this->request($command, $params);

        $found = $this->findByIdentity($domain, $type, $name);
        if ($found === null) {
            $seen = array_map(
                static fn(ZoneRecord $record): string => "{$record->type}:{$record->name}",
                $this->fetchZoneRecords($domain),
            );
            PluginLogger::error(
                "Dinahosting did not report the newly created record for $domain (looked for $type:$name)",
                'zone now reports: ' . (empty($seen) ? '(empty)' : implode(', ', $seen)),
            );

            throw new DriverException(__('Dinahosting did not report the newly created record', 'domainmanager'));
        }

        return $found;
    }

    /**
     * Deletes every record at `type`+`hostname`. Dinahosting's delete
     * commands for A/AAAA/CNAME require the record's value alongside the
     * hostname (confirmed against a live account: omitting it fails with
     * "Required param \"ip\" is missing"/2003-2005), so this necessarily
     * removes *all* records of that type at that name if more than one
     * shared the same value — but callers must have already confirmed
     * (via assertSingleRecordAtName()) that at most one such record exists
     * at the name in the first place.
     *
     * A "record not found" result is treated as success (§ Cloudflare's
     * identical 404-is-success stance in CloudflareDriver::deleteRecord()):
     * the desired end state — no such record — already holds. If the
     * record can no longer be found here, the value param is simply
     * omitted and the delete is attempted anyway so that outcome surfaces
     * through the normal API error path.
     *
     * @param  string $domain   already-normalized FQDN
     * @param  string $type     one of self::WRITABLE_TYPES
     * @param  string $hostname absolute record name
     * @throws DriverException
     */
    private function deleteByIdentity(string $domain, string $type, string $hostname): void
    {
        $command = match ($type) {
            'A'     => 'Domain_Zone_DeleteTypeA',
            'AAAA'  => 'Domain_Zone_DeleteTypeAAAA',
            'CNAME' => 'Domain_Zone_DeleteTypeCname',
            'TXT'   => 'Domain_Zone_DeleteTypeTXT',
            default => throw new DriverException(sprintf(__('Record type %s is not writable through Domain Manager', 'domainmanager'), $type)),
        };

        $params    = ['domain' => $domain, 'hostname' => $hostname];
        $existing  = $this->findByIdentity($domain, $type, $hostname);
        if ($existing !== null) {
            $params += match ($type) {
                'A', 'AAAA' => ['ip' => $existing->data],
                'CNAME'     => ['destinationHostname' => $existing->data],
                default     => ['value' => $existing->data],
            };
        }

        // Unlike Cloudflare's deleteRecord() (404-is-success), no documented
        // Dinahosting responseCode distinguishes "record not found" from any
        // other failure, so that case cannot be special-cased here yet — a
        // delete against an already-absent record surfaces as a generic API
        // error instead of a silent success. Revisit once such a response
        // has been observed against a live account.
        $this->request($command, $params);
    }

    /**
     * Guards every write path against Dinahosting's lack of a scoped
     * delete/update: if more than one record shares `type`+`name` (case-
     * insensitive), there is no way to touch exactly one of them, since
     * A/AAAA/CNAME deletes act on hostname alone and would remove every
     * sibling. Refusing the write here is a deliberate data-loss guard,
     * not a placeholder limitation to be lifted later — see the
     * DnsRecordWriterInterface implementation notes in ARCHITECTURE.md.
     *
     * @param  string      $domain            already-normalized FQDN
     * @param  string      $type
     * @param  string      $name
     * @param  string|null $excludeRemoteId   the record being updated/deleted
     *                                        itself, excluded from the count
     * @throws DriverException
     */
    private function assertSingleRecordAtName(string $domain, string $type, string $name, ?string $excludeRemoteId = null): void
    {
        $matches = array_filter(
            $this->fetchZoneRecords($domain),
            static fn(ZoneRecord $record): bool => $record->type === $type
                && strcasecmp($record->name, $name) === 0
                && $record->remoteId !== $excludeRemoteId,
        );

        if (count($matches) > 0) {
            throw new DriverException(
                sprintf(
                    __('Domain Manager cannot safely edit this record: Dinahosting already has one or more %s records at %s, and its API can only delete all of them at once, not a single one', 'domainmanager'),
                    $type,
                    $name,
                ),
            );
        }
    }

    /**
     * @param  string $domain already-normalized FQDN
     * @param  string $type
     * @param  string $name
     * @return ZoneRecord|null
     */
    private function findByIdentity(string $domain, string $type, string $name): ?ZoneRecord
    {
        foreach ($this->fetchZoneRecords($domain) as $record) {
            if ($record->type === $type && strcasecmp($record->name, $name) === 0) {
                return $record;
            }
        }

        return null;
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
     * Synthetic remoteId: Dinahosting's zone API returns no per-record id,
     * so identity is encoded as `type|name` instead. Not meant to be
     * opaque/secret — just a stable, round-trippable token that this
     * driver's own read/write paths agree on (§11-dinahosting).
     *
     * @param  string $type
     * @param  string $name
     * @return string
     */
    private static function encodeRemoteId(string $type, string $name): string
    {
        return base64_encode($type . '|' . $name);
    }

    /**
     * @param  string $remoteId
     * @return array{type: string, name: string}
     * @throws DriverException on malformed input
     */
    private static function decodeRemoteId(string $remoteId): array
    {
        $decoded = base64_decode($remoteId, true);
        if ($decoded === false || !str_contains($decoded, '|')) {
            throw new DriverException(__('This record reference is no longer valid', 'domainmanager'));
        }

        [$type, $name] = explode('|', $decoded, 2);

        return ['type' => strtoupper($type), 'name' => $name];
    }

    /**
     * Re-encodes a previously-stored `ImportedRecord.remote_id` through
     * `qualifyHostname()`, for `Installer::renormalizeDinahostingRemoteIds()`
     * (the 1.4.2 migration fixing the hostname-normalization bug documented
     * on `qualifyHostname()` itself). A remote id encoded before that fix
     * carries the old, un-normalized name and no longer matches what
     * `fetchZoneRecords()` reports, breaking delete/update/restore for any
     * record synced before the fix — this recomputes it in place, without
     * touching the DomainRecord/Domain rows themselves (so ticket/contract/
     * project links on those never move). Idempotent: re-running against an
     * already-normalized remote id is a no-op, since `qualifyHostname()`
     * itself is idempotent.
     *
     * @param  string $remoteId a previously-stored ImportedRecord.remote_id
     * @param  string $domain   that record's own domain's zone name
     * @return string|null the re-encoded remote id, or null if $remoteId
     *                     doesn't decode (leave such rows untouched)
     */
    public static function renormalizeRemoteId(string $remoteId, string $domain): ?string
    {
        try {
            $decoded = self::decodeRemoteId($remoteId);
        } catch (DriverException $e) {
            return null;
        }

        return self::encodeRemoteId($decoded['type'], self::qualifyHostname($domain, $decoded['name']));
    }

    /**
     * {@inheritDoc}
     *
     * §9 Phase 8 addendum: `Services_GetDomains` (no parameters) returns
     * every domain on the account in one flat, unpaginated response — no
     * pagination loop needed here, unlike IonosDriver::listAccountDomains().
     * `previewStatus` is populated with the real expiration date
     * (`endDate`), a genuinely useful preview this API happens to include
     * for free — unlike IONOS's list endpoint, which was left null pending
     * further verification (§9 Phase 8).
     */
    public function listAccountDomains(): array
    {
        $data = $this->request('Services_GetDomains');

        $domains = [];
        foreach ($data['data'] ?? [] as $row) {
            if (!is_array($row) || empty($row['domain'])) {
                continue;
            }

            $endDate       = trim((string) ($row['endDate'] ?? ''));
            $previewStatus = $endDate !== ''
                ? sprintf(__('Expires %s', 'domainmanager'), $endDate)
                : null;

            $domains[] = new DiscoveredDomain((string) $row['domain'], $previewStatus);
        }

        return $domains;
    }

    /**
     * Dinahosting's `Domain_Zone_GetAll` reports the apex record's hostname
     * inconsistently by record type — confirmed live: A/AAAA/CNAME apex
     * comes back as `@`, while TXT/MX apex comes back as the bare zone name
     * itself (e.g. `dev.gal`), and every non-apex record comes back as a
     * bare relative label (`manel`, not `manel.dev.gal`). Every other write
     * path in this driver and in DnsRecordWriteback works in absolute FQDNs
     * (matching Cloudflare/IONOS, and DnsRecordWriterInterface's contract),
     * so left un-normalized this mismatch broke `findByIdentity()` for
     * virtually every non-apex record: it silently never matched a record
     * that was just created, which surfaced as "Dinahosting did not report
     * the newly created record" even on a successful create, prompting a
     * retry that duplicated the record upstream (§ live bug, 2026-08-03:
     * duplicate "manel" A records after such a retry storm).
     *
     * @param  string $domain already-normalized FQDN
     * @param  string $hostname raw hostname as reported by Domain_Zone_GetAll
     * @return string absolute FQDN (the zone itself for an apex record)
     */
    private static function qualifyHostname(string $domain, string $hostname): string
    {
        $hostname = trim($hostname);
        if ($hostname === '' || $hostname === '@' || strcasecmp($hostname, $domain) === 0) {
            return $domain;
        }

        if (strcasecmp(substr($hostname, -\strlen($domain) - 1), '.' . $domain) === 0) {
            return $hostname; // already absolute
        }

        return $hostname . '.' . $domain;
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
                ((string) ($row['priority'] ?? '')) . ' ' . (string) ($row['destinationHostname'] ?? $row['address'] ?? ''),
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
                sprintf(__('Dinahosting API unavailable (HTTP %d)', 'domainmanager'), $status),
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            PluginLogger::error("Dinahosting non-JSON response on $command (HTTP $status)");
            throw new DriverException(__('Unexpected response from the Dinahosting API', 'domainmanager'));
        }

        if (!self::envelopeSucceeded($decoded)) {
            $responseCode = (int) ($decoded['responseCode'] ?? 0);

            if ($responseCode === self::CODE_AUTH_ERROR_USER) {
                throw new DriverException(__('Dinahosting authentication failed, check the username/password', 'domainmanager'));
            }

            // Distinct from CODE_AUTH_ERROR_USER (§addendum "Debug: Dinahosting
            // Registrar Auth Failure"): the account-wide credentials are
            // valid (proven by other domains/Check Connection succeeding
            // under the same supplier) but THIS domain isn't authorized for
            // them — e.g. registered under a different Dinahosting account.
            // Collapsing this into "authentication failed, check the
            // username/password" wrongly implied the credentials themselves
            // were wrong, when they demonstrably weren't.
            if ($responseCode === self::CODE_AUTH_ERROR_OBJECT) {
                throw new DriverException(
                    __('Dinahosting authentication succeeded, but this account is not authorized to manage this domain', 'domainmanager'),
                );
            }

            if ($responseCode === self::CODE_OBJECT_NOT_EXISTS) {
                throw new DriverException(__('Domain is not managed by this Dinahosting account', 'domainmanager'));
            }

            $summary = self::sanitizeMessage(self::summarizeErrors($decoded) ?: (string) ($decoded['message'] ?? ''));
            PluginLogger::error("Dinahosting API error on $command (code $responseCode): $summary");
            throw new DriverException(
                sprintf(
                    __('Dinahosting API error: %s', 'domainmanager'),
                    $summary !== '' ? $summary : sprintf('code %d', $responseCode),
                ),
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
     * Converts a possibly-Unicode/IDN domain name (GLPI's stored `name`) to
     * Punycode/ACE before it ever reaches the Dinahosting API (§9 Phase 10)
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
     * @param  string $message
     * @return string
     */
    private static function sanitizeMessage(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', $message) ?? '';

        return mb_substr(trim($message), 0, 250);
    }
}
