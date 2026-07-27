# Phase 14 Plan — RDAP as a Fallback Data Source

> Not started. The Phase 13 prerequisite work this note used to point at is now
> resolved: step 2 (extract a dedicated `DomainStatusResolver` class) shipped in
> 0.11.5; step 3 (unify the DNS column onto the sync-outcome badge scheme) was
> tried in 0.11.1 and deliberately reverted in 0.11.2 after live testing showed
> it regressed the UX for genuinely-managed domains — see `ARCHITECTURE.md` §9
> Phase 16. `PHASE13_PLAN.md` itself has been removed as fully addressed.

## What RDAP is

RDAP (RFC 7480–7484) is the IETF/ICANN-mandated HTTPS+JSON replacement for WHOIS —
structured data, no scraping. Public, free, no API key for direct registry queries;
ICANN has required gTLD registry support since 2024.

`rdap.org` is a third-party **bootstrap/redirector**, not an authoritative source —
it looks up the authoritative registry RDAP server per TLD (per the IANA bootstrap
registry) and redirects. Actual data always comes from the TLD's own registry server.

## Coverage (deciding factor — mixed for this driver set)

Per `deployment.rdap.org` (tracks IANA bootstrap registry):

| TLD | RDAP deployed? | Relevant to |
|---|---|---|
| `.com` / `.net` | Yes (since 2017-10-05) | IONOS-registered domains |
| `.gal` | Yes (since 2019-08-10) | Dinahosting-registered domains |
| `.es` | **No** | Dinahosting-registered domains (Red.es hasn't deployed RDAP) |

Real test domains span both: `.com`/`.gal` domains get RDAP data, any `.es` domain
gets nothing from RDAP and stays fully dependent on the driver. "No RDAP data for
this TLD" must be treated as a normal, expected, silent outcome — never an error,
never a `domainmanager-errors.log` entry.

## Rate limits

- `rdap.org` itself: Cloudflare-fronted, hard 10 req/10s per client, 429 beyond that.
- `rdap.org`'s own recommendation for volume clients: don't hit `rdap.org` per
  lookup — consume the IANA bootstrap file (RFC 9224) once, cache the TLD→authoritative
  server mapping, then query each authoritative server (e.g. Verisign for `.com`/`.net`)
  directly. This sidesteps the shared rate limit entirely.
- Authoritative registry servers have their own separate, generally more generous but
  undocumented/variable limits — build in backoff regardless, but don't over-engineer
  client-side rate-limit state (see cron design below — spacing is the real defense).

## Data mapping

- `events` array → `registration` maps to `date_creation`, `expiration` maps to
  `date_expiration`.
- `events` "last changed" → registry's own record-update timestamp. **Store as its
  own new field** — do not merge into GLPI Infocom's "last inventory date", which is
  a distinct, physical-asset-audit concept.
- `status` array (EPP codes: `clientTransferProhibited`, `serverTransferProhibited`,
  `pendingDelete`, `clientDeleteProhibited`, etc.) → maps to the Registrar details
  block's Transfer lock / Domain lock fields, for TLDs where the configured driver
  currently shows "Not reported by this driver".
- Registrant/contact data: expect heavy GDPR redaction — not a useful source beyond
  what's already surfaced.

## Architectural role — fallback only, never primary

- RDAP has no concept of *this account's* ownership — it's a public registry read,
  not "is this domain in my Dinahosting account." Cannot replace registrar-authenticated
  lookups (billing, renewal settings) and cannot do DNS zone sync at all.
- Slots into the existing `RegistrarDriverInterface` + "driver doesn't report this
  field, leave it blank" pattern: RDAP runs *after* the configured driver's sync,
  fills only fields the driver left empty, never overwrites a value the driver
  actually provided. Registrar API answer always wins over RDAP for the same field.

## Confirmed decisions

1. **Unsupported TLDs excluded outright, and documented** — `ARCHITECTURE.md` gets
   an explicit note: "RDAP enrichment does not apply to domains on TLDs absent from
   the IANA bootstrap registry (e.g. `.es` today)." No lookup attempted for these —
   excluded at query-selection stage, not attempted-and-failed.
2. **Gap-check before considering RDAP at all** — before enrichment is even
   considered for a domain, check whether the driver actually left any field
   unreported. A domain whose driver already reports everything relevant never
   generates an RDAP request. This shrinks workload over time as drivers mature.
3. **Provenance marker only where RDAP actually filled something** — "via RDAP" vs.
   "via [Driver]" appears only on fields RDAP populated. No blanket data-source UI
   on fields RDAP didn't touch or wasn't queried for.

## Cron design — dedicated, throttled, one-domain-per-tick

New `PluginDomainmanagerCronRdapEnrichment` task, registered as its own GLPI
Automatic Action (Setup → Automatic actions), independently configurable frequency
(default suggestion: every 5–10 minutes), separate from the existing daily sync task.

Each execution:

1. Query domains that are (a) on an RDAP-supported TLD (decision 1), (b) currently
   have ≥1 field gap not covered by the configured driver (decision 2), and (c) have
   not had an RDAP lookup attempted today — track via a new `last_rdap_check_date`
   column on the relevant plugin table.
2. Pick exactly **one**, ordered oldest-checked-first / never-checked-first (fair
   cycling, not always the same domain).
3. Look up via the cached bootstrap→authoritative-server mapping, hitting the
   authoritative registry server directly (never `rdap.org` per-lookup).
4. Update whichever gap fields RDAP returned, tag with "via RDAP" provenance,
   set `last_rdap_check_date` to today.
5. Exit — next domain waits for the next tick.
6. No qualifying domain (all checked today, or nothing has a gap) → clean no-op,
   not an error, no `domainmanager-errors.log` entry.

Rationale: real binding constraint is unknown per-registry limits on the
authoritative servers, not `rdap.org`'s 10/10s (bypassed via direct authoritative
queries). One-per-several-minutes with a once-daily-per-domain cap is a conservative
way to stay clear of any registry limit without knowing what it is — and daily
freshness is already more than sufficient for this kind of lifecycle metadata.

