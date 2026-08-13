# 2. Setup

> Generated for **domainmanager 1.7.1** on GLPI 11.0 — 2026-08-11.

*Part of the domainmanager manual — see also [1. Introduction](01-intro.md), [3. Usage](03-usage.md), [4. Troubleshooting](04-troubleshooting.md).*

## 2.1 Installation

**From a release archive**

1. Copy the plugin folder into GLPI's `plugins/` directory as `domainmanager`. A release archive already ships its `vendor/` directory (only `php-domain-parser`; the Cloudflare/IONOS/Dinahosting drivers call each provider's REST API directly over Guzzle, there are no vendor SDKs) — no `composer install` needed.
2. In GLPI, go to Setup > Plugins, install and enable "Domain Manager".

**From source (git clone)**

1. Copy/clone the plugin folder into GLPI's `plugins/` directory as `domainmanager`.
2. Run `composer install --no-dev` inside the plugin folder to install dependencies — a source checkout has no `vendor/` directory.
3. In GLPI, go to Setup > Plugins, install and enable "Domain Manager".

**Marketplace**

Install and enable from the GLPI Marketplace like any other plugin (no extra steps required).

## 2.2 Configuration

1. **Setup > General > Domain Manager**: set the default Domain Type for imported domains, optionally enable global read-only mode, and set the sync safety-guard thresholds (max record count/percentage allowed to trash per sync). This page also shows a read-only RDAP enrichment status display (pending count, last processed time).
2. **Suppliers > [pick a supplier] > Domain Manager**: choose the API driver (Cloudflare, IONOS, or Dinahosting) and enter credentials, then click "Check Connection" to validate.
3. **Setup > Automatic actions**: both tasks default to CLI mode (system cron / `bin/console glpi:cron`) rather than GLPI's internal scheduler on a fresh install — switch a task to the internal scheduler here if you'd rather not run a system cron. Also where the DomainSync batch size and either task's run frequency are tuned.
4. **Administration > Profiles > Domain Manager**: grant the unlock right and per-record-type write-back permissions to the relevant profiles.

### 2.2.1 Automatic actions

| Name | Affected action | Recommended execution |
|---|---|---|
| DomainSync | Syncs registration lifecycle and DNS records for a batch of domains, oldest-synced first (default batch size: 2 domains/run, tunable from Setup > Automatic actions) | Every 30 minutes |
| RdapEnrichment | Looks up missing RDAP data for one domain per run (max 1 lookup/domain/day) | Every 15 minutes |

### 2.2.2 RDAP usage and etiquette

Domain Manager's RDAP enrichment queries [rdap.org](https://rdap.org), a free public service that resolves the correct registry RDAP server for each domain. It is a courtesy service run by [Gavin Brown](https://github.com/gbxyz), a third party not affiliated with Domain Manager or GLPI. Two hardcoded limits keep the plugin's traffic conservative by default: one lookup per domain per minute, and one RdapEnrichment run every 15 minutes processing at most one domain — so a single install cannot realistically exceed rdap.org's own rate limits.

