# Domain Manager

## Description

Domain Manager keeps GLPI's Domain and DNS record inventory synchronized with real registrar and DNS provider accounts (Cloudflare, IONOS, Dinahosting), and auto-detects the provider behind any domain even without credentials.

## Why this plugin?

- Native GLPI treats Domains and DNS Records as manually-entered inventory — nothing tells you a domain expired, whether WHOIS privacy or transfer lock is on, or whether the DNS record in GLPI still matches what's live at the provider.
- Without this plugin, "is our inventory accurate?" means logging into each registrar/DNS panel one by one. There is no official plugin that talks to registrar/DNS APIs.
- Editing a DNS record in GLPI normally does nothing to the real zone — teams end up maintaining two copies by hand, which drift silently. Domain Manager can push changes live and locks fields that were just synced so nobody overwrites fresh data by accident.

## Supported providers

Three drivers cover both registrar (lifecycle) data and DNS zone data; the proxy toggle is a Cloudflare-specific feature (Cloudflare's "orange cloud" CDN/proxying):

| Provider | Registrar (lifecycle) | DNS sync (read) | DNS write-back | Proxy toggle |
|---|---|---|---|---|
| Cloudflare | Yes | Yes | Yes | Yes |
| IONOS | Yes | Yes | Yes | No |
| Dinahosting | Yes | Yes | Yes | No |

<!-- shot: supplier-setup/02-driver-fields -->

Beyond these three, Domain Manager auto-detects a domain's DNS provider from its NS records even without any credentials configured — useful for portfolio-wide visibility even where you don't hold an API key. Auto-detection alone has no write-back. Recognized providers:

- Ascio
- AWS Route 53
- Google Cloud DNS
- Azure DNS
- GoDaddy
- OVHcloud
- DigitalOcean
- Linode (Akamai)
- Vercel
- Gandi
- Namecheap
- Hetzner
- Hostalia
- Squarespace
- Wix
- Hostinger
- Porkbun
- cdmon
- one.com
- NS1 (IBM NS1 Connect)
- bunny.net (Bunny DNS)
- Strato
- Arsys
- RaiolaNetworks
- LucusHost

## Features list

- Registrar sync: pulls registration date, expiry date, status, and lock/privacy/DNSSEC/auto-renew flags into each Domain.
- DNS sync: pulls zone records (A, AAAA, ALIAS, CNAME, MX, NS, PTR, SOA, SRV, TXT, CAA) into DomainRecord, tagging which ones are provider-managed vs. hand-added in GLPI.
- DNS write-back: create/update/delete A, AAAA, CNAME, and TXT records directly from the GLPI form (Cloudflare and IONOS), including Cloudflare proxy toggle, TTL, and validation (invalid hosts/IPs, CNAME-at-apex, SPF/DMARC length).
- Provider auto-detection: identifies the DNS provider for any domain from its NS records, even for providers with no write driver (20+ recognized, e.g. Route 53, Azure DNS, GoDaddy, OVHcloud, Namecheap, Hetzner, cdmon, Arsys...).
- Bulk domain discovery: imports every domain in a registrar/DNS account in one go.
- RDAP enrichment: fills in missing registration metadata from public RDAP records, rate-limited per domain.
- Field/record locking: fields and records populated by sync are locked against manual edits unless the user holds the unlock right.
- Reconciliation safety guard: refuses a sync that would trash an excessive number of records without confirmation, preventing a bad API response from wiping a zone.
- Dashboard cards: managed domains by registrar, by DNS provider, by TLD, sync status breakdowns, expiring-soon count, and managed DNS records by type/provider.
- Massive action "Sync now" to force a sync on selected domains.
- Full IDN/punycode support for internationalized domain names.
- Multi-entity aware, with per-entity breakdowns in sync logs and entity-scoped rights.

<!-- shot: domain-monitoring/01-domain-panel -->

## Impacted GLPI items

### Assets

- Domain
- Domain Record

### Management

- Supplier (adds a "Domain Manager" tab: API driver/credentials, linked domains)

### Administration

- Profiles (adds a "Domain Manager" rights tab)

### Setup

- General (adds a "Domain Manager" configuration tab)
- Automatic actions (2 new cron tasks)
- Dropdowns: Domain Type (1 type auto-seeded). Domain Record Type's 11 values (A, AAAA, ALIAS, CNAME, MX, NS, PTR, SOA, SRV, TXT, CAA) are native GLPI dropdown data, not injected by this plugin — it only re-creates one if an administrator deleted it.

## Third-party services and trademarks

Domain Manager integrates with third-party registrar/DNS provider APIs (Cloudflare, IONOS, Dinahosting) and the [rdap.org](https://rdap.org) public RDAP service (see "RDAP usage and etiquette" under Automatic Actions). The domain panel also links out to [who.is](https://who.is) for a manual WHOIS lookup. These are independent services operated by their respective owners; Domain Manager is not affiliated with, endorsed by, or sponsored by any of them, and their availability, rate limits, and terms of use are outside this plugin's control. All product names, logos, and brands referenced in this document — including provider names for auto-detected DNS providers listed under Supported providers — are the property of their respective owners and are used for identification purposes only.

## Interactions with other plugins

None. Domain Manager has no dependency on, and no integration hooks for, other GLPI plugins.

## Permissions

### Domain Manager rights (Profiles tab)

- Unlock imported domain data — edit fields/records that were written by a sync
- DNS records – A: Create / Update / Delete / Purge
- DNS records – AAAA: Create / Update / Delete / Purge
- DNS records – CNAME: Create / Update / Delete / Purge
- DNS records – TXT: Create / Update / Delete / Purge

### Others

- Standard `config` right — required to access Setup > Automatic actions and configure the two cron tasks.

<!-- shot: rights-and-profiles/01-rights-matrix-before -->

## Automatic Actions

| Name | Affected action | Recommended execution |
|---|---|---|
| DomainSync | Syncs registration lifecycle and DNS records for a batch of domains, oldest-synced first (default batch size: 2 domains/run, tunable from Setup > Automatic actions) | Every 30 minutes |
| RdapEnrichment | Looks up missing RDAP data for one domain per run (max 1 lookup/domain/day) | Every 15 minutes |

### RDAP usage and etiquette

Domain Manager's RDAP enrichment queries [rdap.org](https://rdap.org), a free public service that resolves the correct registry RDAP server for each domain. It is a courtesy service run by [Gavin Brown](https://github.com/gbxyz), a third party not affiliated with Domain Manager or GLPI. Two hardcoded limits keep the plugin's traffic conservative by default: one lookup per domain per minute, and one RdapEnrichment run every 15 minutes processing at most one domain — so a single install cannot realistically exceed rdap.org's own rate limits.

> **Note:** If you manage a very large number of domains and are likely to generate sustained RDAP traffic, consider querying the [IANA bootstrap registries](https://data.iana.org/rdap/) directly, or running your own local RDAP instance (rdap.org publishes one you can start with `docker compose up`). This keeps your traffic off the shared public service and avoids the risk of hitting its rate limits or those of the underlying registries. If rdap.org's service is useful to you, its maintainer also welcomes support via [ko-fi.com/rdaporg](https://ko-fi.com/rdaporg).

## Notifications

None — the plugin does not use GLPI's notification system. Sync activity and errors are written to dedicated activity/error logs instead.

## Rules

None — Domain Manager does not add criteria or actions to GLPI's Rules engine.

## Setup

### Installation

**From a release archive**

1. Copy the plugin folder into GLPI's `plugins/` directory as `domainmanager`. A release archive already ships its `vendor/` directory (only `php-domain-parser`; the Cloudflare/IONOS/Dinahosting drivers call each provider's REST API directly over Guzzle, there are no vendor SDKs) — no `composer install` needed.
2. In GLPI, go to Setup > Plugins, install and enable "Domain Manager".

**From source (git clone)**

1. Copy/clone the plugin folder into GLPI's `plugins/` directory as `domainmanager`.
2. Run `composer install --no-dev` inside the plugin folder to install dependencies — a source checkout has no `vendor/` directory.
3. In GLPI, go to Setup > Plugins, install and enable "Domain Manager".

**Marketplace**

Install and enable from the GLPI Marketplace like any other plugin (no extra steps required).

### Configuration

1. **Setup > General > Domain Manager**: set the default Domain Type for imported domains, optionally enable global read-only mode, and set the sync safety-guard thresholds (max record count/percentage allowed to trash per sync). This page also shows a read-only RDAP enrichment status display (pending count, last processed time).
2. **Suppliers > [pick a supplier] > Domain Manager**: choose the API driver (Cloudflare, IONOS, or Dinahosting) and enter credentials, then click "Check Connection" to validate.
3. **Setup > Automatic actions**: both tasks default to CLI mode (system cron / `bin/console glpi:cron`) rather than GLPI's internal scheduler on a fresh install — switch a task to the internal scheduler here if you'd rather not run a system cron. Also where the DomainSync batch size and either task's run frequency are tuned.
4. **Administration > Profiles > Domain Manager**: grant the unlock right and per-record-type write-back permissions to the relevant profiles.

## How to use

1. Link a Supplier to your registrar/DNS provider and configure its API credentials (Suppliers > Domain Manager tab).
2. Run domain discovery (or wait for the next scheduled sync) to import all domains from that account.
3. Open a Domain: the injected panel shows the read-only registrar, detected DNS provider, sync status, and a manual "Update Now" button.
4. Review DNS Records on the domain: records pulled from the provider are marked as managed and locked; edit unlocked fields as needed.
5. To push a DNS change live, edit an A/AAAA/CNAME/TXT record on a domain linked to a write-capable provider (Cloudflare/IONOS) and save — the plugin validates and pushes it to the provider.
6. Use the Domain list's "Sync now" massive action to force an immediate sync on selected domains instead of waiting for the cron.
7. Check the Domain Manager dashboard cards for a portfolio-wide view: domains per registrar/DNS provider, domains by TLD, sync status, and domains expiring soon.
