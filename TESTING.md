# Domain Manager — Acceptance Checklist

A human-readable release checklist for testing Domain Manager against a live GLPI 11 instance.
This is a **reference for QA/UAT**, not a binding gate — run what applies to the accounts you
actually have. Someone who has never seen this plugin's source should be able to follow it.

Every item is a **pass/fail check on one key action** — no partial credit, no tiers. If the
"Then" doesn't hold, it's a fail; note it and move on.

The detailed developer regression record (per-feature-phase, code-level) lives in
`TESTING-dev.md`. This file only covers what the plugin does **today** — nothing planned or
partially built.

## How to read an item

```
**5.3 — Domain with an unsupported DNS provider** · read-only · all
**Given** a domain whose nameservers match no known provider
**When** you open the domain and press Update now
**Then** the panel shows the provider as detected but unsupported, with a contribution link, and no error appears
```

- **Blast-radius marker**: `read-only` (no external effect), `live write` (creates/modifies a
  real record at a real provider — no undo), or `destructive` (deletes something real).
- **Applicability marker**: `all` means the item applies no matter which provider(s) you're
  testing against. Provider-specific behaviour lives only in the three appendices after §17 —
  **skip an entire appendix if you don't have that provider's account.**

---

## §1 — Prerequisites

- A GLPI 11 instance with Domain Manager installed and activated.
- At least one provider account among Cloudflare, IONOS, Dinahosting.
- **Cloudflare**: an Account API Token (not a personal token) scoped `Zone:Zone:Read` +
  `Zone:DNS:Read` (+ `Zone:DNS:Edit` for write-back), and the Account ID.
- **IONOS**: an API Key + Secret from the IONOS Cloud panel.
- **Dinahosting**: the account's plain username/password. **Must be the super-admin account** —
  a sub-user or domain-limited account cannot authenticate at all.
- A throwaway domain/zone on whichever provider(s) you're testing — §9/§10 write and delete real
  records with no undo.
- A second credential/token deliberately missing a required scope, to test §5.3.
- Two GLPI profiles (technician-level, super-admin) for §4; a second child entity for §15.
- Read the "Known limitations" section at the end before you start — three items there are
  expected behaviour, not bugs to file.

---

## §2 — Install and upgrade

**2.1 — Fresh install** · read-only · all
**Given** a GLPI 11 instance with Domain Manager not yet installed
**When** you install and activate it from Setup → Plugins
**Then** it activates with no error, and a new "Domain Manager" tab appears on Supplier and Profile forms

**2.2 — Upgrade from the previous version** · read-only · all
**Given** an older version already installed with existing domains/suppliers
**When** you upload the new version and re-run install
**Then** it upgrades cleanly, existing data is untouched, and no error appears in the GLPI log

---

## §3 — Configuration and credentials

**3.1 — Create a supplier and link a driver** · live write · all
**Given** a new Supplier
**When** you open its Domain Manager tab, pick a driver, enter credentials, and Save
**Then** the credentials save without being echoed back in plain text, and the tab reflects the chosen driver

**3.2 — Check Connection with valid credentials** · read-only · all
**Given** a supplier with correct credentials saved
**When** you press Check Connection
**Then** you see a success result and a "last checked" timestamp

**3.3 — Check Connection with wrong credentials** · read-only · all
**Given** a supplier with an intentionally wrong token/password saved
**When** you press Check Connection
**Then** you see a clear failure message, and no domain data changes

**3.4 — Check Connection on a deactivated supplier** · read-only · all
**Given** a supplier marked inactive
**When** you open its Domain Manager tab
**Then** Check Connection and Import are disabled, with a message explaining why

---

## §4 — Permissions

**4.1 — Grant and revoke a per-type DNS write right** · read-only · all
**Given** a profile with none of the four DNS record rights (A/AAAA/CNAME/TXT)
**When** you grant, then later revoke, the "A" record right
**Then** the add/edit controls for A records appear only while the right is granted

**4.2 — Technician vs. super-admin** · read-only · all
**Given** a technician profile without the per-type rights, and a super-admin profile that has them
**When** each opens the same managed domain's Records tab
**Then** the technician sees read-only records; the super-admin sees full write controls

