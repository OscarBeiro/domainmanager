# Domain Manager — Usage Manual

Domain Manager tracks domain lifecycles (registration, expiry, transfer status) and DNS
zone records inside GLPI, syncing them from Cloudflare, IONOS and Dinahosting, with
auto-detection of many other DNS providers from a domain's NS records. On Cloudflare and
IONOS, DNS records can also be created, edited and deleted from GLPI, writing live to the
provider.

This manual covers the two things you'll do most often:

1. **Configure a Supplier** with an API driver and credentials, test the connection, and
   import the domains it manages.
2. **Monitor a domain** through its Domain Manager status panel and manage its DNS
   records.

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
