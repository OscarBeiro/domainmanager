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

## [Unreleased] (1.4.2-beta9)
### Bugs
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
