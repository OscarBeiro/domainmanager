# Driver Error Message Consolidation — Audit & Design

**Date:** 2026-08-14  
**Status:** Research & Design (no code changes yet)  
**Goal:** Reduce translatable error strings from per-driver/per-call-site to O(categories) by consolidating error messages around a shared frame + driver-supplied data.

---

## Block A: Audit — Current State

### A1: DriverException Inventory

**Total `DriverException` constructions across the plugin: ~38 direct throw/return sites in production**

**By source pattern:**
- Direct `throw new DriverException(...) + return new DriverException(...)` in drivers: 23 sites
  - `src/Driver/CloudflareDriver.php:286,483,531,575,688,755,769,843,886,893,914,959`
  - `src/Driver/IonosDriver.php:299,506,645,677,687,736,740,750`
  - `src/Driver/DinahostingDriver.php:428,486,582,648,861,872`
- Via AbstractDriver helper methods: 4 static methods returning instances
  - `src/Driver/AbstractDriver.php:188,200,211,223` (`apiUnreachableException`, `apiUnavailableException`, `unexpectedResponseException`, `notWritableRecordTypeException`)
- In shared services:
  - `src/Service/DnsRecordWriteback.php:1537,1541` (supplier config, driver capability check)
  - `src/Service/RdapClient.php:73,84,91,97` (RDAP lookups)
- In factory/bootstrap:
  - `src/DriverFactory.php:59,78,127` (unsupported driver names)

**Key observation:** Already partially unified — `AbstractDriver` provides 4 shared helpers (lines 186–226), used by multiple drivers, which is the seed pattern for the full consolidation.

### A1b: Translatable String Baseline — Plugin-Wide __() Count

**Total `__()` calls across entire plugin: ~325 documented**

**Breakdown by area (representatives cited; full counts via `grep -r "__('" src/ templates/ setup.php hook.php`):**

| Area | Count | Sample files & line ranges |
|---|---|---|
| Drivers (`src/Driver/`) | 37 | `AbstractDriver.php:144,188,200,211`, `CloudflareDriver.php:483,531,575,688`, `IonosDriver.php:506,740`, `DinahostingDriver.php:428,486,861` |
| Controllers (`src/Controller/`) | 28 | `SyncController.php`, `SupplierConfigController.php` (form field labels, status messages) |
| **Services (`src/Service/`)** | **84** | `PluginLogger.php`, `SyncEngine.php:1–50` (logging prefixes, sync state messages) — **largest non-template contributor** |
| **Templates (`templates/`)** | **144** | `domain_panel.html.twig`, `supplier_domains_list.html.twig`, page headers/badges — **largest overall** |
| **Setup/Hook (`setup.php`, `hook.php`)** | **30** | Menu labels, right/profile names, search option names — **third-largest** |
| Config (`src/Config/`) | 2 | Minimal |
| **Total** | **325** | Baseline for plugin-wide reduction goal |

**Finding:** Three largest contributors after drivers are Services (84), Templates (144), and Setup/Hook (30) = 258 of 325 strings (79% of total). Drivers (37) are the fifth-largest area, making them a strategic pilot for the plugin-wide reduction effort.

### A2: DriverException Messages — Semantic Grouping

**Distinct translatable error messages in DriverException: 18 unique strings**

Grouped by semantic failure class:

