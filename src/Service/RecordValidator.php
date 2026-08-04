<?php

/*
 * -------------------------------------------------------------------------
 * Domain Manager plugin for GLPI
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Domainmanager\Service;

/**
 * Phases 59-61 (ARCHITECTURE.md §15.4): per-type record validation and
 * canonicalization.
 *
 * Deliberately not yet called from `DnsRecordWriteback` — wiring every
 * per-type validator into `onPreAdd()`/`onPreUpdate()` (and echoing them
 * client-side) is Phase 62's job, so several validators can land as small,
 * independently reviewable phases first. The one exception is the CNAME
 * relational rule (§ `validateCnameTarget()`'s docblock) which needs a DB
 * lookup `RecordValidator` deliberately has no access to, and stays a
 * separate call in `DnsRecordWriteback` for that reason.
 */
class RecordValidator
{
    /**
     * Result shape shared by every per-type validator added across Phases
     * 59-61: `error` (non-null blocks the write), `warning` (non-null is
     * surfaced but never blocks), and `value` (the canonicalized data to
     * store/push — unchanged from the input when `error` is set).
     *
     * @param  string      $value
     * @param  string|null $error
     * @param  string|null $warning
     * @return array{value: string, error: ?string, warning: ?string}
     */
    private static function result(string $value, ?string $error, ?string $warning): array
    {
        return ['value' => $value, 'error' => $error, 'warning' => $warning];
    }

    /**
     * Validates and canonicalizes an A/AAAA record's `data` (the address
     * itself — no other fields are involved for these two types).
     *
     * `FILTER_VALIDATE_IP` with the matching `FILTER_FLAG_IPV4`/`FILTER_FLAG_IPV6`
     * flag covers standard, compressed and loopback forms with no library
     * needed. Canonicalization goes one step further via `inet_pton()` /
     * `inet_ntop()`: `filter_var()` alone accepts `2001:0db8::1` as-is, and
     * without a canonical form that and `2001:db8::1` read as a diff on
     * every sync, so the reconciler would churn indefinitely on a record
     * nobody actually changed.
     *
     * Private/reserved-range addresses (RFC1918, loopback, link-local,
     * IPv4-mapped IPv6 wrapping any of those) are warned on, never blocked —
     * legitimate in split-horizon/internal-zone setups.
     *
     * @param  string $type 'A' or 'AAAA'
     * @param  string $value raw address as typed/imported
     * @return array{value: string, error: ?string, warning: ?string}
     */
    public static function validateAddress(string $type, string $value): array
    {
        $value = trim($value);
        $flag  = $type === 'AAAA' ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;

        if (filter_var($value, FILTER_VALIDATE_IP, $flag) === false) {
            return self::result($value, sprintf(
                __('"%s" is not a valid %s address', 'domainmanager'),
                $value,
                $type,
            ), null);
        }

        $packed = @inet_pton($value);
        $canonical = $packed !== false ? inet_ntop($packed) : $value;

        $warning = self::privateRangeWarning($type, $canonical);

        return self::result($canonical, null, $warning);
    }

