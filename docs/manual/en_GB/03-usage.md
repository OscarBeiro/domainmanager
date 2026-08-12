# 3. Usage

> Generated for **domainmanager 1.7.1** on GLPI 11.0 — 2026-08-11. Screenshots are produced automatically; do not edit generated sections by hand.

*Part of the domainmanager manual — see also [1. Introduction](01-intro.md), [2. Setup](02-setup.md), [4. Troubleshooting](04-troubleshooting.md).*

## 3.1 How to use

1. Link a Supplier to your registrar/DNS provider and configure its API credentials (Suppliers > Domain Manager tab).
2. Run domain discovery (or wait for the next scheduled sync) to import all domains from that account.
3. Open a Domain: the injected panel shows the read-only registrar, detected DNS provider, sync status, and a manual "Update Now" button.
4. Review DNS Records on the domain: records pulled from the provider are marked as managed and locked; edit unlocked fields as needed.
5. To push a DNS change live, edit an A/AAAA/CNAME/TXT record on a domain linked to a write-capable provider (Cloudflare/IONOS) and save — the plugin validates and pushes it to the provider.
6. Use the Domain list's "Sync now" massive action to force an immediate sync on selected domains instead of waiting for the cron.
7. Check the Domain Manager dashboard cards for a portfolio-wide view: domains per registrar/DNS provider, domains by TLD, sync status, and domains expiring soon.

## 3.2 Monitoring a domain and its DNS records

Once a domain is managed by Domain Manager, its own record shows a live status panel — registrar, DNS provider, sync health — and its DNS zone becomes editable from GLPI when the provider supports write-back.

### 3.2.1 Open the domain’s Domain Manager panel

Open the domain from **Assets → Domains**. The **Domain Manager** panel on its main tab shows which Supplier acts as registrar and which one as DNS provider, plus a sync status badge for each — green for healthy, red for a failed sync, grey for "never synchronized".

![The Domain Manager status panel on a managed domain](assets/03-domain-monitoring/01-domain-panel.png)

*The Domain Manager status panel on a managed domain*

### 3.2.2 Open the domain’s DNS records

Switch to the domain’s **Records** tab to see its DNS zone. Records synced from the provider are locked against manual edits — this keeps GLPI from drifting out of sync with what the DNS provider actually serves.

![The domain’s DNS records, synced from its provider](assets/03-domain-monitoring/02-records-list.png)

*The domain’s DNS records, synced from its provider*

### 3.2.3 Add a DNS record

On a domain whose DNS provider supports write-back (here, Cloudflare), a **New record** form appears in place of the usual "Link a record" form. Creating a record here pushes it live to the DNS provider immediately, not just to GLPI's copy of the zone — the confirmation prompt exists for that reason.

![Adding an A record; this will be created live at the DNS provider](assets/03-domain-monitoring/03-add-record-form.png)

*Adding an A record; this will be created live at the DNS provider*

> **Note:** Fields populated by the last sync (registration date, expiry, existing record values) are locked by default. A user with the "Unlock imported domain data" right can edit them anyway — useful when the provider reported something wrong.

## 3.3 Bulk-importing domains from a Supplier

Once a Supplier's API driver is configured, Domain Manager can discover all domains in that provider's account. Importing multiple domains at once is faster than adding them one by one.

### 3.3.1 Open the Import Domains dialog

In the Domain Manager tab on a Supplier, click the **Import** button. This opens a modal showing every domain the provider's account contains that isn't already tracked in GLPI.

![The domain import dialog showing multiple domains available to import](assets/04-bulk-import/01-import-list.png)

*The domain import dialog showing multiple domains available to import*

### 3.3.2 Select domains to import

Each domain in the list can be checked or unchecked independently. Domains already in GLPI are shown with the **Already exists** label and cannot be selected. Use the checkbox in the header to quickly select or deselect all pending domains at once.

![Two domains selected, one deselected (Domain Manager will import only the selected ones)](assets/04-bulk-import/02-select-partial.png)

*Two domains selected, one deselected (Domain Manager will import only the selected ones)*

### 3.3.3 Complete the import

Click **Import selected domains** to create all checked domains in GLPI. They appear immediately in your domain list and automatically start syncing on the next cron run; no manual intervention is needed after import.

> **Note:** The imported domains are now part of your domain inventory. Their first sync happens automatically on the next scheduled sync run (typically within minutes), and they sync thereafter on your configured interval.

> **Note:** Importing multiple domains at once is much faster than creating them individually. After import, all domains are treated identically: they sync automatically, show status, and support DNS record management just like any domain you created by hand.

## 3.4 Editing an existing record & the managed/locked-field state

Domain Manager imports DNS records by syncing them from your provider's live account. Some records can be edited here; others are locked because the provider hasn't confirmed that your edits would succeed.

### 3.4.1 Open a record that can be edited

Navigate to a Domain's Records tab and click **Edit** on an A or CNAME record. Since this domain's DNS provider is configured and working, the form shows an alert warning that **saving updates the record live** at the provider.

