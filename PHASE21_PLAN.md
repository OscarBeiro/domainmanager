# Phase 21–23 Plan — RDAP as a Fallback / Supplementary Data Source

> **Supersedes `PHASE14_PLAN.md`.** Same underlying idea (RDAP fills gaps the
> configured registrar driver leaves blank), but renumbered to start at **Phase 21**
> and split into 3 sessions instead of 1, per 2026-07-28 review. Two design points
> from the old plan are simplified below (rate-limit strategy, TLD-support
> detection) and the field scope is expanded. Everything in the old plan not
> explicitly changed here still holds.

## What changed vs. the old plan (read this first)

1. **No IANA bootstrap-file caching, no per-TLD authoritative-server map.**
   The old plan's rate-limit defense was "cache the bootstrap file once, query
   authoritative servers directly, bypass `rdap.org`'s 10 req/10s entirely."
   That machinery is real engineering cost (fetch/parse/cache/refresh a registry
   file, maintain a TLD→server table) for a problem the cron design already
   solves on its own: capped at **1 domain per tick, 1 tick per 10 minutes**,
   the plugin will never exceed ~6 requests/hour to `rdap.org` — nowhere near
   its 10-req-per-10-second limit. **Decision: query `rdap.org` directly, no
   bootstrap cache.** If tick frequency is ever increased by orders of
   magnitude later, the bootstrap-cache approach from the old plan can be
   revisited — not needed now, and skipping it is the single biggest
   token/engineering savings in this re-plan.

2. **TLD support is detected reactively, not from a maintained list.**
   The old plan's decision 1 excluded unsupported TLDs (e.g. `.es`) *before*
   querying, via a hand-maintained exclusion list sourced from
   `deployment.rdap.org`. Without the bootstrap file, there's no local copy of
   that deployment table to filter against pre-query, and hand-maintaining one
   is exactly the kind of manual-upkeep liability this plugin's existing
   drivers already avoid elsewhere. **Decision: attempt the lookup; a 404 (or
   "no redirect found") response from `rdap.org` for the domain's TLD is
   treated as the normal "no RDAP data for this TLD" outcome** — same
   `last_rdap_check_date` bookkeeping as any other checked-and-nothing-new
   result, same "not an error" logging rule. Self-updating as registries add
   RDAP support, no list to maintain.

3. **Field scope expanded** beyond the old plan's dates/lock-status pair. See
   table below.

---

## RDAP data now in scope

