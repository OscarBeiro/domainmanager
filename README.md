# Domain Manager

GLPI plugin that inventories domain lifecycles (registrar pipeline) and DNS zone records (DNS provider pipeline), with drivers for Cloudflare, IONOS and Dinahosting, plus auto-detection of many more DNS providers from NS records.

![Version](https://img.shields.io/github/v/release/TICGAL-GLPI-Plugins/domainmanager)
![License](https://img.shields.io/github/license/TICGAL-GLPI-Plugins/domainmanager)
![Issues](https://img.shields.io/github/issues/TICGAL-GLPI-Plugins/domainmanager)
![Pull Requests](https://img.shields.io/github/issues-pr/TICGAL-GLPI-Plugins/domainmanager)
![Last Commit](https://img.shields.io/github/last-commit/TICGAL-GLPI-Plugins/domainmanager)
![Project Status](https://img.shields.io/badge/status-active-brightgreen)

Requires **GLPI 11.0.x**.

## Features
- **Domain Manager** tab on each supplier to select an API driver (Cloudflare, IONOS, Dinahosting) and store its credentials encrypted (GLPIKey); secrets are never echoed back to the browser.
- **Check Connection** validates stored credentials against the real provider API before any sync runs — for Cloudflare this includes a follow-up probe that catches a token which is valid but not actually scoped for zone/DNS access, a gap a plain token-verify call would miss.
- Automatic action (`DomainSync`, every 30 minutes by default, tunable in *Setup → Automatic actions*) that synchronizes domain lifecycle data (registration/expiry/status) and DNS zone records from each configured supplier.
- Versioned NS→provider registry (`resources/ns-providers.json`, contributions welcome) auto-detects a domain's DNS provider from its NS records, even for providers with no configured driver — see the detected-providers list below.
- **DNS record write-back** for Cloudflare and IONOS: create, update and delete DNS records for a domain directly from its GLPI form when the DNS provider is one of these two and the domain is recognized as write-capable. Per-domain editability (`manual` / `managed_readonly` / `managed_editable`) is learned only from real write attempts, never assumed. Native GLPI add/edit/delete controls are hidden/guarded on write-capable domains and replaced with plugin-branded, rights-scoped equivalents; every write is also recorded in GLPI's native History tab.
- Per-type rights (A/AAAA/CNAME/TXT × Create/Update/Delete) gate who can write DNS records, managed from a **Domain Manager** tab on each profile alongside the existing *Unlock imported domain data* right.
- Clean install/uninstall from the GLPI UI or `bin/console glpi:plugin:install|uninstall` — uninstall leaves no plugin residue while keeping native inventory data.

## Installation
1. Copy this directory to `plugins/domainmanager` of your GLPI 11 instance.
2. Install and enable it from *Setup → Plugins* (or `php bin/console glpi:plugin:install domainmanager && php bin/console glpi:plugin:activate domainmanager`).

## Configuration
- Grant or revoke the *Unlock imported domain data* right and the per-type DNS record rights in the **Domain Manager** tab of each profile (granted by default to profiles with *config* UPDATE).
- On each Supplier, use the **Domain Manager** tab to pick a driver (Cloudflare, IONOS or Dinahosting), enter its credentials, and run *Check Connection* before enabling sync.
- Tune the `DomainSync` automatic action's frequency from *Setup → Automatic actions* like any other GLPI cron task.

### Cloudflare
Requires an **Account API Token** (Manage Account → API Tokens, not a personal/My Profile token) plus its Account ID. Grant `Zone:Zone:Read` and `Zone:DNS:Read` — both are required just to import DNS records — and add `Zone:DNS:Edit` if you also want this domain's records editable from GLPI (write-back). `Zone:Zone:Edit` is never needed; the plugin only ever writes DNS records, not zone settings. The Account ID is shown on the account's Overview page (API section) or via "Copy account ID" from the account menu.

### IONOS
Requires an **API Key** and **API Secret** from the IONOS Cloud panel (Management → API Keys). Both DNS zone sync/write-back and registrar lifecycle data use the same key/secret pair — no separate scoping is available.

### Dinahosting
Requires the account's plain **username and password** — Dinahosting has no scoped API token, so the credentials stored here are the same ones used to log into the control panel. **The account must be the super-admin account**: a sub-user or domain-limited account cannot authenticate against the API at all. Because there's no way to scope these credentials down, treat them with the same care as full control-panel access — consider a dedicated Dinahosting account if you'd rather not store super-admin credentials for a shared/reseller account.

## Supported registrars and DNS providers

| Provider | Registrar (lifecycle) | DNS sync (read) | DNS write-back |
|---|---|---|---|
| Cloudflare | Yes | Yes | Yes |
| IONOS | Yes | Yes | Yes |
| Dinahosting | Yes | Yes | Yes |

Only these three have a configurable driver (credentials + Check Connection + sync). Any domain hosted elsewhere is still inventoried, and its DNS provider is auto-detected from its NS records against the versioned registry below — it just isn't synced or write-capable until a matching driver exists.

## Detected DNS providers (NS-pattern registry)

In addition to the three drivers above, `resources/ns-providers.json` recognizes NS records from:

AWS Route 53, Google Cloud DNS, Azure DNS, GoDaddy, OVHcloud, DigitalOcean, Linode (Akamai), Vercel, Gandi, Namecheap, Hetzner, Squarespace, Wix, Hostinger, Porkbun, cdmon, one.com, NS1 (IBM NS1 Connect), bunny.net (Bunny DNS), Strato, Arsys, RaiolaNetworks, LucusHost.

Contributions to this registry (new providers/patterns) are welcome via PR.

## Use
Add a Supplier, configure its driver on the **Domain Manager** tab, run Check Connection, then let the `DomainSync` automatic action populate domain lifecycle and DNS record data. On a synced domain whose DNS provider supports write-back (Cloudflare/IONOS) and the current user holds the relevant per-type right, the domain's Records tab exposes create/edit/delete controls that write live to the provider. See `ARCHITECTURE.md` for full design detail, `CHANGELOG.md` for the release history, and `CHANGELOG-dev.md` for the full phase-by-phase development history.

## Testing
`composer test` (or `vendor/bin/phpunit`) runs a small automated suite covering the plugin's pure logic — IDN/Punycode conversion, TLD extraction, driver selection, status-label mapping, DTO enums, and DNS-lookup input validation. It deliberately does **not** cover the registrar/DNS driver API calls, sync engine, or any itemtype CRUD — those need a real GLPI database and, for the drivers, real provider accounts, so they're covered instead by the manual regression checklist in `TESTING-dev.md`.