    /**
     * @param  string $type 'A' or 'AAAA'
     * @param  string $canonical already-canonicalized address
     * @return string|null
     */
    private static function privateRangeWarning(string $type, string $canonical): ?string
    {
        $checkValue = $canonical;
        $checkFlag  = $type === 'AAAA' ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;

        // IPv4-mapped IPv6 (::ffff:x.x.x.x): the embedded v4 address is what
        // actually carries a private/reserved range, not the wrapper itself.
        if ($type === 'AAAA' && stripos($canonical, '::ffff:') === 0) {
            $mapped = substr($canonical, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $checkValue = $mapped;
                $checkFlag  = FILTER_FLAG_IPV4;
            }
        }

        $isPublic = filter_var(
            $checkValue,
            FILTER_VALIDATE_IP,
            $checkFlag | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;

        if ($isPublic) {
            return null;
        }

        return sprintf(
            __('%s is a private/reserved address; make sure this is intentional (e.g. split-horizon DNS)', 'domainmanager'),
            $canonical,
        );
    }

    /**
     * Validates a CNAME record's `data` (the target) against everything that
     * doesn't require a DB lookup: valid FQDN shape, label/name length
     * limits, and self-reference. `$name` and `$target` are both expected
     * already-absolute (§15 Phase 58 — `DnsRecordWriteback` guarantees this
     * before either validator or driver ever sees a name).
     *
     * The relational rule this phase is actually named for — a CNAME may
     * not coexist with any other record type at the same owner name (RFC
     * 1034) — is **not** here: `duplicateNameError()` in `DnsRecordWriteback`
     * already keys on `domains_id`+`domainrecordtypes_id`+`name`, so CNAME
     * plus an A record at one name sails straight past it today and produces
     * a broken zone. Enforcing the real rule needs a DB query across *all*
     * types at that name, which this class has no DB access for by design
     * (every other validator here is a pure function) — see
     * `DnsRecordWriteback::cnameCoexistenceError()`.
     *
     * Apex CNAME (`$name === $zoneName`) is refused unless the caller says
     * the configured driver declares support for it — Phase 66
     * (ARCHITECTURE.md §15.5) resolved this to a driver capability flag,
     * `DriverRegistry::supportsApexCname()`, rather than a new record type:
     * Cloudflare flattens a CNAME at the zone apex (its own documented
     * "CNAME flattening"), IONOS's apex-alias behaviour was already
     * evaluated and closed negative on evidence (§11.4), and Dinahosting
     * has no documented apex-alias support either.
     *
     * @param  string $name absolute FQDN this record would be stored at
     * @param  string $target raw CNAME target as typed/imported
     * @param  string $zoneName the domain's own absolute zone name
     * @param  bool   $apexAllowed whether the configured driver declares
     *                            apex-CNAME support (default false — safe
     *                            for a caller that hasn't resolved a driver)
     * @return array{value: string, error: ?string, warning: ?string}
     */
    public static function validateCnameTarget(string $name, string $target, string $zoneName, bool $apexAllowed = false): array
    {
        $target = trim($target);
        $bareTarget = rtrim($target, '.');

        if (!$apexAllowed && strcasecmp($name, $zoneName) === 0) {
            return self::result($target, __(
                'A CNAME record is not allowed at the zone apex; the configured provider does not support it',
                'domainmanager',
            ), null);
        }

        if (!self::isValidFqdn($bareTarget)) {
            return self::result($target, sprintf(
                __('"%s" is not a valid hostname for a CNAME target', 'domainmanager'),
                $target,
            ), null);
        }

        if (strcasecmp($bareTarget, rtrim($name, '.')) === 0) {
            return self::result($target, sprintf(
                __('A CNAME record cannot point to itself ("%s")', 'domainmanager'),
                $name,
            ), null);
        }

        return self::result($target, null, null);
    }

    /**
     * RFC 1035 label (1-63 octets, alphanumeric/hyphen, no leading/trailing
     * hyphen) and name (<=253 octets) shape, trailing dot already stripped
     * by the caller.
     *
     * @param  string $fqdn
     * @return bool
     */
    private static function isValidFqdn(string $fqdn): bool
    {
        if ($fqdn === '' || strlen($fqdn) > 253) {
            return false;
        }

        return preg_match(
            '/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))*$/',
            $fqdn,
        ) === 1;
    }

    /**
     * Validates a TXT record's `data`, on the two things unambiguous enough
     * to block, and warns on SPF/DMARC/DKIM semantics. `$name` is the
     * already-absolute owner name (§15 Phase 58) — needed only for the
     * DMARC `_dmarc` label check below, not for either block condition.
     *
     * **Block 1 — length.** A single character-string over 255 octets. Per
     * this phase's own verification note (ARCHITECTURE.md §15.4 Phase 61),
     * whether a provider pre-chunks a long TXT value server-side or requires
     * the caller to pre-split it into multiple character-strings is
     * unconfirmed for Cloudflare/Dinahosting (IONOS is confirmed to agree
     * with core); since this plugin has no multi-string chunking of its
     * own, a value over the limit would either be rejected upstream or
     * silently truncated, so it's blocked here rather than guessed at.
     *
     * **Block 2 — quoting conflict with core's own convention.** Per §11.5,
     * this plugin's internal `data` convention is always the bare, unquoted
     * content — `IonosDriver::toWireContent()`/`extractContent()` are the
     * only place quoting/unquoting happens, at the wire boundary. Core's
     * *own* generic per-type-field composer (§15.4's "core's `quote_value`
     * convention", ARCHITECTURE.md §11.5) wraps a TXT value in double quotes
     * when built through that path, so a value already wrapped in a
     * matching, balanced outer quote pair most likely arrived via that other
     * convention rather than as literal content the user meant to store —
     * pushing it through would double-quote on the wire. Blocked rather than
     * silently unwrapped, since a genuinely quote-wrapped literal (rare, but
     * legal) would be silently mangled by guessing wrong.
     *
     * **Duplicate `v=spf1` at one name** (RFC 7208) needs a DB query across
     * every TXT record at `$name`, which this class deliberately has no DB
     * access for — see `DnsRecordWriteback::spfDuplicateError()`.
     *
     * @param  string $name  already-absolute owner name
     * @param  string $value raw TXT content as typed/imported (unquoted,
     *                       per this plugin's own convention)
     * @return array{value: string, error: ?string, warning: ?string}
     */
    public static function validateTxtContent(string $name, string $value): array
    {
        if (strlen($value) > 255) {
            return self::result($value, sprintf(
                __('TXT content is %d octets, over the 255-octet character-string limit', 'domainmanager'),
                strlen($value),
            ), null);
        }

        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            return self::result($value, __(
                'This TXT value is already wrapped in double quotes; enter the content itself — quoting for the provider is applied automatically',
                'domainmanager',
            ), null);
        }

        return self::result($value, null, self::txtSemanticsWarning($name, $value));
    }

