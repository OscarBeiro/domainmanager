# Domain Manager — Usage Manual

Domain Manager tracks domain lifecycles (registration, expiry, transfer status) and DNS
zone records inside GLPI, syncing them from Cloudflare, IONOS and Dinahosting, with
auto-detection of many other DNS providers from a domain's NS records. On Cloudflare and
IONOS, DNS records can also be created, edited and deleted from GLPI, writing live to the
provider.

This manual covers what you'll do most often:

1. **Grant Domain Manager rights** to a profile, so its users can unlock synced fields and
   push DNS record changes to a provider.
2. **Configure a Supplier** with an API driver and credentials, test the connection, and
   import the domains it manages.
3. **Monitor a domain** through its Domain Manager status panel and manage its DNS
   records.

## Why this plugin?

- Native GLPI treats Domains and DNS Records as manually-entered inventory — nothing
  tells you a domain expired, whether WHOIS privacy or transfer lock is on, or whether the
  DNS record in GLPI still matches what's live at the provider.
- Without this plugin, "is our inventory accurate?" means logging into each registrar/DNS
  panel one by one. There is no official plugin that talks to registrar/DNS APIs.
- Editing a DNS record in GLPI normally does nothing to the real zone — teams end up
  maintaining two copies by hand, which drift silently. Domain Manager can push changes
  live and locks fields that were just synced so nobody overwrites fresh data by accident.

## Supported registrars and DNS providers

Three drivers cover both registrar (lifecycle) data and DNS zone data; the proxy toggle is
a Cloudflare-specific feature (Cloudflare's "orange cloud" CDN/proxying):

| Provider | Registrar (lifecycle) | DNS sync (read) | DNS write-back | Proxy toggle |
|---|---|---|---|---|
| Cloudflare | Yes | Yes | Yes | Yes |
| IONOS | Yes | Yes | Yes | No |
| Dinahosting | Yes | Yes | No | No |

Beyond these three, Domain Manager auto-detects a domain's DNS provider from its NS
records even without any credentials configured — useful for portfolio-wide visibility
even where you don't hold an API key. Auto-detection alone has no write-back. Recognized
providers: Ascio, AWS Route 53, Google Cloud DNS, Azure DNS, GoDaddy, OVHcloud,
DigitalOcean, Linode (Akamai), Vercel, Gandi, Namecheap, Hetzner, Hostalia, Squarespace,
Wix, Hostinger, Porkbun, cdmon, one.com, NS1 (IBM NS1 Connect), bunny.net (Bunny DNS),
Strato, Arsys, RaiolaNetworks and LucusHost.

## Before you start

You'll need:

- A **Supplier** record (Management → Suppliers) to attach credentials to — Domain Manager
  adds a tab to Suppliers rather than introducing a page of its own.
- The **Supplier** UPDATE right to configure a driver, and Supplier READ to view the tab.
- For DNS write-back: a per-record-type right (A, AAAA, CNAME, TXT — each with its own
  Create/Update/Delete/Purge bits), granted per profile from the **Domain Manager** tab
  under *Administration → Profiles*.
- Real API credentials for at least one of the supported drivers. See the main
  [README](../../README.md#configuration) for what each provider requires (Cloudflare
  needs an Account API Token and Account ID; IONOS an API Key/Secret pair; Dinahosting the
  super-admin account's username and password).

This manual documents version 1.7.1-beta1 against GLPI 11.0.8.