| Class | Count | Strings | Notes |
|---|---|---|---|
| **Unreachable** | 3 | "%s API is unreachable", "%s API unavailable (HTTP %d)", "Unexpected response from the %s API" | Network, 5xx, JSON decode; parameterised by driver label |
| **Authentication** | 2 | "Domain is not managed by this Dinahosting account", "Domain is not managed by this IONOS account" | Domain-scoped auth; per-driver wording |
| **Authorisation/Scope** | 1 | "Driver does not support DNS record write-back" | Interface not implemented |
| **Write Verification** | 3 | "Cloudflare did not return the created record", "Cloudflare did not return the updated record", "IONOS did not return the created record" | Per-driver ("Cloudflare" vs "IONOS") |
| **Record Not Found** | 2 | "Cloudflare did not return this record", "Dinahosting no longer reports this record" | Per-driver |
| **Validation** | 2 | "FQDN is not valid", "This record reference is no longer valid" | Generic patterns |
| **Configuration** | 1 | "Supplier configuration not found" | Generic, non-driver-specific |
| **RDAP (External)** | 3 | "RDAP lookup failed: ..." (3 variants) | Separate concern; generic/reusable wording |
| **Unsupported** | 1 | "Record type %s is not writable through Domain Manager" | Generic, parameterised by record type |

**Near-duplicate strings (differing only by provider name):**

| Group | String | File:Line |
|---|---|---|
| **"did not return" (write verification)** | "Cloudflare did not return the created record" | `CloudflareDriver.php:483` |
| | "Cloudflare did not return the updated record" | `CloudflareDriver.php:531,688` |
| | "Cloudflare did not return this record" | `CloudflareDriver.php:575` |
| | "IONOS did not return the created record" | `IonosDriver.php:506` |
| | "Dinahosting did not report the newly created record" | `DinahostingDriver.php:486` |
| **"not managed by account"** | "Domain is not managed by this Cloudflare account" | `CloudflareDriver.php:286` (in `describeAuthFailure()`) |
| | "Domain is not managed by this IONOS account" | `IonosDriver.php:740` |
| | "Domain is not managed by this Dinahosting account" | `DinahostingDriver.php:861` |

**Finding:** 6 strings with hardcoded driver names; the "did not return" group (5 instances across 3 drivers) and "not managed" group (3 instances, one per driver) are the primary consolidation targets — same semantic meaning, provider-specific wording. These 8 strings alone represent 44% of the 18-string total.

### A3: Where `apiUnreachableException()` Lives

**Location:** `src/Driver/AbstractDriver.php:186–226`

**Mechanism:** 4 static protected methods returning `DriverException` instances:
- `apiUnreachableException(string $label): DriverException` (line 186)
- `apiUnavailableException(string $label, int $status): DriverException` (line 198)
- `unexpectedResponseException(string $label): DriverException` (line 209)
- `notWritableRecordTypeException(string $type): DriverException` (line 221)

Shared between drivers and already extended from the base class. **This is the seed for consolidation** — extend it to support category-based classification rather than create a parallel system. Called from `src/Driver/CloudflareDriver.php:286`, `IonosDriver.php:299,645`, `DinahostingDriver.php:582,872`, and others.

### A4: Parameterisation vs Hardcoding

**Already parameterised:** `apiUnreachableException()` family takes `$label` (driver name).

**Hardcoded per-driver:** "Cloudflare did not return the X record", "Domain is not managed by this Cloudflare/Dinahosting/IONOS account", etc.

### A5: UI vs Log Messages

**Observation:** Every `DriverException` is designed as "safe to persist" — same message in UI (toast) and logs (PluginLogger). No current split by channel; could be added in future.

---

## Block B: Provider Error Shapes & Security

### B6: Provider Error Response Shapes

**Cloudflare:** `{errors: [{code, message}], success: boolean}` 
- Structure: array of error objects inside `errors` key; `success` boolean indicator
- Envelope: HTTP 200 with non-success `success` field (structured, not status-code driven)
- Codes: numeric (e.g., `81057` for invalid API token)
- Reference: `src/Driver/CloudflareDriver.php:843–918` (error parsing in `describeAuthFailure()`, `describeForbidden()` methods)

**Dinahosting:** `{responseCode, message, errors: [{code, message, parameter}]}`
- Structure: top-level `responseCode` + optional `errors` array with per-field errors
- Envelope: HTTP 200 with `responseCode` field (never HTTP errors for API logic failures)
- Codes: numeric (e.g., `2303` for "domain not found" / "object not exists")
- Reference: `src/Driver/DinahostingDriver.php:582` (error handling in `qualifyHostname()` + `fetchZoneRecords()`); ARCHITECTURE.md §3.8.2 live-account findings (line 409–417)