**4.3 — Unlock imported domain data right** · read-only · all
**Given** a domain with fields locked because they were imported by synchronization
**When** a user without the right tries to edit a locked field, then a user with the right tries
**Then** the first is blocked with an explanatory message; the second can edit successfully

**4.4 — Entity-restricted profile** · read-only · all
**Given** a profile scoped to a single child entity
**When** that user browses domains
**Then** only domains in their entity (and its children) are visible

---

## §5 — Discovery and import

**5.1 — Import domains from an account** · live write · all
**Given** a supplier with valid credentials and at least one real domain at the provider
**When** you press Import, pick a target entity, select the domain(s), and confirm
**Then** the domain(s) appear in GLPI under the chosen entity, linked to that supplier as registrar

**5.2 — All domains already present** · read-only · all
**Given** an account whose domains are all already imported into GLPI
**When** you open the import dialog and attempt import again
**Then** those domains show as already existing / skipped — no duplicates are created

**5.3 — Insufficient token scope** · read-only · all
**Given** a token/credential deliberately missing the scope needed to list domains
**When** you open the import dialog
**Then** you see a clear permission-related error, not a silent empty list or a crash

**5.4 — Which entity domains land in** · live write · all
**Given** the import dialog's entity dropdown
**When** you pick an entity different from your current active entity and import
**Then** the imported domain(s) land in the entity you picked, not your active session entity

---

## §6 — Domain sync

**6.1 — Manual "Update now"** · read-only · all
**Given** a domain linked to a configured, active supplier
**When** you press "Update now" on the domain's panel
**Then** the registrar and DNS status badges refresh, and a "last synced" timestamp updates

**6.2 — Detected but unconfigured** · read-only · all
**Given** a domain whose DNS provider is detected but has no matching Supplier configured
**When** you sync
**Then** the status shows "unconfigured", not an error

**6.3 — Unsupported provider** · read-only · all
**Given** a domain whose nameservers match no known provider in the registry
**When** you sync
**Then** the status shows "unsupported", with a note that contributions are welcome, and no error appears

**6.4 — Inactive supplier** · read-only · all
**Given** a domain linked to a supplier that has since been deactivated
**When** you sync
**Then** the status shows "supplier inactive", distinct from a connection error

**6.5 — An IDN domain** · read-only · all
**Given** a domain with non-ASCII characters in its name
**When** you view its panel and sync
**Then** the Punycode/ASCII form is shown alongside the name, and sync works normally

**6.6 — Sync safety guard** · read-only · all
**Given** a sync that would trash an unusually large number/percentage of existing records in one run
**When** you press "Update now"
**Then** the sync refuses, shows a distinct "safety guard" status naming the counts, and offers a confirmation to force it through

---

## §7 — RDAP enrichment

**7.1 — Manual RDAP trigger** · read-only · all
**Given** a domain with an active RDAP entry at its TLD's registry
**When** you press the RDAP refresh action on the domain panel
**Then** the "last RDAP check" timestamp updates and no error appears

**7.2 — A TLD with no RDAP service** · read-only · all
**Given** a domain under a TLD with no RDAP service
**When** RDAP enrichment runs for it
**Then** it's marked as checked with no data and no error is shown

---

## §8 — Reading records

**8.1 — Each supported type present and correct** · read-only · all
**Given** a synced domain with A, AAAA, CNAME and TXT records at the provider
**When** you view its Records tab
**Then** every record appears with the correct type, name, value and TTL

**8.2 — Proxied record display** · read-only · all
**Given** a Cloudflare-proxied record
**When** you view it
**Then** its proxied status and effective (proxied) IP are shown distinctly from the origin value

---

## §9 — Writing records *(live write)*

**9.1 — Create an A record** · live write · all
**Given** a managed, write-capable domain and the "A" right
**When** you add a new A record at a subdomain and confirm the live-write warning
**Then** it's created at the provider and appears in GLPI within one sync

**9.2 — Create at the apex** · live write · all
**Given** the same domain
**When** you add an A/AAAA/TXT record at the domain's apex
**Then** it's created correctly (apex CNAME is a provider-specific exception — see the appendices)