    /**
     * @param  string $name  already-absolute owner name
     * @param  string $value raw, unquoted TXT content
     * @return string|null
     */
    private static function txtSemanticsWarning(string $name, string $value): ?string
    {
        if (stripos($value, 'v=spf1') === 0) {
            $lookups = preg_match_all(
                '/(?:^|\s)[+\-~?]?(?:include:|a(?::|\s|$)|mx(?::|\s|$)|ptr(?::|\s|$)|exists:|redirect=)/i',
                $value,
            );
            if ($lookups !== false && $lookups > 10) {
                return sprintf(
                    __('This SPF record needs %d DNS lookups; RFC 7208 caps evaluation at 10 and a resolver will treat the whole record as a permanent error past that', 'domainmanager'),
                    $lookups,
                );
            }
            return null;
        }

        if (stripos($value, 'v=DMARC1') === 0) {
            $firstLabel = strtolower(explode('.', rtrim($name, '.'))[0]);
            if ($firstLabel !== '_dmarc') {
                return __('This looks like a DMARC record but its name is not "_dmarc"; DMARC is only honoured there', 'domainmanager');
            }
            if (!preg_match('/(?:^|;)\s*p=/i', $value)) {
                return __('This DMARC record has no "p=" tag; without one no policy is applied', 'domainmanager');
            }
            return null;
        }

        if (stripos($name, '._domainkey.') !== false && stripos($value, 'v=DKIM1') !== false
            && !preg_match('/(?:^|;)\s*p=/i', $value)) {
            return __('This DKIM record has no "p=" tag; without one the key cannot be validated', 'domainmanager');
        }

        return null;
    }
}
