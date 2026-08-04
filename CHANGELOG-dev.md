# Changelog for Domain Manager

All notable changes to this project will be documented in this file — the full development
history, pre-release included. See `CHANGELOG.md` for the trimmed, real-releases-only changelog.

A pre-release bump (any version with a `-alpha`/`-beta` suffix) does not mint its own dated
header — it's appended under the running `## [Unreleased]` section, which names the current
working pre-release version. Only a real release (no pre-release suffix) gets its own
`## [x.y.z] - YYYY-MM-DD` header, at which point `[Unreleased]`'s accumulated content is
collapsed into it and `[Unreleased]` resets to empty. Entries before this convention was
adopted may still show one header per pre-release bump; that's earlier history, left as-is.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries are grouped into **Features** (new capability, UI/UX change, refactor, doc/config
change) and **Bugs** (something that was actually broken, fixed) — one line each.

## [Unreleased] (1.5.1-beta2)
### Features
- **Shortened Setup page field labels.** "Domain type to apply to imported domains" → "Default domain type"; the "Sync safety guard: ..." prefix dropped from the two threshold fields' labels (now redundant with the warning callout already placed above them).
- **Renamed "blast-radius guard" to "sync safety guard" (Phase 55, ARCHITECTURE.md §15.3).** "Blast radius" was inherited incident-response jargon that didn't describe what the guard measures. Renamed throughout: `Exception\BlastRadiusExceededException` → `Exception\SyncSafetyExceededException`; `DomainState::STATUS_BLAST_RADIUS_GUARD` (`'blast_radius_guard'`) → `STATUS_SYNC_SAFETY_GUARD` (`'sync_safety_guard'`); `Config::getBlastRadiusMaxCount()`/`setBlastRadiusMaxCount()`/`getBlastRadiusMaxPercent()`/`setBlastRadiusMaxPercent()` → `getSyncSafetyMaxCount()`/`setSyncSafetyMaxCount()`/`getSyncSafetyMaxPercent()`/`setSyncSafetyMaxPercent()`; config keys `blast_radius_max_count`/`blast_radius_max_percent` → `sync_safety_max_count`/`sync_safety_max_percent`. No behavior change to the guard logic itself. Renamed without a migration (explicit decision) — an install that had customized these thresholds away from the 20/50 defaults will see them reset to the new defaults after upgrading; acceptable since this is a safety threshold, not user data. Also added a warning `alert-warning` callout and a section `<hr>` above the two threshold fields on the Setup page (`config.html.twig`), since they're advanced settings that directly weaken a data-safety mechanism if misconfigured.

### Bugs
- **`last_rdap_check_date`/`last_changed_date`/`transfer_date`/`{prefix}_test_date` columns now use `timestamp` instead of `datetime`.** These were inconsistent with the rest of `glpi_plugin_domainmanager_states`' date/time columns, which are all `timestamp`.
- **Pre-flight empty-credential check extended to IONOS and Dinahosting.** `CloudflareDriver::missingConfigMessage()` was the only driver with a pre-flight check rejecting empty required credential fields before any network call in `testConnection()`; IONOS/Dinahosting only discovered missing credentials lazily via a generic exception from `buildClient()`/`getClient()`. Extracted into new `Driver\Concern\ValidatesCredentialsTrait::missingConfigMessage(array $credentials, array $requiredFields)`, used by all three drivers. `IonosDriver::testConnection()` now returns `ConnectionTestResult::notConfigured('dns', ...)` for an empty `key`/`secret` instead of calling `probeZonesList()`; `DinahostingDriver::testConnection()` returns `notConfigured` for both `registrar` and `dns` for an empty `user`/`password` instead of calling `probeAuth()`. Cloudflare's own messages/behavior are unchanged. The existing lazy checks inside `buildClient()`/`getClient()` are left in place as defense-in-depth for callers outside `testConnection()`.

## [1.5.0] - 2026-08-03
### Bugs
- **Phase 70 addendum: fixed misleading wording on the "no permission to create domains" alert.** This branch of `domain_discovery_modal.html.twig` renders only the alert itself, with no table below it — so "none of the domains discovered below can be imported" referred to a table that was never actually shown. Reworded to "none of the domains discovered in this supplier's account can be imported" (flagged by the user right after Phase 70 landed the general "show an error instead of a blank modal" fix).
- **Phase 70 (RESEARCH-phases69plus.md §(a)): "Import Domains" modal blank on discovery error, e.g. Cloudflare/IONOS scope errors.** `DomainDiscoveryController::__invoke()` returned non-2xx HTTP statuses (400/403/404/409/500) for every failure path — supplier not found, missing permission, inactive supplier, unsupported driver, and `listAccountDomains()` throwing `DriverException`/`Throwable`. The modal is loaded via core's `Ajax::createModalWindow()`, whose generated JS calls jQuery's `.load(url, fields)`; jQuery only writes the response body into the dialog on a 2xx status, so on any error the dialog opened and stayed completely blank with no indication anything had gone wrong (reported live for Cloudflare/IONOS discovery). Fix: all error paths now go through a new `renderError()` helper that renders `domain_discovery_modal.html.twig`'s own error state (a `.alert-danger` fragment, new `error` template variable) and always returns HTTP 200, so jQuery injects it like any successful load.

- **Phase 69 addendum: same fix applied to DomainSync.** `DomainSync`'s `CronTask::register()` call had the identical `'comment'` pre-fill as RdapEnrichment (below), left out of scope in that phase — removed here for consistency, with a matching one-time `Installer::clearDomainSyncComment()` migration (same "only if untouched" guard).

- **Phase 69: RdapEnrichment automatic action no longer pre-fills the admin's comment field.** `Installer::registerCronTasks()` passed a fixed description string as `CronTask::register()`'s `'comment'` option — but that field is the admin's own free-text note on the task (Setup > Automatic actions), not this plugin's to write into; the actual fixed, non-editable description already exists via `Cron::cronInfo()`'s `'description'` entry, shown separately in the same screen. Removed the `'comment'` option from the RdapEnrichment registration; new `Installer::clearRdapEnrichmentComment()` is a one-time, idempotent migration that blanks the comment on existing installs, but only when it still holds exactly the old pre-filled text, so it never touches an admin's own note.

- **Phase 68 addendum: duplicate-name guard made content-aware for TXT.** Excluding NS/MX from Phase 68's guard (below) left TXT still exposed to the same class of bug: TXT is in `WRITABLE_TYPES` (unlike NS/MX) but is itself structurally multi-valued at a name — SPF, a site-verification string, a DKIM policy, etc. routinely coexist there with different content, and RFC 7208's "one SPF per name" rule is already separately enforced, content-aware, by `spfDuplicateError()`. `duplicateNameError()` gained an optional `$data` parameter: when the type resolves to TXT, the match now requires type+name+**data** (an exact content repeat) rather than type+name alone, so two different legitimate TXT records at the same name no longer collide; `A`/`AAAA`/`CNAME` are unchanged (still type+name-only — round-robin multi-value stays deliberately disallowed there). All three call sites (`onPreAdd()`/`onPreUpdate()`/`onPreRestore()`) now thread the record's `data` through. Not yet verified live (code review + `php -l` only).
- **Phase 68: duplicate-name guard scoped to WRITABLE_TYPES.** `DnsRecordWriteback::onPreAdd()`/`onPreUpdate()`/`onPreRestore()`'s plugin-wide duplicate-name guard (added Phase 62-era, § live bug 2026-08-03 for the Dinahosting "manel" retry-storm) keyed only on `domains_id`+`domainrecordtypes_id`+`name`, with no regard for whether that type is even one this plugin ever writes. NS and MX are structurally multi-valued (every zone has 2+ NS records, often 2+ MX at the same name) and are not in `DnsRecordWriterInterface::WRITABLE_TYPES` (`['A', 'AAAA', 'CNAME', 'TXT']`) — the guard has nothing to protect for either, since the plugin never pushes them. Applied unscoped, it rejected every NS/MX record past the first `RecordReconciler` tried to mirror in locally at a given name, on every single sync run, logging a spurious "does not allow duplicate records" failure repeatedly for perfectly normal zone contents (reported live 2026-08-03 via the domainsync automatic action on beiro.net/bodegamoraima.com/desmarque.es). Fix: all three call sites now resolve the record's type name first and only run `duplicateNameError()` when that type is in `WRITABLE_TYPES`; A/AAAA/CNAME/TXT behavior is unchanged. Not yet verified live (code review + `php -l` only).