**9.3 — TXT with quoting** · live write · all
**Given** a TXT value containing spaces or special characters
**When** you create it
**Then** it's stored and displayed as the literal value you entered, without double-quoting or truncation

**9.4 — Edit a value** · live write · all
**Given** an existing writable record
**When** you change its value and confirm
**Then** the new value is live at the provider and reflected in GLPI

**9.5 — Duplicate refused** · read-only · all
**Given** a record identical in name/type/value to one that already exists
**When** you try to create it again
**Then** the attempt is refused with a clear duplicate message, and no second record is created

**9.6 — Non-writable type refused** · read-only · all
**Given** a record type not supported for writing (e.g. MX, SRV, SOA, CAA)
**When** you try to create/edit one via the plugin's write panel
**Then** it's refused, or the control simply isn't offered — never silently accepted and dropped

**9.7 — Write without the right refused** · read-only · all
**Given** a user without the relevant per-type right
**When** they attempt a raw write for that type (not just via the hidden UI control)
**Then** the server rejects it — the right is enforced server-side, not just by hiding a button

**9.8 — Read-only mode blocks all writes** · read-only · all
**Given** the plugin's global "Read-only mode" toggle switched on (Setup tab)
**When** any user with full rights tries to create/edit/delete a record
**Then** the write is blocked with a message naming read-only mode specifically

---

## §10 — Deleting records *(destructive)*

**10.1 — Trash a record** · destructive · all
**Given** an existing writable record and the relevant DELETE right
**When** you delete it and confirm the live-delete warning
**Then** it's removed at the provider and appears trashed (not gone) in GLPI

**10.2 — Restore from trash** · live write · all
**Given** a record trashed in the previous step
**When** you restore it from GLPI's trash
**Then** it's re-created at the provider, matching its original value

**10.3 — Purge (hard delete)** · destructive · all
**Given** a trashed record and the relevant PURGE right
**When** you purge it
**Then** it's permanently removed from GLPI — there is no further undo

---

## §11 — Cross-checking against the provider

**11.1 — A change made at the provider appears in GLPI** · read-only · all
**Given** a record edited directly in the provider's own control panel
**When** the domain next syncs
**Then** GLPI reflects the new value

**11.2 — A record deleted at the provider is trashed, not lost** · read-only · all
**Given** a record deleted directly at the provider
**When** the domain next syncs
**Then** the corresponding GLPI record is moved to trash, not silently removed with no trace

---

## §12 — Errors and degraded states

**12.1 — Unreachable provider** · read-only · all
**Given** a supplier whose API endpoint is temporarily unreachable
**When** you sync
**Then** the status shows a clear "unreachable"/error message a non-developer can act on, not a raw stack trace

**12.2 — Blast-radius guard refusal** · read-only · all
**Given** the scenario from §6.6
**When** the guard triggers
**Then** the message names the exact counts/thresholds and offers a deliberate "force" override — it never silently proceeds

---

## §13 — History and audit

**13.1 — Import logged as a creation** · read-only · all
**Given** a domain just imported (§5.1)
**When** you check its Historical tab
**Then** a creation entry appears

**13.2 — Record changes logged as add/update/delete** · read-only · all
**Given** the write operations from §9/§10
**When** you check the domain's Historical tab
**Then** each write attempt appears as a distinct history line naming the operation, record name, and provider. **Known limitation:** the line does not name which user triggered it.

**13.3 — Profile right changes visible in profile history** · read-only · all
**Given** a change to one of this plugin's rights on a profile (§4.1)
**When** you check that profile's own Historical tab
**Then** the change is visible — native GLPI behaviour, unaffected by the plugin

---

## §14 — Dashboard

**14.1 — Cards render** · read-only · all
**Given** at least one managed domain with records
**When** you open the Domain Manager dashboard
**Then** all cards render with data — domains/records by registrar, DNS provider, TLD, status, type

**14.2 — Respect the active entity** · read-only · all
**Given** domains in two different entities
**When** you switch your active entity
**Then** every card's counts update to reflect only the active entity (and its children)

**14.3 — Drill-down lands on the correct filtered list** · read-only · all
**Given** any card showing a breakdown
**When** you click into one of its segments
**Then** you land on a list pre-filtered to match that segment — not the unfiltered full list

