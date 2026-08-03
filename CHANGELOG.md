# Changelog for Domain Manager

All notable changes to this project will be documented in this file, one entry per real
release. Pre-release (alpha/beta) detail and the full unabridged history live in
`CHANGELOG-dev.md`.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Entries are grouped into **Features** and **Bugs**, one line each.

## [Unreleased]
### Features
- Domain Manager now refuses to create or restore a DNS record that would duplicate an existing one (same type and name) on any managed domain, regardless of which DNS provider is configured, with a clear message explaining why.
- Saving a change to a managed DNS record no longer asks for confirmation first; deleting/purging one still does.

### Bugs
- Fixed Dinahosting DNS record deletion (A/AAAA/CNAME) failing with a "required param missing" error.
- Fixed Dinahosting DNS record creation being misreported as failed (and, on retry, sometimes creating duplicate records), and fixed restoring a deleted DNS record from the trash failing for Dinahosting-managed domains. Existing Dinahosting-managed DNS records are automatically repaired on upgrade — no data is deleted or re-imported, so any linked tickets, contracts, or projects are unaffected.
- Fixed running "Update now" on a Dinahosting-managed domain spuriously trying to re-push every synced DNS record back to Dinahosting, which could fail the sync and, in some cases, corrupt the record name being pushed.
- Fixed the Purge button never appearing on a trashed managed DNS record's edit page, even for a user holding the right to purge it.
- Fixed deleting a Dinahosting-managed DNS record failing with "Domain is not managed by this Dinahosting account", even when the domain and record were both valid.
- Fixed saving an edit to a trashed managed DNS record trying to push the change to the provider, even though a trashed record has already been removed from the provider and can only be recreated by restoring it.
- Fixed a managed DNS record's locked Name field showing the domain name twice (e.g. "www.example.com" instead of "www").

## [1.4.1] - 2026-08-01
### Features
- Shortened the supplier tab's "Check Connection" and "Import Domains" button labels to "Test" and "Import" for a more compact toolbar on smaller screens.

## [1.4.0] - 2026-07-31
### Features
- Dinahosting driver now supports manual DNS record write-back (A/AAAA/CNAME/TXT), matching IONOS and Cloudflare.
- A DomainRecord's `name` field is now structurally locked on its edit form, since editing it never actually pushed a change upstream.

### Bugs
- The Purge button on a plugin-imported DomainRecord's trash view is now hidden for users lacking the per-type PURGE right, instead of appearing but silently bouncing back an error.
- Fixed a missing lock icon on managed Domain/DomainRecord date fields.

## [1.3.1] - 2026-07-31
### Features
- Installer's display-preference seeding now uses the native `countElementsInTable()` helper instead of a manual query for its existence check (no behavior change).

## [1.3.0] - 2026-07-31
Note: `1.2.0` was never cut as a real release (only its alpha/beta line exists — see
`CHANGELOG-dev.md`), so this entry also covers everything shipped since `1.1.0`, including the
entire DNS record write-back feature.

### Features
- Manual DNS record write-back to IONOS and Cloudflare (create/edit/delete a DNS record from GLPI, pushed live to the provider), gated by new per-type rights (A/AAAA/CNAME/TXT).
- Custom write-back UI replaces native record-add controls on a write-back-managed domain; visible "this goes live" warnings before creating, editing, or deleting such a record.
- Managed-domain indicator icon added to the item header on every tab.
- Domain type, registration date, and expiration date are now locked on a managed domain, matching the existing name lock; backfilled for already-managed domains.
- Cloudflare-proxied DNS records: proxy status is now visible, filterable, and editable, with two-way comment sync.
- Domain-level "Native" field, mirroring the existing DomainRecord field.
- Write gate added for DNS provider (source) conflicts — a DNS-supplier change no longer silently migrates ownership.
- "Purge DNS records" folded from one flat right into a per-type PURGE bit.
- Domain Manager panel collapses to one actionable line for a domain with no API-backed registrar/DNS supplier.
- Supplier "Domain Manager" tab rebalanced, with setup guidance for every driver.
- Confirmation prompts added on every DNS record write-back action (create/edit/delete).
- Raised minimum supported PHP version to 8.3.

### Bugs
- Cloudflare permission docs were missing the `Zone:DNS:Edit` scope required for write-back.
- Deleting a plugin-owned DomainRecord as a user holding "unlock imported" silently skipped the upstream provider delete.
- Cloudflare 401 and 403 responses were both surfaced as the same misleading "check the API token" message.
- Check Connection couldn't detect a missing Cloudflare zone/DNS scope — a token could pass and still fail on every real sync.
- IONOS record creation used a backwards absolute-name construction, rejecting every non-apex create.
- Restoring a trashed, write-back-managed DNS record didn't actually recreate it at the provider.
- Removed the "record update conflict" resolution feature added earlier in this line — once managed, GLPI's value is always authoritative, so an edit now always pushes straight through instead of asking the user to pick a side.
- Removed the Supplier list's combined "Domains" count column, which summed registrar+DNS roles into a misleading total.

## [1.1.0] - 2026-07-28
### Features
- RDAP added as a fallback data source: fills registration/expiration dates, last-changed date, pending-delete/transfer flags, and DNSSEC when the registrar driver doesn't report them.
- Registrar/nameserver cross-check panel added to the Domain form.
- Config page shows RDAP enrichment backlog/last-run status.

### Bugs
- RDAP cross-check falsely flagged a match as a mismatch whenever the registrar name carried a legal suffix, or the nameserver set was just reordered.