**IONOS:** Two APIs, two structures:
- DNS API (`/dns/v1/`): `{"message": "..."}` — simple error envelope, real HTTP status codes (400/401 used per spec)
- Domains API (`/domains/v1/`): Either `{"message": "..."}` (auth gateway) or `[{code, message}]` array (application errors)
- Codes: generic HTTP-semantic (400, 401) or provider-specific (confirmed via live probing against API)
- Reference: `src/Driver/IonosDriver.php:677,687,736,750` (error handling); ARCHITECTURE.md §3.9 (line 421–437) cross-verified against OpenAPI 3.0.2 spec

**Key finding:** Provider error messages always available (code + message or message alone); no shared codes across providers. Each uses different HTTP-semantic conventions (Cloudflare/Dinahosting use HTTP 200 + payload codes; IONOS uses real status codes).

### B7: Security — Credentials/Secrets in Provider Messages

**Risk assessment (live against actual APIs):**

| Provider | Risk | Scrubbing |
|---|---|---|
| Cloudflare | **Low** | API returns generic messages; never echoes credentials | Not required |
| Dinahosting | **Medium** | Sanitised messages; field names mentioned but not values | Low risk; defense-in-depth in place |
| IONOS | **Low** | Generic "Missing or invalid credentials" / "Invalid API key format" | Not required |

**Confirmed:** No provider sends credentials in error messages. Scrubbing in `PluginLogger::redact()` already in place; no new mechanism required.

**Localisation:** All providers return messages in English only. Frame can be translated; provider message payload cannot.

---

## Block C: Tensions & Resolutions

### C9: §12.6 — "Error classification happens entirely inside each driver"

**The principle (ARCHITECTURE.md §12.6, lines 1987–1996):**
> The real risk — repeating the exact mistake found in §12.5 items 3–4 — is error-message and error-code handling leaking into the shared layer. Concretely: a shared table mapping "HTTP 403 → this exact message," maintained inside `DnsRecordWriteback` or `DomainState` and keyed by status code alone, would immediately be wrong for the next provider whose 403 means something narrower or broader than Cloudflare's zone-scoped one. **Prevention, already the pattern in place:** error classification and message construction happen entirely inside each driver's own write methods (§12.4); the shared layer (`DnsRecordWriteback`) only ever receives an already-formatted, already-safe `DriverException` message and passes it through unchanged (exactly as `$e->getMessage()` is used today). No shared code needs to know what a 403 means to any particular provider.

**How proposed consolidation preserves §12.6:**
- Drivers own code→category mapping (as data, static method per driver, e.g., "Dinahosting 2303 → domain not found")
- Shared layer owns frame + category set + the helper that *calls* driver classification (frame: "%1$s: %2$s (%3$s)" + 9 category labels)
- This is NOT a violation of §12.6 — it extends the existing pattern: drivers still classify, shared layer still passes through unchanged. The difference is the *form* classification takes: no longer "the driver composes a full message string," but "the driver declares a category via static method, and the shared helper builds the frame."

### C10: Actionability vs Rawness

**The tension (from ARCHITECTURE.md §3.8.2, lines 414–415):**
> **`2303`/`CODE_OBJECT_NOT_EXISTS` does not always mean "domain not managed by this account"**, contradicting this section's own line above (§3.8, `request()`'s shared error mapping) — that reading was only ever confirmed for domain-level commands (`Domain_Zone_GetAll`, `Services_GetDomains`). For a `Domain_Zone_DeleteType*` call specifically, the same code means "this hostname/value combination doesn't exist" (the raw error text is literally `Param "hostname"/"ip" value doesn't exist`), and mapping it to the domain-level message is actively misleading in that context.

The general problem: a generic category ("domain not found") can mask provider-specific nuance (Dinahosting `2303` means "domain" in one context but "record" in another). A raw provider message is always accurate but unintelligible to end users.