Large portfolios take multiple days to fully cycle at low tick frequency — acceptable
tradeoff, but surface it: a small status indicator (same config page as the Domain
Type setting) showing "N domains pending RDAP enrichment, last processed at [time]".

Logging: every processed domain (success or "no data for this TLD/field") →
`domainmanager.log`. Only genuine request failures (network error, unexpected
non-200 from the authoritative server) → `domainmanager-errors.log`.

## Files touched (expected)

- New: RDAP client (bootstrap-mapping fetch/cache + authoritative-server query),
  `PluginDomainmanagerCronRdapEnrichment` cron task class, migration adding
  `last_rdap_check_date` (and any new "last changed" field) to the relevant table.
- `ARCHITECTURE.md` — RDAP-as-fallback architecture, TLD-exclusion list/rationale,
  gap-check optimization, provenance-marker convention, cron design and rate-limit
  reasoning.
- `TESTING.md` — new regression/verification cases (see below).
- Driver/status display code — wherever "Not reported by this driver" is currently
  rendered, to source RDAP-filled values with provenance marker.
- `CHANGELOG.md` — entry once implemented (per versioning requirement).

## Verification

1. Task appears in Setup → Automatic actions with its own configurable frequency,
   independent of the existing sync task.
2. Processes exactly one eligible domain per execution; a domain already checked
   today is skipped on subsequent ticks the same day.
3. A domain with no gaps (fully reported by its driver) is never selected.
4. A domain on an unsupported TLD (e.g. `.es`) is never selected; documented in
   `ARCHITECTURE.md`.
5. Provenance marker appears only on fields RDAP actually populated, nowhere else.
6. Clean no-op when nothing is eligible — no error, no unnecessary log entries.
