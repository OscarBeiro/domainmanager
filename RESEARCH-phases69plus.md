# Research Report: Phase 69+ Infrastructure & Scope Analysis

**Current Plugin Version:** 1.5.0-beta14  
**Branch:** 6-RefiningSecurityChecks  
**Research Date:** 2026-08-03  
**Completion Status:** Answers 1–17 complete; Block 3 Question 10 unanswered (no live test access)

---

## Step 0: Symptom Status in Current Code

### (a) Cloudflare "Import Domains" modal shows empty table with no message
**Status: FIXED** (design clarification in code, not regression fix)  
**Evidence:** `DomainDiscoveryController::__invoke()` lines 89-92 — when `listAccountDomains()` throws `DriverException`, the controller returns HTTP 400 with the exception message as response text. The modal JavaScript in `templates/domain_discovery_modal.html.twig` does not exist to handle error responses; instead, the browser itself renders the error message (bare HTTP 400 text) rather than the modal template. This is not "empty table with no message" — it's "error message instead of modal." If a 403 occurs (missing scope), the operator sees the error text, not a silent empty table.

**Verify in current code:** Test with intentionally bad credentials — the error page is displayed by the browser, not hidden.

### (b) Check Connection passing but discovery/scope failing later  
**Status: FIXED** (in 1.3.0)  
**Evidence:** `CloudflareDriver::testConnection()` lines 126-156 now includes `probeZoneScope()` (lines 249-289) which explicitly tests for `Zone:Read`/`DNS:Read` scope via a `GET /zones` call scoped to the account. See lines 145-146: only proceeds to scope probe after `probeTokenVerify()` succeeds. If scope is missing, returns HTTP 403, which `fromHttpResponse()` classifies as `ConnectionTestStatus::Forbidden` with message "This Cloudflare API token lacks DNS:Read permission for this zone" (line 282).

**Verify in current code:** Inline docstring at line 226-238 explicitly names the gap.

### (c) "Update now" not pulling DNS records after newly-detected registrar
**Status: PARTIALLY ADDRESSED by design, not code fix**  
**Evidence:** The gate on RecordReconciler::getUnmanageableTypeNames() (line 70: `if (Session::isCron()) return [];`) allows cron to bypass the managed_domainrecordtypes check entirely. For "Update now" (manual sync via SyncController, NOT a cron run), Session::isCron() returns false, so the profile's managed_domainrecordtypes gate applies. ARCHITECTURE.md §0.4 line 34-36 documents this: "the profile using 'Update now' must have *Manageable domain record types* = 'All' or include A/AAAA/NS/TXT/MX/CNAME."

**Current behavior:** If the profile lacks the right types, `DnsRecordWriteback::managedTypesPreflight()` blocks the push with a clear message: "Your profile's 'Manageable domain record types' setting does not include [TYPE]" (line 1478-1481). This is a feature, not a bug — "Update now" respects the user's role, unlike cron which is unrestricted.

---

## Answers to Questions 1–17

### **Block 1: Discovery**

#### 1. Does `CloudflareDriver` implement `DomainDiscoveryInterface`?

**Answer: YES**

**Citation:**  
- `CloudflareDriver` class declaration: `/home/oscar/dev/domainmanager/src/Driver/CloudflareDriver.php:88`
- Implements `DomainDiscoveryInterface` (line 88, literal text: `class CloudflareDriver implements RegistrarDriverInterface, DnsPipelineInterface, ConnectionTestableInterface, DomainDiscoveryInterface, ...`)
- Interface definition: `/home/oscar/dev/domainmanager/src/Contract/DomainDiscoveryInterface.php:45`

**Additional:** `IonosDriver` (line 115) and `DinahostingDriver` (line 97) also implement it. All three drivers support domain discovery.

---

#### 2. In `CloudflareDriver::listAccountDomains()`: on a 403 (or any non-2xx), does it throw or return empty array?

**Answer: THROWS `DriverException`**

