# Changelog for Domain Manager

All notable changes to this project will be documented in this file, one entry per real
release. Pre-release (alpha/beta) detail and the full unabridged history live in
`CHANGELOG-dev.md`.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.3.0] - 2026-07-31
- Domain type, registration date, and expiration date are now locked on managed domains, matching the existing name lock. Fixed the type dropdown not visually greying out when locked, and closed a gap where a registration date sourced only from RDAP (not the registrar driver) wasn't locked at all.

## [1.1.0] - 2026-07-28
- Added RDAP as a fallback data source to fill in registrar details a domain's own driver doesn't report (registration/expiration dates, last changed/transfer dates, pending delete/transfer flags, DNSSEC), plus a registrar/nameserver cross-check panel on the Domain form.

## [1.0.0] - 2026-07-28
- Fixed: reimporting a previously trashed domain created a duplicate item instead of restoring the original, orphaning its linked tickets/contracts.

## [0.12.0] - 2026-07-28
- Added search options for the remaining registrar metadata fields (WHOIS privacy, transfer/domain lock, auto-renew, DNSSEC). Fixed the "Punycode name" search option matching every domain instead of only genuine IDN ones.

## [0.11.9] - 2026-07-28
- Redesigned the Domain form's identity header (IDN badge, copyable Punycode form, Visit/WHOIS buttons) and added a searchable "Punycode name" field.

## [0.11.8] - 2026-07-28
- Moved the Punycode/ASCII form into a clickable identity row above the status table.

## [0.11.7] - 2026-07-28
- Visual polish of the Domain form's "Domain Manager" panel to match the Supplier tab's own conventions.

## [0.11.6] - 2026-07-27
- Added searchable Registrar/DNS Provider domain lists and counts directly on the native Supplier search page.

## [0.11.5] - 2026-07-27
- Internal refactor: extracted status label/class logic into its own `DomainStatusResolver` service. No behavior change.

## [0.11.4] - 2026-07-27
- Renamed the "NS provider" column to "DNS Provider" for consistency.

## [0.11.3] - 2026-07-27
- Fixed Dinahosting-hosted domains using an undocumented nameserver pattern showing as "Unknown provider." Reworded the "Provider unknown" status label to "Unknown provider."

## [0.11.2] - 2026-07-27
- Reverted a regression from 0.11.1 that made the DNS/NS provider column show a worse-looking status than before.

## [0.11.1] - 2026-07-27
- Fixed the Registrar sync status showing "Not yet checked" when viewed from a different supplier's tab than the domain's actual registrar. Unified the DNS/NS provider column's status vocabulary with the Registrar column's.

## [0.11.0] - 2026-07-27
- Added four new filterable Domain search options: NS Provider, Registrar sync status, DNS sync status, Last sync.

## [0.10.1] - 2026-07-27
- Fixed a recurring "invalid search options" warning that a prior fix didn't fully resolve (the stale reference was also being written back into the session on every request, not just in saved searches).

## [0.10.0] - 2026-07-27
- Added: the Registrar field is now lock-protected once a domain has a confirmed registrar match, with a new "Unlink registrar" action; added a "Managed" search option on Domain. Fixed two upgrade-path crashes and a stale-search-option warning introduced by this change.

## [0.9.0] - 2026-07-27
- Made the domain type applied to imported domains a configurable setting (Setup > General) instead of hardcoded.

## [0.8.0] - 2026-07-27
- Added nameserver detection for RaiolaNetworks and LucusHost.

## [0.7.0] - 2026-07-27
- Added nameserver detection for Strato and Arsys. Fixed a false-positive IONOS detection that was silently matching every sibling United Internet brand sharing the same DNS platform.

## [0.6.0] - 2026-07-27
- Added bulk-import domain discovery for Dinahosting and Cloudflare registrar accounts, IDN/Punycode support across NS detection and sync, and a "registrar changed, not yet verified" badge. Migrated Cloudflare off its deprecated Registrar Domains API. Moved "Update Now" into the main button row and added an Entity column to the Supplier's domains list.

## [0.5.0] - 2026-07-22
- Added bulk-import of undiscovered IONOS registrar domains, richer registrar metadata (WHOIS privacy, locks, auto-renew, DNSSEC, EPP auth code on file), and a searchable Cloudflare proxy-status field on DNS records.

## [0.4.0] - 2026-07-21
- Fixed several Supplier/sync bugs found live: duplicate driver assignment across suppliers, an undercounted "Domains" list, a registrar mirror that could go stale, inactive suppliers still being used for API calls, and a misleading Dinahosting authorization error. Changed: Cloudflare now requires an Account ID and only supports account-scoped API tokens; a vanished DNS record is now soft-deleted into GLPI's native trash instead of flagged with a comment marker.

## [0.3.2] - 2026-07-21
- Fixed a critical bug where any connection test silently wiped the supplier's stored API credentials.

## [0.3.1] - 2026-07-21
- Unified all Domain Manager panels on GLPI's native ribbon-banner convention.

## [0.3.0] - 2026-07-21
- Fixed: "Check Connection" and "Update Now" never actually worked from a real browser click (a template routing bug), and plugin log files never appeared until first triggered. Added real Dinahosting and IONOS DNS driver implementations, and a read-only "Domains" list on the Supplier tab.

## [0.2.0] - 2026-07-20
- Fixed native History entries rendering with a blank "field" column. Added `search-options-registry.json` and ten more nameserver-detection providers.

## [0.1.2] - 2026-07-19
- Fixed plugin logging silently writing nothing on a stock install. Added native History audit trail, on-demand connection diagnostics ("Check Connection"), and consolidated logging into two files.

## [0.1.1] - 2026-07-19
- Version bump only — no functional changes; superseded by 0.1.2 before any separate release.

## [0.1.0] - 2026-07-19
- Initial release: supplier API credential storage, sync engine (registrar lifecycle + DNS zone records) for Cloudflare/IONOS/Dinahosting, nameserver-provider detection registry, Domain form status panel, daily automatic sync action, and field locking for synced domains/records.