> **Note:** If you manage a very large number of domains and are likely to generate sustained RDAP traffic, consider querying the [IANA bootstrap registries](https://data.iana.org/rdap/) directly, or running your own local RDAP instance (rdap.org publishes one you can start with `docker compose up`). This keeps your traffic off the shared public service and avoids the risk of hitting its rate limits or those of the underlying registries. If rdap.org's service is useful to you, its maintainer also welcomes support via [ko-fi.com/rdaporg](https://ko-fi.com/rdaporg).

## 2.3 Permissions

### Domain Manager rights (Profiles tab)

- Unlock imported domain data — edit fields/records that were written by a sync
- DNS records – A: Create / Update / Delete / Purge
- DNS records – AAAA: Create / Update / Delete / Purge
- DNS records – CNAME: Create / Update / Delete / Purge
- DNS records – TXT: Create / Update / Delete / Purge

### Others

- Standard `config` right — required to access Setup > Automatic actions and configure the two cron tasks.

![rights-and-profiles — 01-rights-matrix-before](assets/01-rights-and-profiles/01-rights-matrix-before.png)

## 2.4 Granting Domain Manager rights to a profile

Domain Manager adds its own rights to GLPI’s profile system. A profile has no access to the plugin’s protected actions until an administrator grants them here — this is the first thing to check if a user reports missing DNS record buttons or an un-editable synced field.

### 2.4.1 Open the profile to edit

Go to **Administration → Profiles** and open the profile to grant rights to — here, **Technician**.

### 2.4.2 Open the Domain Manager tab

Select the **Domain Manager** tab. It lists every right the plugin defines: unlocking fields and records that came from a synchronization, and per-record-type write-back rights (Create/Update/Delete/Purge) for each DNS record type the plugin can push to a provider.

![The Domain Manager rights matrix, before any right is granted](assets/01-rights-and-profiles/01-rights-matrix-before.png)

*The Domain Manager rights matrix, before any right is granted*

### 2.4.3 Grant unlock and A-record write-back rights

Tick **Edit fields and records imported by synchronization** to let this profile override plugin-managed locks on the native form, and tick **Create**/**Update** on **Domain Record: A** to let it push new and changed A records to the provider. Leave the other record types and the destructive Delete/Purge bits unchecked — grant only what a role actually needs.

![Unlock and A-record Create/Update ticked, not yet saved](assets/01-rights-and-profiles/02-rights-matrix-checked.png)

*Unlock and A-record Create/Update ticked, not yet saved*

### 2.4.4 Save the profile

Click **Save**. The rights take effect immediately for every user with this profile.

> **Note:** Delete and Purge are separate bits from Create/Update on each record-type right: a profile can be trusted to push new and changed records without also being able to remove them from the provider.

## 2.5 Configuring a Supplier as a domain source

Domain Manager syncs domains and DNS records through a Supplier’s API credentials. Before it can manage anything, one Supplier needs an API driver selected and its credentials entered.

### 2.5.1 Open the Domain Manager tab on a Supplier

Open the Supplier record and select its **Domain Manager** tab. This is where the API driver and credentials for that provider are configured.

![The Domain Manager tab on a Supplier, with a driver already selected](assets/02-supplier-setup/01-supplier-tab.png)

*The Domain Manager tab on a Supplier, with a driver already selected*

### 2.5.2 Pick an API driver and enter credentials

Choose the **API driver** that matches this Supplier — Domain Manager currently supports Cloudflare, IONOS and Dinahosting. The fields below change to match the chosen driver; each shows exactly the credentials that provider requires.

![Credential fields for the Cloudflare driver](assets/02-supplier-setup/02-driver-fields.png)

*Credential fields for the Cloudflare driver*

> **Note:** <a id="cloudflare"></a>**Cloudflare:** Requires an **Account API Token** (Manage Account → API Tokens, not a personal/My Profile token) plus its Account ID. Grant `Zone:Zone:Read` and `Zone:DNS:Read` — both are required just to import DNS records — and add `Zone:DNS:Edit` if you also want this domain's records editable from GLPI (write-back).

> **Note:** <a id="ionos"></a>**IONOS:** Requires an **API Key** and **API Secret** from the IONOS Cloud panel (Management → API Keys). Both DNS zone sync/write-back and registrar lifecycle data use the same key/secret pair — no separate scoping is available.

> **Note:** <a id="dinahosting"></a>**Dinahosting:** Requires the account's plain **username and password** — Dinahosting has no scoped API token, so the credentials stored here are the same ones used to log into the control panel. **The account must be the super-admin account**: a sub-user or domain-limited account cannot authenticate against the API at all.

### 2.5.3 Save the configuration

Click **Save**. The *Test* and *Import* actions only appear once a driver has been saved at least once — until then there is nothing configured to test.

### 2.5.4 Test the connection

Click **Test** to verify the credentials work before relying on them. The result appears in the **Connection diagnostics** panel below, including any error the provider returned — useful for catching a mistyped or under-scoped credential before a sync ever runs.

![The connection diagnostics panel after running Test](assets/02-supplier-setup/03-connection-test.png)

*The connection diagnostics panel after running Test*

### 2.5.5 Import the Supplier’s domains

Click **Import** to list every domain visible in that provider’s account. Domains already tracked in GLPI are shown as already existing; the rest can be selected and imported in one action. This step needs working credentials — with an invalid or under-scoped token, the dialog reports the same error **Test** did instead of a domain list.

![The domain import dialog](assets/02-supplier-setup/04-import-modal.png)

*The domain import dialog*

> **Note:** Domains already synced through this Supplier are updated automatically by the **DomainSync** automatic action; importing here is only needed for domains Domain Manager has not seen yet.