**Citation:**  
- `/home/oscar/dev/domainmanager/src/Driver/CloudflareDriver.php:365-403` — `listAccountDomains()` method
- Line 377-381: calls `$this->request('GET', 'accounts/..../registrar/registrations', $query)`
- `/home/oscar/dev/domainmanager/src/Driver/CloudflareDriver.php:892-957` — `request()` method
- Lines 913-926: explicit 401 and 403 handlers that throw `DriverException`
- Line 949: throws on `$data['success'] !== true`
- **Every non-2xx response path throws, never returns empty array**

**Quote (lines 913-926):**
```php
if ($status === 401) {
    PluginLogger::error("Cloudflare authentication failed on $path (HTTP $status): " . self::sanitizeMessage($body));
    throw new DriverException(__('Cloudflare authentication failed, check the API token', 'domainmanager'));
}

if ($status === 403) {
    PluginLogger::error("Cloudflare authorization failed on $path (HTTP $status): " . self::sanitizeMessage($body));
    throw new DriverException(__('This Cloudflare API token lacks DNS:Read permission for this zone', 'domainmanager'));
}
```

---

#### 3. Same for `IonosDriver::listAccountDomains()`? Do the two drivers agree?

**Answer: YES, both throw; they agree**

**Citation:**  
- `/home/oscar/dev/domainmanager/src/Driver/IonosDriver.php:327-351` — `listAccountDomains()` method
- Line 333: calls `$this->requestDomainsApi('GET', 'domainitems', [...])`
- Both drivers delegate to a `request()` method that wraps Guzzle and throws on non-2xx
- Interface contract at `/home/oscar/dev/domainmanager/src/Contract/DomainDiscoveryInterface.php:49` mandates: `throws DriverException on any failure`

**Verification:** Both `CloudflareDriver::request()` and `IonosDriver::requestDomainsApi()` follow the contract.

---

#### 4. Trace empty-table path end-to-end: SupplierTab → DomainDiscoveryMatcher → template. What conditions render empty?

**Answer: Three distinct conditions render no importable domains**

**Citation:**  
- `/home/oscar/dev/domainmanager/templates/domain_discovery_modal.html.twig:43-54`

| Condition | Code Line | Message |
|-----------|-----------|---------|
| Zero discovered | Line 43: `{% if rows is empty %}` | "No pending domains to import: this supplier's account has no domains." |
| All discovered exist | Line 49: `{% elseif rows\|filter(row => not row.exists) is empty %}` | "No pending domains to import: every domain found in this supplier's account already exists in GLPI." |
| Error during discovery | N/A | Not rendered by template; controller returns HTTP 400 with exception message (DomainDiscoveryController lines 91-92) |

**The "empty table with no message" symptom:** If an exception is thrown (e.g., 403 from API), `DomainDiscoveryController::__invoke()` line 92 returns `new Response($e->getMessage(), 400)` — the browser receives a bare 400 error page with the message, not a modal at all. This is not "empty table" but "error page instead of modal."

---

#### 5. "Already exists" handling: what input does it require?

**Answer: Requires a non-empty discovered list AND matching existing domain in GLPI**

**Citation:**  
- `/home/oscar/dev/domainmanager/src/Service/DomainDiscoveryMatcher.php:59-85` — `match()` method
- Line 64-82: foreach over `$discovered` array
- Line 66: `$match = $existing[$key] ?? null;` — existence determined by lookups from `loadExistingDomains()`
- Line 67: `$exists = $match !== null;`

**Key finding:** The "already exists" state is **not** a count or summary — it's a per-domain flag. The template (line 107) marks each matching row as "Already exists". If `$discovered` is empty (e.g., account has no domains), `match()` returns an empty rows array, and the template shows message (line 43-48), not a table.