| Field | RDAP source | Gap-check against | New or existing storage |
|---|---|---|---|
| Registration date | `events[eventAction=registration]` | `date_domaincreation` | existing (Domain) |
| Expiration date | `events[eventAction=expiration]` | `date_expiration` | existing (Domain) |
| Last-changed timestamp | `events[eventAction=last changed]` | — (RDAP-only concept, never merged into Infocom's inventory date) | **new**: `rdap_last_changed_date` |
| Transfer lock | `status` (`clientTransferProhibited`/`serverTransferProhibited`) | driver's transfer-lock field | existing (Registrar details block) |
| Domain lock | `status` (`clientDeleteProhibited`/`serverDeleteProhibited`) | driver's domain-lock field | existing (Registrar details block) |
| **Pending delete** | `status` (`pendingDelete`) | driver has no equivalent today for any driver → always RDAP-only when present | **new**: `rdap_pending_delete` (tri-state: signed/not/unknown) |
| **Pending transfer** | `status` (`pendingTransfer`) | same as above | **new**: `rdap_pending_transfer` (tri-state) |
| **DNSSEC** | `secureDNS.delegationSigned` | IONOS already reports `dnssecEnabled` (§3.9) → gap only for drivers that don't (Dinahosting, Cloudflare-as-registrar n/a) | **new**: `rdap_dnssec_signed` (tri-state) |
| **Registrar of record (name + IANA ID)** | `entities[role=registrar]` → `vcardArray` fn + `publicIds[type=IANA Registrar ID]` | no driver reports this — it's a *different* concept from the Infocom Supplier link (§0.1), see note below | **new**: `rdap_registrar_name`, `rdap_registrar_iana_id` |
| **Nameservers (cross-check)** | `nameservers[].ldhName` | never a gap-fill — DNS driver's own detected NS is authoritative for the DNS pipeline; RDAP's list is informational only | **new**: `rdap_nameservers` (JSON array, display-only, not fed into `DomainRecord`/`RecordReconciler`) |
| Notices / remarks | top-level `notices`, per-object `remarks` | n/a | **not stored** — logged to `domainmanager.log` only on first-ever lookup per domain, for diagnostic value; no UI surface, no schema (see rationale below) |

**Registrar-of-record note:** `rdap_registrar_name`/`rdap_registrar_iana_id` is
a *read-only informational diagnostic*, not a second source of truth for
"who is the registrar." The plugin's only authoritative registrar concept
remains Infocom's `suppliers_id` mirror (§0.1). This RDAP field exists so a
mismatch — registry says registrar X, GLPI's Supplier says Y — is visible
without being wired into any lock/sync/reassignment logic. Display only,
same ribbon-panel convention (§6.5), no interaction beyond a mismatch badge.

**Why notices/remarks aren't stored:** they're free-text, often boilerplate
ICANN policy language, not structured facts. Storing them long-term per
domain would mean translation/display work for content that's already
expected to just say "data redacted per GDPR" (documented as the known
Registrant-contact outcome in the old plan). Logging once per domain on
first lookup preserves the diagnostic value (you can grep the log if a
domain's RDAP behavior looks odd) without a schema or UI commitment. Flag if
you'd rather have this surfaced — cheap to add later if wanted, since it's
additive.

---

## Schema changes (single migration, Phase 21)

New columns on `glpi_plugin_domainmanager_states` (the existing one-row-per-Domain
table — same placement rule already used for `is_managed`, following established
convention rather than a new table):

- `last_rdap_check_date` (datetime, nullable) — cron eligibility gate
- `rdap_last_changed_date` (datetime, nullable)
- `rdap_pending_delete` (tinyint, nullable — tri-state)
- `rdap_pending_transfer` (tinyint, nullable — tri-state)
- `rdap_dnssec_signed` (tinyint, nullable — tri-state)
- `rdap_registrar_name` (varchar 255, nullable)
- `rdap_registrar_iana_id` (varchar 32, nullable)
- `rdap_nameservers` (text, nullable — JSON array)

All nullable, all additive, no changes to existing columns — consistent with
this plugin's existing migration pattern (e.g. `is_proxied`, §5.7 addendum).

---

## Phase 21 — RDAP client, data model, gap-check

**Session scope:**
- `RdapClient` (new `Service/` class): single method, query `rdap.org/domain/{name}`
  directly (decision 1 above), parse the JSON envelope, return a DTO
  (`RdapLookupResult` — events, status array, secureDNS, entities, nameservers,
  notices) or a distinguishable "no data for this TLD/domain" result for a 404.
  Uses GLPI's own HTTP client factory (never raw Guzzle — established
  constraint from `glpi-plugin-builder`).
- Migration (`Installer::addRdapColumns()`) — the 8 columns above.
- Gap-check logic: given a Domain's current state row + driver capability,
  determine which of the fields in the scope table are still unreported.
  A domain where every relevant field is already covered by its driver
  (e.g. IONOS already reporting DNSSEC) never becomes RDAP-eligible for that
  field — reuses the existing "driver doesn't report this field, leave it
  blank" pattern (`RegistrarDriverInterface`) rather than a new concept.
- Unit-level verification only in this phase (no cron wiring yet) — confirm
  the client correctly parses a real `.com` and a real `.gal` response, and
  correctly classifies a real `.es` domain as "no data" without raising an
  error.

## Phase 22 — Cron integration, field population, provenance

**Session scope:**
- `PluginDomainmanagerCronRdapEnrichment` cron task, registered as its own
  GLPI Automatic Action (Setup → Automatic actions), default **every 10
  minutes**, independently configurable — matches your confirmed default.
- Eligibility query per tick: domains with ≥1 field gap (Phase 21 logic) AND
  `last_rdap_check_date` not today, ordered oldest/never-checked-first. Pick
  exactly one, look it up, update whichever gap fields RDAP returned, tag
  "via RDAP" provenance, set `last_rdap_check_date = today`, exit.
- No qualifying domain → clean no-op, `domainmanager.log` entry, never
  `domainmanager-errors.log`.
- Genuine failures (network error, unexpected non-200 that isn't a plain
  404-for-this-domain) → `domainmanager-errors.log`, same two-file logging
  convention as the rest of the plugin (§3.6).
- First-ever lookup for a domain also logs any `notices`/`remarks` text to
  `domainmanager.log` (diagnostic only, per table above).
- Registrar-of-record and nameserver fields are populated unconditionally
  (never gap-gated — no driver reports these today) but never touch
  `Infocom::suppliers_id` or `DomainRecord`/`ImportLock` in any way — purely
  informational columns, reinforced here so it doesn't accidentally get wired
  into the lock/sync mechanism later.

## Phase 23 — UI surfacing, cross-check display, docs

**Session scope:**
- Registrar details block: render `rdap_pending_delete`/`rdap_pending_transfer`
  /`rdap_dnssec_signed` wherever the driver currently shows "Not reported by
  this driver," with "via RDAP" provenance marker (existing convention,
  Registrar details block already has this "driver didn't report it" slot).