---

## §15 — Entities

**15.1 — A domain in a child entity** · read-only · all
**Given** a domain created in a child entity
**When** a user scoped to the parent entity views the domain list
**Then** the child-entity domain is visible; a user scoped only to a sibling entity does not see it

**15.2 — Cron does not follow entity scope — known limitation** · read-only · all
**Given** the DomainSync/RdapEnrichment automatic actions
**When** they run
**Then** they process domains across **all** entities, with no per-entity restriction — this is by design. Only the manual, per-domain trigger paths (§6.1, §7.1) enforce entity access.

---

## §16 — Automatic actions

**16.1 — Per-item log lines present** · read-only · all
**Given** a completed `DomainSync` run
**When** you check the cron task's log (Setup → Automatic actions → DomainSync)
**Then** you see a line per domain processed, naming the outcome, plus per-entity and per-registrar summary tallies

**16.2 — A failing domain does not block the queue** · read-only · all
**Given** one domain in the batch that will fail to sync (e.g. bad credentials)
**When** the cron runs
**Then** that domain's failure is logged and the rest of the batch still processes normally

---

## §17 — Uninstall

**17.1 — Clean removal** · destructive · all
**Given** Domain Manager installed with domains, suppliers, and records
**When** you uninstall it from Setup → Plugins
**Then** it uninstalls without error, and all plugin-owned tables/config/rights/cron tasks/dashboard are removed

**17.2 — What's retained, and why** · read-only · all
**Given** the same uninstall
**When** you check the native Domain/DomainRecord/Supplier/Infocom data afterward
**Then** it's all still there — the plugin never deletes native GLPI inventory data on uninstall

---

## Appendix A — Cloudflare-specific

Skip this whole section if you have no Cloudflare account to test against.

**A.1 — Proxy toggle** · live write
**Given** a Cloudflare-managed A/AAAA record
**When** you toggle "Proxied" on, then off
**Then** the toggle reflects at Cloudflare, and the panel's displayed IP switches between Cloudflare's proxy IP (proxied) and your origin IP (unproxied)

**A.2 — Apex CNAME allowed** · live write
**Given** a Cloudflare zone
**When** you create a CNAME at the domain's apex
**Then** it's accepted (Cloudflare's CNAME-flattening makes this valid here — the other two providers refuse this)

---

## Appendix B — Dinahosting-specific

Skip this whole section if you have no Dinahosting account to test against.

**B.1 — Super-admin credential requirement** · read-only
**Given** a Dinahosting sub-user or domain-limited account's credentials
**When** you save them and Check Connection
**Then** authentication fails — only the super-admin account's credentials work; this is a provider limitation, not a plugin bug

**B.2 — Two identical-name A records refuse to edit** · live write
**Given** two A records already sharing the same name at Dinahosting
**When** you try to edit either one
**Then** the plugin refuses, explaining it cannot safely edit one of a set, since Dinahosting's API can only delete all of them at once

---

## Appendix C — IONOS-specific

Skip this whole section if you have no IONOS account to test against.

**C.1 — Registrar Check Connection always reports "not implemented"** · read-only
**Given** an IONOS supplier with valid or invalid credentials — it doesn't matter which
**When** you press Check Connection
**Then** the DNS leg gives a real, credential-dependent result; the registrar leg always reads "not yet implemented for this driver" — a known, deliberate limitation. Registrar **sync** itself (§6) still works normally.

**C.2 — No apex CNAME** · read-only
**Given** an IONOS zone
**When** you try to create a CNAME at the domain's apex
**Then** it's refused — IONOS has no apex-alias support

---

## Known limitations

Documented so they aren't mistaken for bugs during UAT — none are fixed by this checklist.

- **RDAP-sourced fields have no visible Domain-form representation** (`rdap_registrar_name`,
  `rdap_registrar_iana_id`, `rdap_nameservers`, DNS write-editability status/message).
- **No user attribution in Domain history** for sync/RDAP/write actions (§13.2).
- **Cron ignores entity scope** by design (§15.2).
- **MX, NS, PTR, SOA, SRV, CAA are read/displayed but never writable** through this plugin by
  any driver — there is no live scenario for writing them.