## [1.0.0] - 2026-07-28
### Bugs
- Reimporting a previously trashed domain created a duplicate item instead of restoring the original, orphaning its linked tickets/contracts.

## [0.12.0] - 2026-07-28
### Features
- Search options added for the remaining registrar metadata fields (WHOIS privacy, transfer/domain lock, auto-renew, DNSSEC).

### Bugs
- "Punycode name" search option matched every domain, not just genuine IDN ones.

## [0.11.9] - 2026-07-28
### Features
- Redesigned the Domain form's identity header (IDN badge, copyable Punycode form, Visit/WHOIS buttons).
- New searchable "Punycode name" field.

## [0.11.8] - 2026-07-28
### Features
- Moved the Punycode/ASCII form into a clickable identity row above the status table.

## [0.11.7] - 2026-07-28
### Features
- Domain form panel visual polish to match the Supplier tab's own conventions.

## [0.11.6] - 2026-07-27
### Features
- Added searchable Registrar/DNS Provider domain lists and counts on the native Supplier search page.

## [0.11.5] - 2026-07-27
### Features
- Internal refactor: extracted status label/class logic into its own service. No behavior change.

## [0.11.4] - 2026-07-27
### Features
- Renamed the "NS provider" column to "DNS Provider" for consistency.

## [0.11.3] - 2026-07-27
### Features
- Reworded the "Provider unknown" status label to "Unknown provider".

### Bugs
- Dinahosting-hosted domains using an undocumented nameserver pattern showed "Unknown provider".

## [0.11.2] - 2026-07-27
### Bugs
- Reverted a regression from 0.11.1 that made the DNS/NS provider column show a worse-looking status than before.

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
- A recurring "invalid search options" warning wasn't fully resolved by 0.10.0.

## [0.10.0] - 2026-07-27
### Features
- Registrar field is now lock-protected once a domain has a confirmed registrar match, with a new "Unlink registrar" action.
- New "Managed" search option on Domain.

### Bugs
- Two crashes on the upgrade path (missing version bump, unbuffered migration order) and a stale-search-option warning, all introduced by this same change.

## [0.9.0] - 2026-07-27
### Features
- Made the domain type applied to imported domains a configurable setting (Setup > General) instead of hardcoded.

## [0.8.0] - 2026-07-27
### Features
- Added nameserver detection for RaiolaNetworks and LucusHost.

## [0.7.0] - 2026-07-27
### Features
- Added nameserver detection for Strato and Arsys.

### Bugs
- A false-positive IONOS detection was silently matching every sibling United Internet brand sharing the same DNS platform.

## [0.6.0] - 2026-07-27
### Features
- Added bulk-import domain discovery for Dinahosting and Cloudflare registrar accounts.
- Added IDN/Punycode support across NS detection and sync.
- Migrated Cloudflare off its deprecated Registrar Domains API ahead of its EOL.

### Bugs
- IONOS registrar domain lookup failed for IDN domains via a name filter — now matches client-side instead.

## [0.5.0] - 2026-07-22
### Features
- Added bulk-import of undiscovered IONOS registrar domains.
- Added richer registrar metadata (WHOIS privacy, locks, auto-renew, DNSSEC, EPP auth code on file).
- Added a searchable Cloudflare proxy-status field on DNS records.

### Bugs
- A native PHP `true`/`false` value was silently persisted as `NULL` instead of `1`/`0`.

## [0.4.0] - 2026-07-21
### Features
- Cloudflare now requires an Account ID and only supports account-scoped API tokens.
- A vanished DNS record is now soft-deleted into GLPI's native trash instead of flagged with a comment marker.
- Added a "Sync now" massive action and a recommendation banner for unverified registrar-linked domains.

### Bugs
- Duplicate driver assignment across suppliers, an undercounted "Domains" list, a registrar mirror that could go stale, inactive suppliers still usable for API calls, and a misleading Dinahosting authorization error — all fixed.
- A valid Cloudflare Account API Token still failed Check Connection due to a wrong verify endpoint.

## [0.3.2] - 2026-07-21
### Bugs
- Critical: any connection test silently wiped the supplier's stored API credentials.

## [0.3.1] - 2026-07-21
### Features
- Unified all Domain Manager panels on GLPI's native ribbon-banner convention.

### Bugs
- Panel fetch URLs broke on a subdirectory install.

## [0.3.0] - 2026-07-21
### Features
- Added real Dinahosting and IONOS DNS driver implementations.
- Added a read-only "Domains" list on the Supplier tab.

### Bugs
- "Check Connection" and "Update Now" never worked from a real browser click.
- Plugin log files never appeared until first triggered.

## [0.2.0] - 2026-07-20
### Features
- Added `search-options-registry.json` and ten more nameserver-detection providers.

### Bugs
- Native History entries rendered with a blank "field" column.

## [0.1.2] - 2026-07-19
### Features
- Added native History audit trail, on-demand connection diagnostics ("Check Connection"), and consolidated logging into two files.

### Bugs
- Plugin logging silently wrote nothing on a stock install.

## [0.1.1] - 2026-07-19
Version bump only — no functional changes; superseded by 0.1.2 before any separate release.

## [0.1.0] - 2026-07-19
### Features
- Initial release: supplier API credential storage, sync engine (registrar lifecycle + DNS zone records) for Cloudflare/IONOS/Dinahosting, nameserver-provider detection registry, Domain form status panel, daily automatic sync action, and field locking for synced domains/records.

### Bugs
- Supplier "Domain Manager" tab was gated by the wrong right, so it never appeared for regular profiles.
- Registrar supplier changes were lost when no other domain field was modified in the same save.