- New informational sub-panel (same ribbon-header convention, §6.5): registrar-
  of-record mismatch badge (RDAP name/IANA ID vs. Infocom Supplier, shown only
  when they actually differ) and nameserver cross-check (RDAP's list vs. the
  DNS pipeline's live-detected NS, shown only on mismatch — no badge at all
  when they agree, to avoid noise on the common case).
- Config-page status indicator (same page as the Domain Type setting, §6.6):
  "N domains pending RDAP enrichment, last processed at [time]."
- `ARCHITECTURE.md` — new phase entries (21–23), the "no bootstrap cache /
  reactive TLD detection" decision and rationale, the full field-scope table,
  the registrar-of-record-is-diagnostic-only clarification.
- `TESTING.md` — regression cases (see Verification below).
- `CHANGELOG.md` entry.
- Delete old `PHASE14_PLAN.md` reference material from `ARCHITECTURE.md`'s §9
  Phase-14-unlink note if it still points at the old plan file name.

---

## Rate-limit rationale (confirmed against your cron design)

At 1 request per 10-minute tick, worst case is ~144 requests/day to `rdap.org` —
far under any per-second Cloudflare limit, and the once-daily-per-domain cap means
a large portfolio simply takes proportionally longer to fully cycle (same
tradeoff the old plan already accepted, now stated without needing the
bootstrap-cache justification). No client-side rate-limit state needed beyond
"has this domain been checked today" — the tick spacing *is* the defense, exactly
as your cron design intended.

---

## Verification

1. Cron task appears in Setup → Automatic actions, default 10-minute frequency,
   independent of the daily sync task.
2. Processes exactly one eligible domain per execution; already-checked-today
   domains are skipped on subsequent ticks the same day.
3. A domain whose driver already reports every in-scope field is never selected.
4. A real `.es` domain is queried, gets a 404 from `rdap.org`, is logged as a
   normal no-data outcome (not an error), and `last_rdap_check_date` is still
   set so it isn't retried until tomorrow.
5. A real `.com` and a real `.gal` domain populate all applicable new columns
   correctly, with "via RDAP" provenance only on fields the driver left blank.
6. DNSSEC gap-fill is skipped for IONOS-registered domains (already reported)
   and applied for Dinahosting-registered domains (not reported).
7. Registrar-of-record mismatch badge appears only when RDAP's registrar name/
   IANA ID actually differs from the Domain's Infocom Supplier — never wired
   into any lock, sync, or reassignment path.
8. Nameserver cross-check badge appears only on mismatch; RDAP's nameserver
   list never reaches `DomainRecord`/`RecordReconciler`.
9. Clean no-op tick (nothing eligible) — no error, no unnecessary log entries.

---

## Files touched (expected)

- New: `Service/RdapClient.php`, `RdapLookupResult` DTO,
  `Cron/CronRdapEnrichment.php` (or similar namespace placement matching
  existing `Cron.php` conventions), migration adding the 8 columns.
- `ARCHITECTURE.md`, `TESTING.md`, `CHANGELOG.md`.
- Registrar-details Twig template + Domain form panel — new gap-fill fields,
  provenance marker, mismatch badges.
- Config page template — pending-enrichment status line.