![The data and TTL fields are editable (no lock icons)](assets/05-editing-records/01-editable-fields.png)

*The data and TTL fields are editable (no lock icons)*

### 3.4.2 Make a change and review before saving

Type a new value (the form doesn't require you to submit, so you can review the change). Notice there's no lock icon next to **data** or **TTL** — they're fully editable.

![The form shows your change ready to save (no undo once submitted)](assets/05-editing-records/02-edit-form-changed.png)

*The form shows your change ready to save (no undo once submitted)*

### 3.4.3 Open a record that cannot be edited

Go back to the domain list and open a different domain whose DNS provider is in a **read-only state** (write-back failed or never succeeded). Records on this domain cannot be edited here.

### 3.4.4 See the locked-field explanation

The form shows an alert explaining that this record is **imported by Domain Manager synchronization** and cannot be edited. This happens when the provider hasn't confirmed that write-back would succeed.

![The alert explains why this record cannot be edited](assets/05-editing-records/03-locked-alert.png)

*The alert explains why this record cannot be edited*

### 3.4.5 Notice the lock icons on editable fields

Look at the **data** and **TTL** fields — they have a lock icon next to them, indicating they're read-only. The fields are disabled, preventing any changes.

![The data field has a lock icon and is disabled](assets/05-editing-records/04-locked-field.png)

*The data field has a lock icon and is disabled*

### 3.4.6 Why records become locked

Domain Manager locks imported records until a successful write confirms the provider can push updates. Once a write succeeds, these fields unlock. If the provider returns an error, the fields stay locked (the "read-only" state shown here). A user with the **Unlock imported domain data** right can override the lock if needed.

## 3.5 Proxy vs. origin IP indicators

When a domain is proxied through Cloudflare (or another proxy service), Domain Manager displays both the origin IP address and the proxy service's address. Visual indicators make it clear which records are being proxied.

### 3.5.1 Open the Records tab on a proxied domain

Navigate to a domain with some records proxied through Cloudflare. Open the **Records** tab to see the full list of DNS records.

![The records table shows both proxied (cloud icon) and non-proxied records](assets/06-proxy-indicators/01-records-table-with-proxied.png)

*The records table shows both proxied (cloud icon) and non-proxied records*

### 3.5.2 Identify which records are proxied

A **cloud icon** appears next to the name of any record that is proxied through Cloudflare. This small visual indicator tells you at a glance which records have proxy enabled.

![The cloud icons next to proxied record names show which records are proxied](assets/06-proxy-indicators/02-proxy-icon-detail.png)

*The cloud icons next to proxied record names show which records are proxied*

### 3.5.3 See the proxy service's address

Below the **Target** (origin IP) for any proxied record, a second line shows the proxy service's address — for Cloudflare, this is the anycast IP they're using. This address is updated automatically during each sync and acts as the public-facing address for the record.

![Proxy service anycast addresses appear below the origin IP on proxied records](assets/06-proxy-indicators/03-proxy-address-detail.png)

*Proxy service anycast addresses appear below the origin IP on proxied records*

## 3.6 Dashboard cards & drill-down

Domain Manager adds a set of widgets to GLPI's own dashboard system (the same "Home" dashboard, or any custom dashboard, that other GLPI plugins and core itemtypes contribute cards to). Add them from the dashboard's **Edit** mode, like any other GLPI widget, then arrange and resize them as needed.

### 3.6.1 Add the widgets to a dashboard

![Domain Manager's dashboard cards added to a GLPI dashboard](assets/07-dashboard/01-dashboard-overview.png)

*Domain Manager's dashboard cards added to a GLPI dashboard*

> **Note:** this chapter's screenshot is a placeholder pending a real capture from a populated instance.

### 3.6.2 Available cards

- **Number of Managed Domains** and **Number of Managed Records** — simple totals.
- **Number of Domains expiring soon** — domains whose registration expires within 30 days.
- **Managed domains per registrar**, **per DNS provider**, **by TLD**, and **per registrar by TLD** — breakdown charts you can use to see where your domain portfolio is concentrated.
- **DNS sync status** and **Registrar sync status** — breakdowns of how many domains are currently syncing cleanly versus in a warning or error state, the fleet-wide view of the per-domain status shown in [Monitoring a domain and its DNS records](#32-monitoring-a-domain-and-its-dns-records).
- **Managed records by type**, **by DNS provider**, and **per DNS provider by type** — the same kind of breakdown, one level down at the DNS record.
- **Proxied records** — how many managed records are currently proxied (see [Proxy vs. origin IP indicators](#35-proxy-vs-origin-ip-indicators)).

### 3.6.3 Drilling down from a chart segment

Clicking a segment of any breakdown chart (a registrar's slice of "Managed domains per registrar", an "Error" slice of "DNS sync status", and so on) opens GLPI's Domain search already filtered to match that segment — so a spike in errors on the dashboard is one click away from the actual list of affected domains.
