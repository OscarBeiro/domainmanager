# Changelog for Domain Manager

All notable changes to this project will be documented in this file, one dated header per
version bump (pre-release included) — the full development history. See `CHANGELOG.md` for the
trimmed, real-releases-only changelog.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries are grouped into **Features** (new capability, UI/UX change, refactor, doc/config
change) and **Bugs** (something that was actually broken, fixed) — one line each.

## [Unreleased]

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