**Resolution:** Frame + category + code together:
- Frame tells user the category ("domain not found", "record not found", etc.) — actionable, broad category
- Code provides provider-specific context for lookup (e.g., "2303" in Dinahosting docs, which clarifies the per-command variants)
- Raw message (the provider's own text) adds support-level detail for triage
- This is a **design judgment**, not automatic: the category set (9 labels) must be chosen so no two genuinely different provider situations collapse into the same label — if they do, split the category. The "record not found" vs "domain not found" distinction already exists in Dinahosting's use of 2303, and the proposed category set preserves it.

### C11: Interaction with Phase 51b

**Phase 51b (future):** Per-command error mapping (e.g., Dinahosting's `2303` means different things for delete vs fetch).

**If consolidation lands first (recommended):** Drivers build categories now; Phase 51b refines by command later. No conflict.

### C12: GLPI Core Convention

**Research limitation:** No local `glpi-project/glpi` clone on `11.0/bugfixes` branch available to inspect. GLPI 11 core source inspection skipped.

**Provisional finding (unverified):** GLPI 11 has no standard error code convention. Plugins establish their own. (This would need confirmation by inspecting core error-handling patterns in `src/CommonDBTM.php`, `src/Session.php`, or the generic form class if it exists.)

**Interim decision (recommended, pending verification):** Use provider codes passed through unchanged (Cloudflare `81057`, Dinahosting `2303`, IONOS `400`). Trade-offs:
- Pro: Real provider docs are directly usable; support staff already familiar with these codes
- Con: Documentation table gets bigger (but table already exists in ARCHITECTURE.md §3.8.2)
- Risk: If GLPI core does have a convention, this design may not follow it (low risk given how provider APIs are external)
- Status: Acceptable for now; revisit if GLPI audit surfaces a core pattern

---

## Part 2: Design Plan

### Proposed Consolidation Scheme

#### 1. Consolidated Frame
**One frame with positional placeholders:**
```
'%1$s: %2$s (%3$s)'
```
- `%1$s` = category label (e.g. "wrong credentials", "domain not managed")
- `%2$s` = provider code (e.g. "2303", "403", "81057")
- `%3$s` = provider message (raw, from API)

**Result:** "Dinahosting: domain not found (2303: Param 'hostname' value doesn't exist)"

#### 2. Category Labels — Static, Shared Map
**New: 9 category labels (translatable)**
- Unreachable, Auth Failed, Auth Scoped, Domain Not Found, Record Not Found, Config Error, Validation Error, Write Verification, Unsupported

#### 3. Driver-Supplied Code→Category Mapping
**Each driver declares its own mapping as data (not logic):**
```php
private static function classifyError(int $code, string $message): ErrorCategory {
    return match($code) {
        2200 => ErrorCategory::AuthFailed,
        2201 => ErrorCategory::AuthScoped,
        2303 => ErrorCategory::DomainNotFound,
        default => ErrorCategory::ValidationError,
    };
}
```

#### 4. Shared Helper for Message Construction
```php
DriverException::buildFromProvider(
    string $driverLabel,
    ErrorCategory $category,
    ?int $code,
    string $providerMessage,
): DriverException
```

### Before/After String Count

| Scope | Before | After | Reduction |
|---|---|---|---|
| **Driver errors only** | 18 hardcoded | 10 (9 labels + 1 frame) | **44% reduction** |
| **All drivers** | 37 | ~25–30 | **20–30% reduction** |
| **Plugin-wide** | 325 | ~305–315 | **3–5% reduction** |

**Key metric:** Adding driver #4 → with consolidation: cost **stays flat**, not linear.

### Migration Path

**Phase 1: Non-Breaking Refactor**
1. Introduce `ErrorCategory` enum
2. Add static error classification methods per driver
3. Add `DriverException::buildFromProvider()` helper
4. One driver at a time: replace hardcoded `__('...')` with category-based calls
5. No user-facing changes; messages reformatted but content identical

**Per-driver order:** Any order; no dependencies. CloudflareDriver smallest change.

**Incremental:** Each driver migrates independently; no big-bang refactor.

### New Driver Author Expectations

**To add driver #4:**
1. Implement error classification method (3–5 lines, pattern from existing drivers)
2. Call `DriverException::buildFromProvider()` when throwing
3. No new translatable strings (categories already exist)
4. If code doesn't fit existing category, propose new one (edit `ErrorCategory`, add 1 translatable label)

### Out of Scope

- Changing error codes themselves (provider codes passed through as-is)
- Consolidating non-error messages (Services, Templates are separate phases)
- Retroactive error message mapping
- Credential storage refactor

---

## Open Questions for You

The design above makes several judgment calls that are yours to approve or override:

### Decision 1: Error Code Handling — Plugin-Namespaced vs. Pass-Through Raw

**Recommendation:** Pass provider codes through unchanged (Cloudflare `81057`, Dinahosting `2303`, IONOS `400`/`401`).

**Trade-off:**
- **Pass-through (recommended):** Support staff already knows these codes from provider docs; documentation burden stays light; no new namespace to maintain.
- **Plugin-namespaced:** Creates a stable, translatable error-code reference ("Error DM-2001: ...") that can be controlled in one place, but requires an error-code mapping table and documentation of the mapping, and adds no real value since providers' codes are already stable and have public docs.

**Decision:** Approve pass-through, or would you prefer plugin-namespaced codes?

### Decision 2: ARCHITECTURE.md §12.6 — Superseded or Preserved?

**The constraint (§12.6, lines 1987–1996):** Error classification must happen entirely inside each driver, never in the shared layer, because the same HTTP code means different things to different providers.

**This design's approach:** Drivers still own classification (each has its own static `classifyError(code, message): ErrorCategory` method), but the shared layer now builds the message from that classification. The constraint is **preserved** — no shared table mapping codes to messages; instead, a shared table mapping *categories* (which drivers declare) to labels (which are translatable).

**Is this correct?** Or should error construction stay entirely in the driver, with shared layers only passing through finished `DriverException` messages unchanged (the current strict interpretation)?

**Decision:** Confirm "preserved" is the right framing, or prefer stricter driver-only construction (and accept that consolidation cannot happen)?

### Decision 3: Category Label Set — Correct?

The proposed 9 labels are:
**Unreachable, Auth Failed, Auth Scoped, Domain Not Found, Record Not Found, Config Error, Validation Error, Write Verification, Unsupported**

**Concern:** Is "Write Verification" a genuine category (a real failure mode), or should it collapse into "Unexpected Response"? The category exists because Cloudflare/IONOS/Dinahosting all have a failure mode "you told me to create/update a record, I got back success=true, but the record wasn't actually created/updated" — that *is* a distinct operational failure (user should retry; support should check if the write actually happened upstream). Keep it, or merge into a broader category?

**Decision:** Approve the 9 labels, or revise?

### Decision 4: GLPI Core Convention — Not Verified

No local GLPI core clone was available to inspect for error-handling conventions (§12.6 research). The design assumes GLPI has no standard error-code convention and that plugins establish their own. **If you have a GLPI 11.0/bugfixes clone handy, please verify** this assumption before implementation; if GLPI does have a standard pattern, we should follow it.

**Decision:** Acknowledged; proceed with pass-through codes pending verification.

---

## Summary

**Before:** 18 distinct error strings, 6 hardcoded driver names, 2–3 near-duplicates.  
Adding driver #4: +6–7 more strings.

**After:** 10 shared strings (9 category labels + 1 frame).  
Adding driver #4: 0 new strings (just 3–5 lines of code).

**Preservation:** §12.6 preserved — drivers own error classification via data.

**Impact:** Non-breaking, incremental. Users see reformatted messages; behavior unchanged.

**Next step:** Review and approve; then Phase 1 implementation begins.