### Features
- **Phase 67 (ARCHITECTURE.md §15.6): Ascio and Hostalia NS registry entries.** New detection-only entries added to `resources/ns-providers.json` for Ascio (`*.ascio.com`, `*.ascio.net`) and Hostalia (`ns[0-9]*.hostalia.com`). Ubilibet, a reseller on Ascio's wholesale platform, is deliberately not added — every Ascio reseller's customer zones share the same four ns1–ns4.ascio.com/net hostnames with no per-reseller label, so NS-based detection cannot identify a reseller on a shared wholesale platform; detection correctly names only the underlying DNS platform (Ascio), not the commercial registrar relationship (Ubilibet), which is what the manually-assigned Registrar field is for per §4. Verifications before code: (1) `dig NS ubilibet.com` returns ns1–ns4.ascio.com, confirming ubilibet.com itself is on Ascio; (2) `dig NS hostalia.com` returns ns.hostalia.com and ns2.hostalia.com; (3) `dig` against ns1–ns5.hostalia.com confirms all resolve in 82.194.x range per §15.6 observations, and (4) registry file order `*.ascio.com` checked for collision against existing entries — no conflict found. Both entries are detection-only (no `driver` key); API integration not yet supported. Registry format and matching unchanged.

- **Phases 63-66 (ARCHITECTURE.md §15.5): Group D, full record-type coverage.** Phase 63: new `ZoneRecord::normalizeMxContent()` guarantees the RFC-canonical trailing dot on an MX target (core's `is_fqdn` convention for that field, §11.5/§15.5), applied uniformly by all three drivers' MX extraction rather than resolved per-provider (no live account access this phase) — every driver already joined `"<priority> <target>"` in the right order/separator, only the trailing dot was ever missing. `Installer::renormalizeMxTrailingDot()` is the matching one-time, idempotent migration for MX rows stored before this change, same pattern as `renormalizeDinahostingRemoteIds()`. Phases 64 and 65 turned out to already be done, predating this phase numbering: `ZoneRecord::TYPES`/`Installer::RECORD_TYPE_NAMES` already list all 11 GLPI types (post-1.2.0), and the per-provider SRV/CAA/SOA doc-audit this doc's own resolved-items table already recorded (Phase 50) covers the same ground — both cross-referenced in ARCHITECTURE.md rather than redone. Phase 66: new `DriverRegistry::supportsApexCname()` (Cloudflare only, per its documented CNAME-flattening; IONOS/Dinahosting both false) replaces Phase 60's unconditional apex-CNAME refusal — `RecordValidator::validateCnameTarget()` gained an `$apexAllowed` parameter (default false), threaded from the domain's configured driver via `DnsRecordWriteback::runTypeValidators()`. Documentation-sourced only; neither a live Cloudflare apex-CNAME write nor Dinahosting's own apex-alias behaviour was actually tested this phase. Not yet verified live for any of Phases 63/66 (unit-style manual checks only; see TESTING.md).

- **Phase 62 (ARCHITECTURE.md §15.4): wire-up and TTL guardrails (server-side half).** New `DnsRecordWriteback::runTypeValidators()` is the single call site `onPreAdd()`/`onPreUpdate()` both use to run Phases 59-61's validators (`RecordValidator::validateAddress()`/`validateCnameTarget()`/`validateTxtContent()`) against whichever type is actually being written, plus their DB-backed coexistence siblings (`cnameCoexistenceError()`, `spfDuplicateError()`) that need a query `RecordValidator` deliberately can't run. A block aborts the write with the validator's own message; a warning surfaces via `Session::addMessageAfterRedirect()` without blocking; the canonicalized value (today, only A/AAAA changes) replaces `$data` before the driver push. `onPreRestore()` is deliberately excluded — it recreates data that already passed these checks once, at the original create/update. Also: new `DriverRegistry::getMinTtl()` replaces `sanitizeInputs()`'s hardcoded `60` floor with a per-driver value — IONOS's 60 stays live-probe-confirmed (§11.9), Cloudflare's 60 is sourced from Cloudflare's own public API docs rather than independently probed against this plugin's own account, and Dinahosting has no `ttl` write parameter at all (server-managed) so its value is unused filler; all three currently agree at 60, so this is a structural change rather than a behavioural one until a driver with a genuinely different minimum exists. **Not done: "echo them client-side."** This plugin has no JS layer on the native `DomainRecord` form at all (it intercepts entirely server-side via item hooks), so a blocked/warned record is only discovered on submit; adding client-side echo is a separate design question left open as a follow-up rather than bolted on ad hoc. Not yet verified live (unit-style manual checks only; see TESTING.md).

- **Phase 61 (ARCHITECTURE.md §15.4): TXT validation, block mechanics and warn semantics.** New `RecordValidator::validateTxtContent()` blocks a single character-string over 255 octets (this plugin has no chunking of its own, so the open verification of whether Cloudflare/Dinahosting pre-chunk server-side stays unresolved but doesn't gate landing this) and a value already wrapped in a matching double-quote pair — this plugin's internal `data` convention is always unquoted (§11.5; quoting only happens at the IONOS wire boundary in `toWireContent()`/`extractContent()`), so an already-quoted value most likely arrived via GLPI core's own `quote_value` field-composer rather than as literal content, and pushing it through would double-quote on the wire. RFC 7208's "no more than one `v=spf1` TXT at a name" needs a DB query across every TXT record at the name, so it's a sibling DB-backed check, `DnsRecordWriteback::spfDuplicateError()`, same shape as Phase 60's `cnameCoexistenceError()`. Warns (never blocks, regex-counted heuristics rather than a full parser): SPF exceeding 10 DNS-lookup mechanisms (RFC 7208's own evaluation cap), a DMARC-shaped value (`v=DMARC1`) not at a `_dmarc` label or missing a `p=` tag, and a DKIM-shaped value (name containing `._domainkey.` and content containing `v=DKIM1`) missing a `p=` tag. Neither validator is wired into `onPreAdd()`/`onPreUpdate()` yet — Phase 62, same as Phases 59-60. Not yet verified live (unit-style manual checks only; see TESTING.md).

- **Phase 60 (ARCHITECTURE.md §15.4): CNAME validation, including the relational rule.** New `RecordValidator::validateCnameTarget()` blocks an invalid-FQDN target, self-reference, and apex CNAME (no configured driver declares support yet — Phase 66). The rule this phase is actually named for — RFC 1034's "a CNAME may not coexist with any other record type at the same owner name" — can't live in `RecordValidator` since it needs a DB query across every type at a name, not a pure check: `duplicateNameError()` only keys on `domains_id`+`domainrecordtypes_id`+`name`, so a CNAME and an A record at one name pass it today and silently produce a broken zone. Added as a sibling DB-backed check, `DnsRecordWriteback::cnameCoexistenceError()`, enforcing the rule in both directions (CNAME blocked by any existing record at the name; any other type blocked by an existing CNAME at the name). Neither validator is wired into `onPreAdd()`/`onPreUpdate()` yet — that's still Phase 62's job, once Phase 61 exists too. Not yet verified live (unit-style manual checks only; see TESTING.md).

- **Phase 59 (ARCHITECTURE.md §15.4): A/AAAA validation and canonicalization.** New `RecordValidator::validateAddress()` (`src/Service/RecordValidator.php`) validates an A/AAAA record's `data` via `filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4|FILTER_FLAG_IPV6)` and canonicalizes it through an `inet_pton()`/`inet_ntop()` round-trip, so e.g. `2001:0db8::1` and `2001:db8::1` normalize to the same stored form and stop reading as a diff on every sync. Warns (never blocks) when the address falls in a private/reserved range (RFC1918, loopback, link-local, or an IPv4-mapped IPv6 address wrapping one of those) via `FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE` — legitimate in split-horizon setups, so this can't be a hard refusal. Deliberately **not yet wired into `DnsRecordWriteback::onPreAdd()`/`onPreUpdate()`** — per the phase plan, registering every per-type validator at the hook layer (and echoing them client-side) is Phase 62's job, done once all of Phases 59-61 exist. Not yet verified live (unit-style manual checks only; see TESTING.md).

- **Phase 58 (ARCHITECTURE.md §15.3): explicit name-form boundary, audited across all three drivers.** Reframed from a single cross-driver canonicalizer (known wrong — the wire form is per-command, not per-plugin) into a rule about the *boundary*: the internal canonical form stays the absolute FQDN everywhere, every wire transform is a named, paired function applied at exactly one call site, and nothing outside a driver builds a name by inline string concatenation. Audit result: `DinahostingDriver`'s existing `qualifyHostname()`/`relativeHostname()` already matched this exactly (inbound normalizer in `fetchZoneRecords()`, outbound denormalizer in `deleteByIdentity()` only, matching its documented add/delete asymmetry). `CloudflareDriver` needed nothing — its wire form is absolute FQDN on both read and write. `IonosDriver` had one inline transform, `rtrim($name, '.')` at the `createRecord()` call site; extracted into a new `IonosDriver::wireHostname()` to remove the last implicit transform. Also corrected a stale claim in ARCHITECTURE.md §11.9 (that IONOS's read path "relativises" names) that predated Phase 58's canonical-form decision and no longer matched the code, which already returns absolute names as-is — correctly. `DnsRecordWriteback::absoluteRecordName()` remains confirmed as the single construction point feeding all three drivers, no bypass found. One item from the phase's verification list remains open: Dinahosting's TXT/MX apex delete convention is still unconfirmed against a live account (no apex record was safe to test-delete). Not yet verified live for the IONOS extraction (code audit only).

- **Phase 57 (ARCHITECTURE.md §15): provider writes into native History.** `DnsRecordWriteback` already logged every *successful* create/update/delete/restore/proxy-toggle to `Log::history()` on the `Domain` (added incrementally since Phase 34b) — but a failed provider write never reached the Historical tab, only `domainmanager.log`, so "GLPI tried to change this record and the provider rejected it" was invisible on the native tab an admin already knows how to check. Added a new private `logWriteAttempt()` helper, called from every write path's `catch` block in addition to its existing success line, so both outcomes of an attempted write are now recorded symmetrically. Each line follows §3.7.1's convention exactly (`id_search_option = 0`, `"[Domain Manager] "` prefix — no new search option) and carries type, name, provider, operation and outcome (succeeded/failed + a short detail) — never the full RDATA, since `Log::history()` truncates `old_value`/`new_value` at 255 chars via `mb_substr()` and would silently mangle e.g. a DKIM `p=` value; the untruncated detail stays in `domainmanager.log` via the existing `PluginLogger::error()` call at each failing site. Not yet verified live (code audit only).

- **Phase 55 (ARCHITECTURE.md §15.3): blast-radius guard on reconciliation.** `RecordReconciler::doReconcile()` now counts, after its match/claim pass but before the trash loop runs, how many currently-owned (non-deleted) records would be newly moved to the trash bin this run. If that count exceeds `Config::getBlastRadiusMaxCount()` (default 20) or that count as a percentage of the domain's owned records exceeds `Config::getBlastRadiusMaxPercent()` (default 50), it throws a new `BlastRadiusExceededException` *before* any trash-bin mutation runs — a mis-scoped credential or a truncated upstream page that parses as a valid empty/near-empty snapshot no longer gets the chance to soft-delete a whole zone. `SyncEngine::syncDnsLeg()` catches this distinctly from `DriverException`/`Throwable` and sets a new `DomainState::STATUS_BLAST_RADIUS_GUARD` (`text-bg-warning` badge, its own label) rather than `STATUS_ERROR` — this is a guard doing its job, not a failure, same "distinct status, not an error" pattern as `STATUS_SOURCE_CONFLICT` (Phase 47). Both thresholds are configurable on the Domain Manager Setup tab (`blast_radius_max_count`/`blast_radius_max_percent`, stored on the existing `plugin:domainmanager` config context). Requires explicit operator action to proceed, per the phase's own wording: `reconcile()`/`sync()` gained a `$force` parameter (default false, every existing caller unaffected), threaded from a new `force` POST field on `POST /plugins/domainmanager/sync/{id}`; the domain panel's "Update Now" button, on receiving `STATUS_BLAST_RADIUS_GUARD`, shows a `window.confirm()` naming the exact counts and re-issues the request with `force=1` only if the operator confirms. Not yet verified live (code audit only; see TESTING.md Phase 55).

- **Phase 53 (ARCHITECTURE.md §15.3): global write kill switch / read-only mode.** New `Config::isReadOnlyMode()`/`setReadOnlyMode()`, backed by a `read_only_mode` key on the existing `plugin:domainmanager` config context (explicit `1`/`0` int, §0.10), editable from the Domain Manager Setup tab via a slider field, gated on the existing `config` UPDATE right. `DnsRecordWriteback::readOnlyModeError()` is the single choke point: checked in `onPreAdd()`/`onPreUpdate()`/`onPreDelete()`/`onPreRestore()` immediately after the `isDnsEditable()` gate and *before* the per-type right check, so the kill switch is never second-guessed by a user's own rights — `abort()` surfaces the existing session-message convention, naming the setting and its location. Defense in depth: `Config::assertWritesAllowed()` (throws `DriverException`) is asserted again at the top of every driver's `createRecord()`/`updateRecord()`/`deleteRecord()` (Cloudflare, IONOS, Dinahosting) and `setProxied()`/`pushComment()` (Cloudflare only, the sole implementer of those two interfaces). This isn't redundant: `RecordReconciler::reconcileComment()` calls `CloudflareDriver::pushComment()` directly during a cron sync, entirely outside `DnsRecordWriteback`'s call path — only the driver-level assertion catches that one, caught there and logged as a best-effort failure like any other `pushComment()` error. UI surfaced at both write-control entry points: `DomainForm::injectDomainRecord()`'s Save/Delete/proxy-toggle gating and `renderRecordWritePanel()`'s add-form each show a message naming read-only mode specifically (rather than their normal "no rights"/"synchronization-locked" copy) when that's the actual reason a control is hidden. A fresh install defaults to writes allowed (`read_only_mode = 0`), so installing/upgrading never silently goes read-only. Not yet verified live (code audit only; see TESTING.md Phase 53).

### Bugs
- **Phase 52 (ARCHITECTURE.md §15.2): `DnsRecordWriteback`'s per-type write-right helpers were not entity-aware.** Audited every field lookup, type resolution and right check in `hasTypeRight()`, `hasPurgeRight()`, `writableTypes()`, `creatableTypesForDomain()` and `writableSupplierName()`, per §11.6/§8's stated convention that every entry point checks rights server-side, entity-aware. `writableTypes()` (returns a constant) and `writableSupplierName()` (no right check at all) had nothing to fix. `hasTypeRight()`, `hasPurgeRight()` and `creatableTypesForDomain()` all route through the private `hasRight()`, which checked only a bare `Session::haveRight($rights[$type], $bit)` — no notion of which `Domain` the caller meant. On a multi-entity install, a profile granted a per-type DNS write-back right (e.g. `domainmanager:dns_records_txt` CREATE) for one entity held it over every entity's zones — the silent-privilege-escalation, fail-*open* mirror of the `hasPurgeRight()` field-name bug fixed in `1.4.2`, which failed *closed* instead. Fixed by threading `$domains_id` through `hasRight()` (now requires it) and gating on `Session::haveAccessToEntity($domain->fields['entities_id'], $domain->fields['is_recursive'])` in addition to the profile-bit check — the same primitive `CommonDBTM::canViewItem()`/`canUpdateItem()` use internally, mirrored here since these per-type rights aren't itemtype-scoped GLPI rights `Domain::can()` itself resolves. Updated every call site: `onPreAdd()`/`onPreUpdate()`/`onPreDelete()`/`onPreRestore()` (domain id already in scope), `hasPurgeRight(DomainRecord $item)` (from `$item->fields['domains_id']`), and the two now-required-parameter public wrappers `hasTypeRight()`/`creatableTypesForDomain()`, updating both call sites in `DomainForm.php`. No behavior change on a single-entity install. Not yet verified live (requires a multi-entity install to confirm cross-entity denial in practice; see TESTING.md Phase 52).

### Features
- Phase 51 (ARCHITECTURE.md §15.2): credential-leak audit of `PluginLogger`'s two funnels. Grepped every `PluginLogger::activity()`/`error()` call site (55, across the three drivers, controllers and services) and every `DriverException`/`GuzzleException::getMessage()` path that reaches one. Finding: no live leak, by construction — all three drivers authenticate via a Guzzle `headers`/`auth` client option (never a request URI or body param), and Guzzle's own `RequestException::create()` builds its message only from the redacted request URI, method, and a truncated response-body summary, never the request headers or body. `PluginLogger::redact()` (added earlier, §3.6) is the residual guarantee, not the primary defense. Widened its regex to also catch `auth_code`/`credential(s)` keys and quoted JSON-style values (`"password":"x"`), documented the audit's conclusion in the method's own docblock so the next call site addition doesn't have to re-derive it.

- Phase 50 (ARCHITECTURE.md §11.17): doc-verified (no live provider account access, per explicit request) how each of the three drivers handles reading SRV/SOA/CAA zone records, since `ZoneRecord` has no typed sub-fields for them. IONOS's existing 2026-07-29 conclusion re-checked and unchanged. Cloudflare's SRV/CAA responses confirmed (via its current API reference) to carry a pre-serialized `content` string alongside the structured `data` object, so `CloudflareDriver`'s existing flat pass-through needed no change; SOA isn't a Cloudflare record type at all, so no SOA row can reach that driver's read loop. Dinahosting has no documented structured shape for SRV/SOA/CAA, and its reference client (`libdns/dinahosting`) treats SRV as an opaque flat value — `DinahostingDriver`'s existing generic fallback needed no change either. Read-path only; SRV/SOA/CAA remain write-disabled by design.

### Bugs
- `DnsRecordWriteback::duplicateNameError()`'s message read "A %1$s record named..." — for type A specifically, that's "A A record named...", an awkward double-article. Reworded to "A record of type %1$s named...". Verified live (per explicit user request to confirm this guard actually blocks and warns on a real duplicate, not just a synthetic unit check): attempting to add a 2nd "demo" A record on dev.gal, where an active one already exists, returned `add() === false` and queued the session message `[Domain Manager] A record of type A named "demo.dev.gal" already exists for this domain; Domain Manager does not allow duplicate records` — confirming the plugin-wide guard added in 1.4.2 already satisfies "block in advance, or at least warn" for genuine duplicates (an earlier same-session check against a *trashed* record found nothing, which is correct: the guard only compares against active, non-trashed rows).

## [1.4.2] - 2026-08-03
### Features
- `domainrecord_edit_panel.html.twig` no longer asks "This updates the record live at the DNS provider. Continue?" before a plain Save on a managed DNS record (per explicit user request) — only the delete/purge confirmation stays, plus GLPI's own native confirmation on restore.

### Bugs
- **A managed record's locked `name` field showed the raw stored value (an absolute FQDN, e.g. `"www.zzz.gal"`) instead of just the relative label** (found live 2026-08-03, reported by user for `www` under `zzz.gal`). Storage itself is correct and unchanged — every driver's `ZoneRecord.name` and `DnsRecordWriterInterface`'s contract are absolute FQDNs by design, matching GLPI core's own `DomainRecord::getDisplayName($domain, $name)`, which already strips the domain suffix for the "link a record" dropdown but was never applied to this field's own display. `DomainForm::injectDomainRecord()` now computes it via that same core helper and passes it to the template as `display_name`; the locked `name` input's displayed value is set to it client-side (cosmetic only — the field is locked either way, so this never changes what could be posted back). Verified live: `getDisplayName($zzzGalDomain, "www.zzz.gal")` returns `"www"`; apex (`"zzz.gal"`) returns `"@"`, GLPI core's own convention.
- **`DnsRecordWriteback::onPreUpdate()` never checked whether the record was trashed before trying to push the edit upstream.** Trashing a write-back-managed record already pushes a real `deleteRecord()` (§ `onPreDelete()`), so its remote copy is gone; saving a data/ttl edit on it while still trashed tried to push an update for a record that no longer exists at the provider (found live 2026-08-03, per explicit user report: "only 'synced' action should be restore"). Added an early `is_deleted` check that returns `false` (local-only edit, no push attempted) for a trashed record — the only write-back action a trashed record can still trigger is `onPreRestore()`. Verified live: a synthetic trashed `DomainRecord` hits the new guard and returns `false` before any API call, while a non-trashed one passes through it unaffected.
- **`DinahostingDriver::deleteByIdentity()` sent an absolute `hostname` param to every `Domain_Zone_DeleteType*` command, which the API rejects** with responseCode 2303 (`Param "hostname"/"ip" value doesn't exist`) even when that exact record is the one `findByIdentity()` resolves it to moments earlier — surfaced to the user as the misleading "Domain is not managed by this Dinahosting account" (that message is only accurate for domain-level 2303s; this driver conflated every occurrence of that code into one hardcoded message regardless of which command produced it). Confirmed directly against the live account with raw HTTP calls (bypassing our own exception mapping) for both A and TXT: the delete commands need the *relative* label back (`"xxx"`, not `"xxx.zzz.gal"`) — unlike `Domain_Zone_AddType*`, which is fine with the absolute form. Added `DinahostingDriver::relativeHostname()` (inverse of `qualifyHostname()`) and applied it to the outgoing `hostname` param only — `findByIdentity()` still receives the absolute form, since that's what `fetchZoneRecords()` normalizes every record's name to. Verified end-to-end through the actual plugin driver (not just raw HTTP): `createRecord()` then `deleteRecord()` on a disposable test record, followed by a `fetchZoneRecords()` re-check confirming it was actually gone from the zone. Apex delete for TXT/MX specifically remains unconfirmed (no existing apex record was safe to test-delete against a live zone), but uses the same `@` convention as A/AAAA/CNAME apex rather than guessing at a type-specific exception.
- `DinahostingDriver::deleteByIdentity()` sent A/AAAA/CNAME delete commands (`Domain_Zone_DeleteTypeA`/`AAAA`/`Cname`) with only `domain`+`hostname`, but Dinahosting's API requires the record's value too — confirmed live via "Required param \"ip\" is missing" (code 2003-2005) on `Domain_Zone_DeleteTypeA`. Now looks up the existing record via `findByIdentity()` unconditionally (previously gated to `type === 'TXT'`) and includes the type-appropriate value param (`ip` for A/AAAA, `destinationHostname` for CNAME, `value` for TXT), mirroring the field mapping already used by `createRecordRaw()`. If the record can no longer be found, the value param is omitted and the delete is attempted anyway so a genuine "not found" surfaces through the normal API error path instead of being masked.
- `createRecordRaw()`'s post-create `findByIdentity()` lookup can still fail to find a just-created record ("Dinahosting did not report the newly created record") if the `hostname` name form sent on create doesn't match the absolute-name form Dinahosting echoes back via `Domain_Zone_GetAll`. Added diagnostic logging (`PluginLogger::error()`) on this failure path that dumps the full zone's `type:name` list at the moment of failure.
- **Root cause of the above, confirmed live 2026-08-03**: `DinahostingDriver::fetchZoneRecords()` never normalized the raw hostname `Domain_Zone_GetAll` reports — which Dinahosting itself reports inconsistently by type (`@` for A/AAAA/CNAME apex, the bare zone name for TXT/MX apex, a bare relative label like `manel` for everything else), while every other write path works in absolute FQDNs (matching Cloudflare/IONOS and `DnsRecordWriterInterface`'s contract). This silently broke `findByIdentity()` for virtually every non-apex record, so a successful create was misreported as "Dinahosting did not report the newly created record," prompting user retries that created real duplicate records upstream (observed: two duplicate "manel" A records, after which further creates hard-failed with generic `code=2400 Command failed`). Added `DinahostingDriver::qualifyHostname()`, applied in `fetchZoneRecords()`, to normalize every reported hostname into the same absolute-FQDN convention used everywhere else.
- `DnsRecordWriteback::onPreAdd()`/`onPreRestore()` built the record's absolute name as `($name !== '' && $name !== '@') ? $name . '.' . $zoneName : $zoneName`. Two bugs in that one line: (1) it didn't treat `$name === $zoneName` as already-apex, which — combined with the `fetchZoneRecords()` bug above — doubled the zone name when restoring a trashed apex TXT record (`dev.gal` → `dev.gal.dev.gal`, hard-rejected by Dinahosting); (2) `onPreRestore()`'s `$name` comes from `$item->fields['name']`, a previously-stored record's name that is *already* an absolute FQDN (same value `onPreUpdate()` already passes through unmodified) — appending `$zoneName` to it a second time was wrong for every non-apex restore too. Extracted the apex-aware qualification into a shared `absoluteRecordName()` helper, used only where the input is genuinely a raw unqualified label (`onPreAdd()`'s user-submitted `name`); `onPreRestore()` now passes its already-absolute stored name straight through, unmodified, like `onPreUpdate()` does.

- **Migration for the above**: `ImportedRecord.remote_id` is set once at creation and never rewritten for already-synced, unchanged records (`RecordReconciler::createRecord()`), so any Dinahosting-managed DNS record imported before the `qualifyHostname()` fix above still carried a `remote_id` encoding the old, un-normalized hostname — breaking its delete/update/restore. Rather than requiring affected `DomainRecord`/`Domain` rows to be deleted and re-imported (unacceptable where they already carry ticket/contract/project links), added `DinahostingDriver::renormalizeRemoteId()` plus `Installer::renormalizeDinahostingRemoteIds()`: an idempotent migration, run on every install/upgrade like the rest of `Installer::install()`, that re-encodes just the `remote_id` column on the plugin's own ownership table for every Dinahosting-managed domain's records — no other row is touched. Verified live against the `glpi-claude` dev container (domain #9/dev.gal): `A|demo` → `A|demo.dev.gal`, `A|@` → `A|dev.gal`, `TXT|dev.gal` unchanged (already correct); re-running the migration twice more produced byte-identical `remote_id` values, confirming idempotency.
- **`RecordReconciler`'s local reconciliation writes had no guard against re-triggering `DnsRecordWriteback`'s own push-to-provider hooks**, only `Session::isCron()` — which a manually-triggered "Update now" sync (`SyncController`) never is. So every reconciler-driven `add()`/`update()`/`restore()`/`delete()` mirroring a record just read *from* the provider fired the matching `onPreAdd()`/`onPreUpdate()`/`onPreRestore()`/`onPreDelete()` hook, which tried to push that same record straight back *to* the provider — using its already-absolute name as if it were a raw user-typed label (found live 2026-08-03: `manel.dev.gal` became `manel.dev.gal.dev.gal` on a reconciler-driven add, hard-rejected by Dinahosting; also produced spurious "Dinahosting already has one or more TXT records" aborts on every sync). Added a synthetic `_domainmanager_sync` input flag (same convention as the existing `_domainmanager_proxied`) set on all four of `RecordReconciler`'s native mutation calls; each `DnsRecordWriteback` hook now bails out immediately when it sees that flag, since a reconciler-driven change is never a genuine user-initiated write with anything left to push upstream.

### Bugs (continued)
- `DnsRecordWriteback::hasPurgeRight()` read `$item->fields['type']` — not a real `DomainRecord` field; every other type lookup in this class reads `domainrecordtypes_id` — so it always resolved to no type and returned `false` unconditionally, hiding the Purge button on a trashed managed record even for a user holding the per-type PURGE right (found live 2026-08-03, reported by a `glpi`/`glpi` super-admin account with full rights). Fixed to read `domainrecordtypes_id` like everywhere else.

### Added
- **Plugin-wide, driver-independent duplicate-record guard** (per explicit user request, 2026-08-03, after the Dinahosting retry-storm above left real duplicate "manel" A records both upstream and in GLPI): `DnsRecordWriteback::duplicateNameError()` refuses a second non-trashed `DomainRecord` sharing `domains_id`+`domainrecordtypes_id`+`name` on any Domain Manager-tracked domain, regardless of which (if any) driver is configured. Called from `onPreAdd()`, `onPreUpdate()` (against the update's *effective* domain/type/name, falling back to the record's current stored values for whichever isn't part of a given update), and `onPreRestore()` (a restore recreating a duplicate is refused the same as a fresh create) — checked *before* each method's `_domainmanager_sync` bail-out, so it also catches a reconciler-driven sync attempting to mirror a genuine upstream duplicate locally. Deliberately not scoped to Dinahosting's own driver-specific `assertSingleRecordAtName()` guard (which exists only because that one API can't target a single record among same-name siblings) — per user decision, this is a hard rule for every driver, even ones that could technically support round-robin multi-record names; that's out of scope for Domain Manager.
  - **Follow-up bug, found live 2026-08-03**: `onPreAdd()`'s call compared `$item->input['name']` — the raw, still-unqualified label a user types into the add form (e.g. `"manel"`) — directly against stored `DomainRecord.name` values, which are always absolute FQDNs (e.g. `"manel.dev.gal"`, per `absoluteRecordName()`). The two never matched, so creating a genuine second "manel" A record sailed straight past this guard and was only rejected deeper in, by Dinahosting's own `assertSingleRecordAtName()`, with its less helpful driver-specific message ("...its API can only delete all of them at once..."). Fixed by qualifying the raw name against the domain's own zone name (fetched up front for this purpose) before comparing — the same transformation the actual push already applies.

## [1.4.0] - 2026-07-31
### Features
- Dinahosting driver now implements `DnsRecordWriterInterface` (write mode for A/AAAA/CNAME/TXT), matching the same manual write-back UX already available for IONOS and Cloudflare. Synthesized against Dinahosting's real per-type add/delete-only API (no update command, no per-record id, no client-settable TTL): `updateRecord()` is a delete-then-add, `remoteId` is a synthetic `type|name` token, and any name already holding more than one record of the same type is refused as unsafe to edit individually (Dinahosting's A/AAAA/CNAME delete has no value filter and would remove every sibling). See `ARCHITECTURE.md` §3.8.1.
- `DomainRecord`'s own edit form now structurally locks the record `name` field (moved out of the conditional write-back lock, always disabled like `domains_id`/`domainrecordtypes_id`/`date_creation`), since `DnsRecordWriteback::onPreUpdate()` never actually pushes a name change upstream — it always pushes the record's current DB name. Editing it previously looked possible but silently did nothing; the field-lock icon now covers `name`, `date_creation`, and `domains_id` consistently.

### Bugs
- A plugin-imported `DomainRecord`'s edit form kept showing the Purge button while it sat in the trash even when the user lacked the per-type PURGE right, so submitting it just bounced back with "Purging this record requires the 'Purge' right for its DNS record type." The button is now hidden client-side whenever `DnsRecordWriteback::hasPurgeRight()` is false; the server-side block in `LockEnforcer::blockRecordRemoval()` is unchanged (authoritative, still covers e.g. automatic actions).
- The lock icon on a managed Domain's registration/expiration date fields (and a locked DomainRecord's creation date) was silently missing: `fields_macros.html.twig`'s `dateField()`/`datetimeField()` builds its flatpickr wrapper with its own independently-random id, which never matches the outer `field()` macro's `label[for=...]` — so the icon-injection JS's `label[for=input.id]` lookup missed every date field. Both `domain_panel.html.twig` and `domainrecord_edit_panel.html.twig` now fall back to the enclosing `.form-field` row's label when the id-based lookup misses.

## [1.3.1] - 2026-07-31
### Features
- Raw-DB audit: confirmed no raw SQL exists outside the justified `CREATE TABLE` bootstrap in `Installer.php` (Migration has no schema-creation builder); replaced one manual existence-check query with the native `countElementsInTable()` helper.

## [1.3.0] - 2026-07-31
### Features
- Domain type dropdown (`domaintypes_id`) is now locked on a managed domain, joining name/registration/expiration date.
- Registration/expiration date locking now also covers a registrar driver that never reports one of those fields itself (e.g. IONOS), relying entirely on RDAP's gap-fill; backfilled retroactively for already-managed domains.
- "Locked by Domain Manager synchronization" icon changed from `ti-lock` to `ti-cloud-lock`, to stop it reading as GLPI's own native field-lock icon.
- Confirmation prompts added on "+ New record" and edit for write-back-managed DNS records (delete already had one).
- Domain record edit form: banner color/placement cleanup, proxy toggle now a solid icon in its own row.
- Records tab: proxied records show a cloud icon next to their name.
- Supplier "Domain Manager" tab: rebalanced two-column layout, one-line setup summary + link per driver.
- Documented that Dinahosting requires the account's super-admin credentials.
- "Purge DNS records" folded from one flat right into a PURGE bit on each per-type write-back right.
- Domain Manager panel collapses to one actionable line (instead of a wall of "Not configured" cells) when a domain has no API-backed registrar/DNS supplier.
- Cloudflare-proxied DNS records: proxy status now a default-visible, filterable column, plus an edit-form toggle.
- Two-way sync for Cloudflare's per-record comment.
- New Domain-level "Native" field (`is_glpi_created`), searchable, mirroring the existing DomainRecord field.
- Write gate added for DNS provider (source) conflicts — a DNS-supplier change no longer silently migrates ownership.
- Renamed several search options for shorter column labels ("Native", "Auth code", "Registrar Count", "Provider Count").
- Raised minimum supported PHP version to 8.3.
- Cloudflare permission docs updated to also require `Zone:DNS:Edit` for write-back (previously only listed read-side scopes).
- New `domainmanager:purge_records` right, and Domain/record type/creation date always disabled on a plugin-owned DomainRecord's edit form.
- Update-conflict reconciliation for record writes (store conflict, resolution screen to pick GLPI vs. provider value) — later removed in this same release (see Bugs).
- DNS record write-back per-type rights relabeled "Domain Record: X" (was "DNS record write-back: X").
- Check Connection can now detect a missing Cloudflare zone/DNS scope directly, instead of only failing on the next real sync.
- `CloudflareDriver` implements `DnsRecordWriterInterface` (create/update/delete/fetch record).
- Per-domain DNS write-editability state (`dns_write_status`/`dns_write_message`), learned from real writes only.
- Cloudflare DNS record write support — architecture design landed (docs only, this release).
- `DnsRecordWriteback` generalized beyond IONOS ahead of Cloudflare write support (was hardcoded to the IONOS driver constant).

### Bugs
- Restoring a trashed, write-back-managed DNS record from GLPI's native trash didn't actually recreate it at the provider.
- Deleting a plugin-owned DomainRecord as a user holding "unlock imported" silently skipped the upstream provider delete.
- Cloudflare read-path 401 and 403 were both surfaced as the same "check the API token" message, misleading for a 403 (missing scope, not a bad token).
- Cloudflare 401/403 responses were logged with no detail beyond the bare status code.
- Removed the "record update conflict" resolution feature — editing a managed record's data/TTL now always pushes straight to the provider (design clarification: once managed, GLPI's value is always authoritative).
- Removed Supplier list's combined "Domains" count column, which summed registrar+DNS roles into a misleading total.

## [1.3.0-alpha8] - 2026-07-30
### Bugs
- Cloudflare permission docs only listed the two read-side scopes, never mentioning `Zone:DNS:Edit` — write-back attempts permission-denied with no prior warning.

## [1.3.0-alpha7] - 2026-07-30
### Features
- New `domainmanager:purge_records` right, independent of per-type write-back DELETE rights.
- Domain, record type, and creation date now always disabled on a plugin-owned DomainRecord's edit form.

### Bugs
- Deleting a plugin-owned DomainRecord as a user holding "unlock imported" silently skipped the upstream provider delete.

## [1.3.0-alpha6] - 2026-07-30
### Features
- Phase 44: update-conflict reconciliation for record writes (store conflict, resolution screen).
- DNS record write-back per-type rights renamed to "Domain Record: X" (cosmetic).

## [1.3.0-alpha5] - 2026-07-30
### Bugs
- Check Connection couldn't detect the Cloudflare zone/DNS scope gap — a token could pass Check Connection and still 403 on every real sync.

## [1.3.0-alpha4] - 2026-07-30
### Bugs
- Cloudflare read-path 401 and 403 were both surfaced as the same "check the API token" message.

## [1.3.0-alpha3] - 2026-07-30
### Features
- `CloudflareDriver` implements `DnsRecordWriterInterface` (create/update/delete/fetch record).
- Per-domain DNS write-editability state (`dns_write_status`/`dns_write_message`).

## [1.3.0-alpha2] - 2026-07-30
### Features
- Cloudflare DNS record write support — architecture design (docs only).

### Bugs
- `DnsRecordWriteback` generalized beyond IONOS ahead of Cloudflare write support (was hardcoded to the IONOS driver constant, would have silently rejected Cloudflare writes).

## [1.3.0-alpha1] - 2026-07-30
### Bugs
- Cloudflare 401/403 responses were logged with no detail beyond the bare status code.

## [1.2.0-beta3] - 2026-07-29
### Features
- Custom write-back UI replaces native add controls; visible "goes live" warnings on edit/delete.
- GLPI's own generic "New Domain record" form now also warns it can push live.

### Bugs
- IONOS absolute-name construction for record create was backwards, rejecting every non-apex create.
- Custom add panel didn't return to the Records tab after create (redirected to the new record's own page instead).

## [1.2.0-beta2] - 2026-07-29
### Features
- Managed-domain indicator icon added to the item header on every tab.
- Native "Link a record"/"New Domain record" controls now hidden client-side when the domain is under IONOS write-back and the user holds no per-type CREATE right.

## [1.2.0-beta1] - 2026-07-29
### Features
- Phase 35 end-to-end verification (code + live check on the dev container) of the write-back feature built across 1.2.0-alpha1-4; no functional change.

## [1.2.0-alpha4] - 2026-07-29
### Features
- DNS record write-back redesigned around GLPI's native `DomainRecord` tab (item hooks), replacing the earlier controller/modal design.
- Four per-type write-back rights (A/AAAA/CNAME/TXT) replace one flat right.

## [1.2.0-alpha3] - 2026-07-29
### Features
- Controllers and end-to-end write path for DNS records (create/update/delete), gated by per-type rights, with live NS re-check and re-fetch-and-diff on update. No UI trigger yet.

## [1.2.0-alpha2] - 2026-07-29
### Features
- `DnsRecordWriterInterface` and a real IONOS implementation (create/update/delete/fetch, four writable types).

## [1.2.0-alpha1] - 2026-07-29
### Features
- Schema/rights groundwork for DNS record write-back to IONOS (no write path yet): `is_glpi_created` column, new right, new search option.

## [1.1.0] - 2026-07-28
### Features
- RDAP added as a fallback data source: fills registration/expiration dates, last-changed date, pending-delete/transfer flags, and DNSSEC when the registrar driver doesn't report them.
- Registrar details block now surfaces RDAP-only fields (pending delete/transfer, DNSSEC fallback).
- RDAP cross-check sub-panel on the Domain form (registrar-of-record and nameserver mismatch badges).
- Config page shows RDAP enrichment backlog/last-run status.
- Richer per-entity and per-registrar-supplier breakdown in automatic-action logs.
- RDAP "last changed"/"last transfer" dates surfaced on the Domain form.
- Transfer lock/domain lock now also get RDAP gap-fill (DNSSEC already had it).
- Four new Domain search options for the RDAP-only fields.
- Dropped the separate `rdap_*` shadow columns — RDAP now fills the existing registrar-metadata columns directly.
- Supplier's Domain Manager tab now shows the RDAP-reported registrar of record.

### Bugs
- RDAP cross-check falsely flagged a match as a mismatch whenever RDAP's registrar name carried a legal suffix, or the nameserver set was just reordered.

## [1.0.0] - 2026-07-28
### Bugs
- Reimporting a previously trashed domain created a duplicate item instead of restoring the original, orphaning its linked tickets/contracts/infocom.

## [0.12.0] - 2026-07-28
### Features
- Search options added for the remaining registrar metadata fields (WHOIS privacy, transfer/domain lock, auto-renew, DNSSEC).

### Bugs
- "Punycode name" search option matched every domain, not just genuine IDN ones.

## [0.11.9] - 2026-07-28
### Features
- Redesigned the Domain form's identity header (IDN badge, copyable Punycode form, Visit/WHOIS buttons).
- New `name_ascii` column + searchable "Punycode name" search option.

## [0.11.8] - 2026-07-28
### Features
- Moved the Punycode/ASCII form into a clickable identity row above the status table.

## [0.11.7] - 2026-07-28
### Features
- Domain form panel visual polish to match the Supplier tab's own panel conventions.

## [0.11.6] - 2026-07-27
### Features
- Five new searchable/sortable Registrar/DNS Provider fields added to the native Supplier search page.

## [0.11.5] - 2026-07-27
### Features
- Internal refactor: extracted status label/class logic into its own `DomainStatusResolver` service. No behavior change.

## [0.11.4] - 2026-07-27
### Features
- Renamed the "NS provider" column to "DNS Provider" for consistency.

## [0.11.3] - 2026-07-27
### Features
- Reworded the "Provider unknown" status label to "Unknown provider".

### Bugs
- Dinahosting-hosted domains using an undocumented nameserver pattern (`*.gestiondecuenta.com`) showed "Unknown provider".

## [0.11.2] - 2026-07-27
### Features
- Renamed the DNS/NS provider column back to "NS provider" (one consistent term).

### Bugs
- Reverted 0.11.1's NS/DNS provider column badge change — confirmed a regression by live testing.

## [0.11.1] - 2026-07-27
### Features
- Unified the DNS/NS provider column's status badge with the Registrar column's own vocabulary.

### Bugs
- Registrar sync status showed "Not yet checked" when viewed from a different supplier's tab than the domain's actual registrar.

## [0.11.0] - 2026-07-27
### Features
- Four new filterable Domain search options: NS Provider, Registrar sync status, DNS sync status, Last sync.

## [0.10.1] - 2026-07-27
### Bugs
- A recurring "invalid search options" warning wasn't fully fixed by 0.10.0 — the stale reference was also written back into the session on every request, not just saved searches.

## [0.10.0] - 2026-07-27
### Features
- Registrar field is now lock-protected once a domain has a confirmed registrar match, with a new "Unlink registrar" action.
- New "Managed" search option on Domain.
- DomainRecord field locking is now conditional per-sync, matching how Domain's own locking already worked.
- Search UI cleanup: dropped two dummy search options, deduplicated one against a native option.

### Bugs
- Crash on the Domains list/search referencing a column whose migration silently never ran (missing version bump).
- Crash during the plugin upgrade itself (raw update ran before its own queued column migration).
- Stale saved-search reference to a removed search option kept re-triggering a warning.

## [0.9.0] - 2026-07-27
### Features
- "Domain type to apply to imported domains" is now a configurable setting (Setup > General) instead of hardcoded.

## [0.8.0] - 2026-07-27
### Features
- Nameserver detection added for RaiolaNetworks and LucusHost.

## [0.7.0] - 2026-07-27
### Features
- Nameserver detection added for Strato and Arsys.

### Bugs
- IONOS nameserver detection wildcard was silently matching every sibling United Internet brand on the same shared DNS platform.

## [0.6.0] - 2026-07-27
### Features
- Bulk-import domain discovery added for Dinahosting and Cloudflare registrar accounts.
- IDN/Punycode handling added across NS detection, registrar sync, and DNS sync.
- Registrar reassignment now shows a distinct "not yet verified" badge instead of a stale prior status.
- `CloudflareDriver` migrated off the deprecated Registrar Domains API ahead of its EOL.
- "Update Now" moved into the main button row instead of a separate corner button.
- Entity column added to the Supplier tab's "Domains" list.

### Bugs
- IONOS registrar domain lookup failed for IDN domains via the Domains API's `name` filter — now matches client-side instead.

## [0.5.0] - 2026-07-22
### Features
- Bulk-import of undiscovered IONOS registrar domains, with registrar-mismatch detection and one-click reassignment.
- Richer registrar domain metadata surfaced (WHOIS privacy, locks, auto-renew, DNSSEC, EPP auth code on file).
- Searchable "Proxy status" field added on Domain Records for Cloudflare-managed domains.

### Bugs
- A native PHP `true`/`false` passed into a `CommonDBTM` update/add input was silently persisted as `NULL` instead of `1`/`0`.

## [0.4.0] - 2026-07-21
### Features
- Driver dropdown now excludes a driver already claimed by another supplier, and sorts alphabetically.
- Cloudflare now requires an Account ID and only supports account-scoped API tokens.
- A vanished DNS record is now soft-deleted into GLPI's native trash bin instead of flagged with a comment marker.
- New "Managed" search option on native Domain Records.
- Recommendation banner added for unverified registrar-linked domains, linking to a pre-filtered search.
- New "Sync now (Domain Manager)" massive action on Domain.
- Tab count badge added to the Supplier's "Domain Manager" tab.
- Check Connection button now disables itself when the supplier is inactive.
- Real `IonosDriver` registrar/lifecycle implementation (`fetchLifecycle()`).
- Added `SyncLogger::activity()` logging a plain fetch-succeeded line after each registrar/DNS fetch.

### Bugs
- The API driver dropdown could assign the same driver to more than one supplier.
- Supplier tab's "Domains" list undercounted registrar-linked domains (required a prior sync to even appear).
- `SyncEngine` never actually resolved the registrar from Infocom live, only from a mirror that could go stale.
- An inactive supplier's stored credentials could still be used for connection tests, syncs, and cron.
- Dinahosting misreported "authentication failed" for a domain that isn't authorized under the account, even with correct credentials.
- A genuinely valid Cloudflare Account API Token still failed Check Connection (wrong verify endpoint for account-owned tokens).

## [0.3.2] - 2026-07-21
### Bugs
- Critical: any connection test silently wiped the supplier's stored API credentials.
- `api_credentials` wasn't registered for GLPI's own encryption-key-rotation mechanism, found alongside the above.

## [0.3.1] - 2026-07-21
### Features
- Unified all four Domain Manager panels on GLPI's native ribbon-banner convention.
- Domain form's "Domain Manager" panel redesigned as a one-row native table.

### Bugs
- Panel fetch URLs used raw string concatenation instead of `path()`, breaking on a subdirectory install.

## [0.3.0] - 2026-07-21
### Features
- Real `DinahostingDriver` implementation (registrar lifecycle, DNS zone records, connection test).
- Real `IonosDriver` DNS implementation (`fetchZoneRecords()`, connection test).
- Read-only "Domains" list added to the Supplier's Domain Manager tab.
- Check Connection now shows one combined status badge instead of a per-capability breakdown.
- Registrar field is now a read-only mirror of Infocom's native Supplier field, not an independent plugin dropdown.

### Bugs
- "Check Connection" and "Update Now" never worked from a real browser click — both built a broken URL via `path()` inside a legacy-rendered template.
- Plugin log files never appeared in Setup > Logs on a fresh install, since nothing had written to them yet.

## [0.2.0] - 2026-07-20
### Features
- `search-options-registry.json` added, seeded with this plugin's two search-option IDs.
- Ten more nameserver-detection providers added to the registry.

### Bugs
- Native History entries rendered with a blank "field" column.

## [0.1.2] - 2026-07-19
### Features
- Native GLPI History audit trail added for supplier credential and registrar-assignment changes.
- On-demand connection diagnostics ("Check Connection") added, testing live form values against each driver's capabilities.
- All plugin logging consolidated onto two files (`domainmanager.log`/`domainmanager-errors.log`).

### Bugs
- `PluginLogger` silently wrote nothing on a stock install (GLPI's file-logging config wasn't enabled by default).
- A failure while persisting connection-test results could break the whole response with no client-side detail.

## [0.1.1] - 2026-07-19
Version bump only — no functional changes; superseded by 0.1.2 before any separate release.

## [0.1.0] - 2026-07-19
### Features
- Every Domain Manager mention now carries the Tabler "world-cog" icon.
- Nameserver detection registry seeded with eight providers (AWS Route 53, Google Cloud DNS, Azure DNS, GoDaddy, OVHcloud, DigitalOcean, Linode, Vercel).
- Domain form panel added: registrar dropdown, detected DNS provider, status card, unsupported/unknown-provider warning, cosmetic field-lock JS.
- "Update Now" endpoint added, refreshing the panel in place.
- `DomainSync` automatic action added (daily, batch of 20, per-domain error isolation).
- Driver contracts (`RegistrarDriverInterface`, `DnsPipelineInterface`), validated DTOs, `DriverFactory`, full `CloudflareDriver`, IONOS/Dinahosting stubs.
- `SyncEngine` split pipeline (isolated registrar/DNS legs), `NsResolver`, `RecordReconciler`, `SyncLogger`.
- `LockEnforcer` added — synced fields and imported records shielded server-side without the unlock right.
- Itemtypes added for the plugin's own tables (`SupplierConfig`, `DomainState`, `ImportedRecord`, `ImportLock`).
- "Domain Manager" tab added on suppliers (driver select + per-driver encrypted credential fields).
- `resources/ns-providers.json` NS-host → provider registry with wildcard matcher.
- Supplier purge cascade added (configuration row removed, sync states detached).
- Initial plugin skeleton for GLPI 11.0.x.
- Installer creates the plugin tables, seeds the "Internet Domain" domain type and A/AAAA/CNAME/MX/NS/TXT record types.
- `domainmanager:unlock_imported` profile right added, with a rights matrix on a new "Domain Manager" profile tab.
- Residue-free uninstall (drops tables, unregisters the automatic action, deletes profile rights and display preferences).

### Bugs
- Supplier "Domain Manager" tab was gated by the wrong right, so it never appeared for regular profiles.
- Registrar supplier changes were lost when no other domain field was modified in the same save.
- "Update Now" URL was built from a deprecated core method, logging a deprecation on every render.