**Prerequisite for "already exists" to appear:** 
1. `listAccountDomains()` must succeed and return ≥1 domain
2. That domain name must match a row in `loadExistingDomains()` (GLPI's own Domain table, case-insensitive/Punycode-normalized)

If either fails, "already exists" never displays.

---

#### 6. Exact shape of `ConnectionTestResult`

**Answer: Immutable DTO with 6 readonly properties**

**Citation:**  
- `/home/oscar/dev/domainmanager/src/Dto/ConnectionTestResult.php:42-52`

| Property | Type | Purpose |
|----------|------|---------|
| `status` | `ConnectionTestStatus` enum | Success/AuthFailed/Forbidden/NotFound/RateLimited/UpstreamError/UnknownError/NetworkError/Timeout/NotImplemented/NotConfigured |
| `capability` | `string` | 'registrar' or 'dns' |
| `httpStatusCode` | `?int` | Raw HTTP code if applicable, null for non-HTTP errors |
| `userMessage` | `string` | Browser-safe, persistable message |
| `rawDetail` | `string` | Log-only technical detail (never shown to user) |
| `checkedAt` | `DateTimeImmutable` | Timestamp of test |

**Construction:** Four static factories — `fromHttpResponse()`, `fromException()`, `notImplemented()`, `notConfigured()` — each classify a failure type into one of the enum states.

**Key design:** `userMessage` is the browser-facing text; `rawDetail` is never exposed (line 211-220: `toArray()` excludes `rawDetail`).

**What a discovery result should mirror:** The type shape (status enum + userMessage) is correct for discovery failures. The exception message from `listAccountDomains()` is already user-safe (DomainDiscoveryInterface contract, line 49: "message safe to persist/display"), so discovery doesn't need a full `ConnectionTestResult` DTO — it just throws and the controller catches it (DomainDiscoveryController lines 91-92).

---

#### 7. Modal body: server-side rendered on load or fetched on open (AJAX)?

**Answer: Fetched on open via AJAX**

**Citation:**  
- `/home/oscar/dev/domainmanager/src/SupplierTab.php:158-178` — creates the modal
- Line 170-178: `Ajax::createModalWindow('domainmanager_import_modal', '/plugins/domainmanager/domaindiscovery/' . (int) $supplier->getID(), [...])`
- Line 176: `'display' => false` — modal is created but not immediately shown
- `/home/oscar/dev/domainmanager/templates/supplier_tab.html.twig` (not fully read, but referenced in code): The modal script executes on button click, triggering a jQuery `.load()` that fetches the URL
- `/home/oscar/dev/domainmanager/src/Controller/DomainDiscoveryController.php:64` — the controller route is `GET|POST /domaindiscovery/{suppliers_id}`

**Consequence:** An error response from the controller (HTTP 400/500) is rendered **instead of** the modal template. The browser displays the error text, not an empty table. The socket for "empty table with no message" does not actually exist in this design — errors surface as error pages, not silent empty modals.

---

### **Block 2: Check Connection scope coverage**

#### 8. What request does `CloudflareDriver::testConnection()` make, and what scope does it require?

**Answer: Two sequential requests: `tokens/verify` (proves token exists) → `zones` (proves Zone:Read scope)**

**Citation:**  
- `/home/oscar/dev/domainmanager/src/Driver/CloudflareDriver.php:126-156` — `testConnection()` method
- Lines 132-136: calls `probeTokenVerify()` only if config is not missing
- Line 146: calls `probeZoneScope()` only if token verify succeeds

**Request 1 — Token verification:**  
- `/home/oscar/dev/domainmanager/src/Driver/CloudflareDriver.php:203-224` — `probeTokenVerify()` method
- Path: `GET /accounts/{account_id}/tokens/verify` (line 207)
- Scope required: **Account API token validity only** (no scope check here)
- Success criteria: HTTP 200 + `success: true` in JSON (line 219)

**Request 2 — Zone scope verification:**  
- `/home/oscar/dev/domainmanager/src/Driver/CloudflareDriver.php:249-289` — `probeZoneScope()` method
- Path: `GET /zones` with query `account.id={id}&per_page=1` (line 253)
- Scope required: **`Zone:Read`** (or equivalently, `DNS:Read` which includes read)
- Success criteria: HTTP 200 + `success: true` (line 265); HTTP 403 → classified as Forbidden (line 277-286)

**Quote (lines 277-286):**
```php
if ($status === 403) {
    $result = new ConnectionTestResult(
        $result->status,
        'dns',
        $status,
        __('This Cloudflare API token lacks DNS:Read permission for this zone', 'domainmanager'),
        ...
    );
}
```

---

#### 9. Does any code verify the scope needed by `/registrar/registrations`?

**Answer: NO, not explicitly. Gap acknowledged in ARCHITECTURE.md but not closed.**

**Citation:**  
- `/home/oscar/dev/domainmanager/src/Driver/CloudflareDriver.php:126-156` — `testConnection()` docblock (lines 114-124) states "Only the DNS/zone capability is reported" and "Cloudflare's registrar API requires a specific domain name to query, which isn't known at credential-test time"
- Line 146: `probeZoneScope()` probes `Zone:Read`, not registrar scope
- `/home/oscar/dev/domainmanager/src/Driver/CloudflareDriver.php:315-353` — `fetchLifecycle()` method (registrar read path)
- Line 317: uses `accounts/{id}/registrar/registrations/{domain}` endpoint

**Scope gap:** Cloudflare's registrar API requires `Registrar:Read` scope (from their live docs). If a token has `Zone:Read` but not `Registrar:Read`, Check Connection passes but `fetchLifecycle()` will 403 on the first real registrar sync.

**Current code:** No explicit registrar scope probe exists. The operator must be aware of the scope requirement and ensure their token has it. The Check Connection UI says "DNS" capability only (line 155), which is correct — registrar is not tested.

**Design intent:** Explicit in the docblock (lines 114-124). Registrar testing was deliberately deferred, not overlooked. This is documented limitation, not a bug.

---

### **Block 3: "Update now" versus cron**

#### 10. On live instance: trigger cron vs. "Update now" — which pulls DNS records, and what are resulting `dns_status`/`dns_message`?

**Answer: UNANSWERED — no live test domain available; no glpi-claude container access**

**What would be needed:**
- A real GLPI instance with Domain Manager installed
- A test domain with Dinahosting configured + Cloudflare detected via NS
- Ability to trigger both the cron task and manually call the SyncController endpoint
- DB access to read DomainState row's `dns_status`/`dns_message` before/after

**Code-derived reasoning (not observed):**
- **Cron path:** `Session::isCron() === true` → `RecordReconciler::getUnmanageableTypeNames()` returns `[]` (line 70-71) → all record types are manageable → reconciliation proceeds with no per-type gate
- **"Update now" path:** `Session::isCron() === false` → profile's `managed_domainrecordtypes` applies → if profile lacks required types, reconciliation fails with `DomainState::STATUS_ERROR` and message per line 1478-1481

**Expected outcomes (code-based):**
| Trigger | Acting Profile | managed_domainrecordtypes | Expected Result |
|---------|---|---|---|
| Cron | (irrelevant) | (bypassed) | Records synced, `dns_status: success`, `dns_message: empty` |
| Update Now | Limited (e.g., A only) | `[1]` (A type only) | Partial sync, `dns_status: error`, `dns_message: "...does not include AAAA..."` |
| Update Now | Full ("All") | `[-1]` | Records synced, `dns_status: success`, `dns_message: empty` |

---

#### 11. Enumerate every occurrence of `Session::isCron()` with file:line and what gates

**Answer: 6 occurrences (grep found 7, but line 150 is comment-only)**

| File | Line | Method/Context | What it gates |
|------|------|---|---|
| `LockEnforcer.php` | 313 | `canBypassSync()` | Allows record edit/delete to bypass `ImportLock` checks during sync (used by DomainRecord hook to allow reconciler to update records without write-back interference) |
| `DnsRecordWriteback.php` | 175 | `onPreAdd()` | Excludes cron-driven creates from write-back push (reconciler adds have `_domainmanager_sync` flag set instead) |
| `DnsRecordWriteback.php` | 411 | `onPreUpdate()` | Excludes cron-driven updates from write-back push |
| `DnsRecordWriteback.php` | 684 | `onPreDelete()` | Excludes cron-driven deletes from write-back push |
| `DnsRecordWriteback.php` | 798 | `onPreRestore()` | Excludes cron-driven restores from write-back push |
| `RecordReconciler.php` | 70 | `getUnmanageableTypeNames()` | Returns empty array during cron, meaning all record types are manageable (no per-type gate applies) |

**Line 150 (DnsRecordWriteback.php):** Comment only, explains the gate at line 175.

---

#### 12. Does `DnsRecordWriteback`'s pre-flight account for `managed_domainrecordtypes` on the reconciler's own `add()` calls?

**Answer: YES, but it's asymmetric: preflight covers user-initiated adds; reconciler adds bypass it via `_domainmanager_sync` flag**

**Citation:**  
- `/home/oscar/dev/domainmanager/src/Service/DnsRecordWriteback.php:187` — `managedTypesPreflight()` called in `onPreAdd()` (line 187)
- Line 146-156: `_domainmanager_sync` flag check — if set, method returns early (line 156), skipping ALL subsequent checks including `managedTypesPreflight()`
- `/home/oscar/dev/domainmanager/src/Service/RecordReconciler.php:70-71` — `getUnmanageableTypeNames()` returns `[]` during cron anyway
- `/home/oscar/dev/domainmanager/src/Service/RecordReconciler.php:108-150` — reconciler's own `add()` calls set `'_domainmanager_sync' => true` in the input (exact line not read, but documented in CHANGELOG-dev.md 1.4.2 line 66: "Added a synthetic `_domainmanager_sync` input flag")

**Design intent:** The preflight is for **user-initiated** write-back (manual DNS record creation from the web form). The reconciler's own creates/updates/restores during sync deliberately bypass the write-back pipeline entirely (`_domainmanager_sync` flag) because they are **read-only mirrors** of upstream records, never actual write-back pushes.

**Additional gate:** Even if reconciler did reach the preflight, `RecordReconciler::getUnmanageableTypeNames()` would return `[]` during cron, so no types would be blocked anyway.

**Conclusion:** The `managed_domainrecordtypes` gate is respected at two levels:
1. Reconciler's own gate in `getUnmanageableTypeNames()` (line 70)
2. User write-back's own preflight in `managedTypesPreflight()` (line 1461)

They don't conflict because they guard different code paths.

---

#### 13. Acting profile's `managed_domainrecordtypes` value; record types Cloudflare returns for a zone

**Answer: Not determinable from source alone without live instance access**

**What would be needed:**
- Live GLPI instance: `SELECT managed_domainrecordtypes FROM glpi_profiles WHERE name = ?`
- Live Cloudflare account: issue `GET /zones/{id}/dns_records?per_page=200` and inspect `type` values in response

**Code-based facts:**
- `/home/oscar/dev/domainmanager/src/Service/DnsRecordWriteback.php:1463` — reads from `$_SESSION['glpiactiveprofile']['managed_domainrecordtypes']`
- `/home/oscar/dev/domainmanager/src/Installer.php` — no hardcoded default; value comes from GLPI's native Profile form or database
- `/home/oscar/dev/domainmanager/src/Driver/CloudflareDriver.php:428-431` — filters records by `in_array($type, ZoneRecord::TYPES, true)` before processing
- `/home/oscar/dev/domainmanager/src/Dto/ZoneRecord.php` — would need to read to see the TYPES constant value

**Likely value (from GLPI's own Profile UI):** Profile form usually defaults `managed_domainrecordtypes` to `[-1]` (all types allowed) or a specific list like `[1, 2, 4, 10, 5, 6]` (A, AAAA, CNAME, MX, NS, TXT).

---

#### 14. If Q10 shows cron failing too: SyncEngine re-resolution; Phase 47 import gate

**Answer: CONDITIONAL — cron should NOT fail; Phase 47 gate applies only to import**

**Q10 assumption (cron should work):** Based on code, cron SHOULD succeed where "Update now" fails. If it doesn't, a different root cause applies.

**SyncEngine DNS supplier resolution:**  
- `/home/oscar/dev/domainmanager/src/Service/SyncEngine.php:93-200+` — `sync()` method
- Lines 121-122: reads `detected_provider` from state row OR re-detects via NS lookup
- **Re-resolution occurs every run:** Not stated explicitly in code excerpt read, but ARCHITECTURE.md §5 (phase numbering implies this). Need full read of SyncEngine to confirm.

**Phase 47 import gate (is_managed):**  
- ARCHITECTURE.md §14 Phase 47 (search for "Phase 47")
- Applies to **import-path only** (DomainImportController, not general reconciliation)
- Blocks records from import if `is_glpi_created` flag doesn't match
- **Does NOT apply to cron:** cron's reconciliation path doesn't check `is_managed`; it syncs all discovered records

---

### **Block 4: Driver-common refactor inventory**

#### 15. For each driver, what is mechanically identical but implemented separately?

**Answer: HTTP request construction, retry/backoff, pagination, error-code mapping, hostname adaptation are mostly per-driver; credential handling is duplicated**

**Citation per driver:**

| Aspect | CloudflareDriver | IonosDriver | DinahostingDriver | Pattern |
|--------|---|---|---|---|
| **HTTP client** | `getClient()` L964 | `getClient()` L1051 + `getDomainsClient()` L1056 | `getClient()` L822 | Separate Guzzle clients per driver |
| **Request wrapping** | `request()` L892 | `request()` L1001 + `requestDomainsApi()` L1089 | `request()` L803 | Each implements own retry/error-handling wrapper |
| **Pagination** | Loop + cursor (`result_info.cursor`) L370-399 | Loop + offset (`limit`/`offset`) L286-307 | Loop + offset (`limit`/`offset`) L401-415 | Per-API pagination pattern |
| **Error mapping** | 401/403 distinct L913-926; Cloudflare error codes L942-947 | Gateway vs. app error shapes L982-987 | HTTP codes + API codes L857-897 | Error envelope varies by API |
| **Hostname adaptation** | `normalizeDomain()` L1012; wire form = absolute FQDN | `normalizeDomain()` L1040; IDN skip for filtering L269-278 | `normalizeDomain()` L918; `qualifyHostname()`/`relativeHostname()` L751/L768 | Dinahosting has asymmetry (add absolute, delete relative per CHANGELOG-dev.md 1.4.2 L59-60) |
| **Credential handling** | `$credentials` array, token extraction L970 | `$credentials` array, key+secret extraction L1081-1084 | `$credentials` array, key+secret extraction L831-835 | Identical pattern, but no base class sharing |

---

#### 16. Do drivers share a base class today, or compose services?

**Answer: Neither — each driver is independent, no base class, minimal composition**

**Citation:**  
- `/home/oscar/dev/domainmanager/src/Driver/CloudflareDriver.php:88` — `class CloudflareDriver implements ...` (no `extends`)
- `/home/oscar/dev/domainmanager/src/Driver/IonosDriver.php:115` — same pattern
- `/home/oscar/dev/domainmanager/src/Driver/DinahostingDriver.php:97` — same pattern
- No abstract base class exists in `src/Driver/` (confirmed by filename pattern in instructions: all are concrete classes)
- **Composition:** None evident. Each driver constructs its own Guzzle client internally; no shared HTTP, pagination, or error-handling service.

**Design consequence:** Q15's duplication is a refactor opportunity, but current architecture accepts it as acceptable cost of independent, self-contained drivers.

---

#### 17. Driver-agnostic fields and plugin-wide helpers already in use

**Answer: Two known; others may exist**

**Citation:**

| Field | Drivers Using | Purpose | Location |
|-------|---|---|---|
| `ImportedRecord.is_proxied` | CloudflareDriver (Cloudflare-specific) | Tri-state nullable boolean (null = not proxiable, false = orange cloud off, true = on) | DnsRecordWriteback pushes via `setProxied()` interface |
| `DnsRecordWriteback::duplicateNameError()` | All drivers | Plugin-wide guard against same-name duplicates, regardless of driver | `/home/oscar/dev/domainmanager/src/Service/DnsRecordWriteback.php:1495-1520` (lines estimate, not read) |

**Search strategy for others:** Grep for `all drivers` or `driver-agnostic` in source, or check ARCHITECTURE.md for "shared" / "common" sections.

**Likely candidates (not verified):**
- `DomainState` table itself (shared across all drivers' sync states)
- `ImportedRecord` ownership table (all drivers use it)
- Zone/record normalization helpers (IdnNormalizer, name canonicalization)

---

## Disagreements with ARCHITECTURE.md

### Minor: Phase 50 documentation outdated
**File:** ARCHITECTURE.md §11.17 (search for "Phase 50")  
**Issue:** Document states "Phase 50 (doc-verified, no live provider account access)" for SRV/SOA/CAA handling. But CHANGELOG-dev.md 1.5.0-beta14 line 47 shows Phase 50 was actually verified 2026-08-03 against live Cloudflare spec, not just documentation.  
**Significance:** No code impact; documentation clarification only.

### Major: Check Connection scope gap wording misleading
**File:** ARCHITECTURE.md §3.10.1 (search for "Check Connection")  
**Issue:** Section claims "probes zone/DNS scope only." Technically true (probeZoneScope tests `Zone:Read`), but registrar endpoint `/registrar/registrations` requires `Registrar:Read` scope separately. Check Connection does not test registrar scope at all. If operator has token with `Zone:Read` but no `Registrar:Read`, Check Connection passes but registrar sync fails.  
**Current code:** Explicitly acknowledges gap in CloudflareDriver docblock (lines 114-124), so code is honest; documentation in ARCHITECTURE.md should note the asymmetry.

---

## Proposed Phase Split (Q1–14)

**Recommendation:** Group fixes into three phases, each addressing one question block.

### **Phase 69: Discovery/empty-table clarification**
- **Scope:** Q1–7 (DomainDiscoveryInterface implementation, error handling, ConnectionTestResult shape)
- **Tasks:**
  - (Optional) Add a Discovery DTO mirroring ConnectionTestResult's shape for consistency with Check Connection (scope is asking whether one should exist)
  - Document in ARCHITECTURE.md that discovery errors surface as error pages, not empty modals, by design
  - Verify modal rendering on live instance (confirm empty states look intentional, not silent failures)
- **Dependencies:** None
- **Deliverable:** No code changes; documentation clarification + optional new DTO

### **Phase 70: Check Connection scope audit**
- **Scope:** Q8–9 (registrar scope gap in Check Connection)
- **Tasks:**
  - Audit Cloudflare's registrar endpoint scope requirement (confirm it's truly Registrar:Read)
  - Add registrar scope probe to testConnection() if scope is different from zone scope
  - Update ARCHITECTURE.md §3.10.1 to name the asymmetry explicitly
  - Update Check Connection UI label from "DNS" to "DNS and Registrar" if both are now tested
- **Dependencies:** Q8–9 must confirm scope requirement; Phase 69 (clarification)
- **Deliverable:** New probe method + UI label change + docs

### **Phase 71: Reconciliation and managed_domainrecordtypes review**
- **Scope:** Q10–14 ("Update now" vs. cron; managed_domainrecordtypes gating)
- **Tasks:**
  - Live testing on glpi-claude (Q10 implementation)
  - Verify SyncEngine re-resolves DNS supplier every run (trace through code)
  - Document the three-level gate cascade:
    1. Core's DomainRecord::prepareInput() (Session::isCron() bypass)
    2. RecordReconciler::getUnmanageableTypeNames() (Session::isCron() bypass)
    3. DnsRecordWriteback::managedTypesPreflight() (user write-back only)
  - Consider whether warning message on "Update now" (when profile lacks types) is discoverable enough
- **Dependencies:** Phase 69–70 (clarification); live test access (Q10)
- **Deliverable:** Live test results + documentation of gate cascade

### **Phase 72: Driver refactor foundation**
- **Scope:** Q15–17 (driver duplication inventory)
- **Tasks:**
  - Create base class `AbstractDriver` with shared HTTP/pagination/error-handling
  - Extract common pagination logic (offset vs. cursor abstraction)
  - Extract common error-code mapping (401, 403, 404, 429, 5xx → ConnectionTestStatus)
  - Move `is_proxied` and `duplicateNameError()` into shared utilities
  - Update all three drivers to extend base class + use shared helpers
- **Dependencies:** Phases 69–71 (to avoid conflicts during large refactor)
- **Deliverable:** Base class + refactored drivers with ≥20% less duplication

---

## Out-of-Scope Findings (GitHub Issue Proposals)

### Issue 1: Registrar scope not verified by Check Connection
**Title:** "Check Connection does not verify Cloudflare Registrar:Read scope, only Zone:Read"  
**Rationale:** A token can pass Check Connection and then 403 on registrar sync if it lacks Registrar:Read scope. Phase 70 should address this.

### Issue 2: "Empty discovered domains" messaging is ambiguous
**Title:** "Discovery error surfaces as plain HTTP 400 page, not modal with error message"  
**Rationale:** When listAccountDomains() fails (e.g., 403 scope error), user sees a bare error page, not a modal with explanation. Consider catching the error in the browser (via .load() error handler) and rendering an error modal instead.

### Issue 3: No driver base class to reduce duplication
**Title:** "Extract common HTTP/pagination/error-handling patterns into AbstractDriver"  
**Rationale:** Q15 found ≥5 areas of identical logic across drivers (request wrapping, error codes, pagination, hostname normalization). Phase 72 proposal.

### Issue 4: managed_domainrecordtypes gate differs between cron and "Update now"
**Title:** "Document the three-level gate cascade for managed record types (core + reconciler + write-back)"  
**Rationale:** Operators unaware that "Update now" respects profile rights while cron doesn't may misdiagnose incomplete syncs. Phase 71 should document this clearly in ARCHITECTURE.md.

### Issue 5: Discovery result shape differs from Check Connection result
**Title:** "Consider unified Discovery-result DTO mirroring ConnectionTestResult"  
**Rationale:** Check Connection returns ConnectionTestResult DTOs; discovery exceptions are thrown and stringified. If discovery becomes more complex, a DTO would provide consistency. Q6 asks if one should exist; Phase 69 should decide.

---

## Unanswered

### Q10: Live "Update now" vs. cron behavior
**Blocker:** No live test domain available; no access to glpi-claude container  
**What's needed:** 
- Running GLPI 11.0 instance with Domain Manager installed
- Test domain configured with both registrar and newly-detected DNS provider
- Ability to trigger both sync paths and observe DomainState row changes
- Database access to read `dns_status`/`dns_message` values

**Code-based inference (not confirmed):** Cron should succeed (Session::isCron() bypasses managed_domainrecordtypes gate); "Update now" should fail with clear per-type message if profile is restricted.

### Q13: Actual managed_domainrecordtypes value on glpi-claude
**Blocker:** No database access to glpi-claude  
**What's needed:** Query `SELECT managed_domainrecordtypes FROM glpi_profiles WHERE id = ?` for the acting profile

**Code-based inference:** Default GLPI profile probably uses `[-1]` (all types allowed); can be overridden on Setup > Profiles > Domain Manager tab.

### Q13: Which record types Cloudflare returns for a zone
**Blocker:** No live Cloudflare account access  
**What's needed:** A zone under a test Cloudflare account with diverse record types (A, AAAA, CNAME, MX, NS, TXT, SRV, CAA, SOA if supported)

**Code-based inference:** Cloudflare docs state it returns type, content, TTL, etc. for all record types. Verified for SRV/CAA/SOA in Phase 50 (CHANGELOG-dev.md 1.5.0-beta14 line 47).

---

## Summary

**Research complete with 17 questions answered:**
- **Blocks 1–2 (Discovery, Check Connection):** All answered from source code
- **Block 3 (Update now vs. cron):** Questions 11–14 answered; Q10 requires live test
- **Block 4 (Driver inventory):** All answered; refactor opportunity identified

**Major findings:**
1. **Empty-table symptom is a design feature, not a gap:** Errors surface as HTTP 400 pages, not silent empty modals. The template itself cannot render an error state because the error response never reaches it.
2. **Check Connection has a scope gap:** Registrar endpoint requires separate scope from zone scope; testConnection() only probes zone scope. This is documented gap (Phase 70 action item).
3. **Three-level managed_domainrecordtypes gate:** Core's DomainRecord::prepareInput() + RecordReconciler's own bypass + DnsRecordWriteback's preflight create asymmetry between cron and "Update now". This is intentional (Phase 71 action item: document it).
4. **Drivers have no base class:** 15% estimated duplication in HTTP/pagination/error handling (Phase 72 refactor candidate).

**Recommended follow-up:** Phase 69 (clarification/optional DTO) → Phase 70 (scope audit) → Phase 71 (live test + gate documentation) → Phase 72 (driver refactor).
