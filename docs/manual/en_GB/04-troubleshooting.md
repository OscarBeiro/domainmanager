# 4. Troubleshooting

> Generated for **domainmanager 1.7.1** on GLPI 11.0 — 2026-08-11.

*Part of the domainmanager manual — see also [1. Introduction](01-intro.md), [2. Setup](02-setup.md), [3. Usage](03-usage.md).*

**"Test" reports an error instead of Success.**
The badge shows the specific failure — Authentication failed, Forbidden, Not found, Rate limited, Upstream error, Network error, or Timeout — and the diagnostics panel keeps the provider's own message. Authentication failed and Forbidden almost always mean the credential is valid syntactically but missing a required scope (Cloudflare) or belongs to a non-super-admin account (Dinahosting) — see the driver-specific requirements in the main [README](../../README.md#configuration).

**The "New record" form doesn't appear on a domain's Records tab.**
Two independent causes, each shown as its own message in place of the form:
- *"Domain Manager is in read-only mode"* — a Setup → General → Domain Manager toggle disables all write-back cluster-wide; an administrator needs to turn it off.
- *"You do not hold write-back rights for any record type on this domain"* — your profile is missing the per-type (A/AAAA/CNAME/TXT) right for this record's type. Ask an administrator to grant it from the **Domain Manager** tab on your profile.

Even when neither message appears, write-back only exists for domains whose DNS provider is Cloudflare, IONOS, or Dinahosting — any domain whose provider was auto-detected from NS records (rather than configured as a driver) has no write-back at all.

**A field on the domain won't accept an edit.**
Fields Domain Manager fills from a sync (registration date, expiry, DNS record values, etc.) are locked against manual edits by default, so a sync can't be silently overwritten by a stale local edit. A user holding the **"Unlock imported domain data"** right can edit them anyway; grant it per profile from *Administration → Profiles → (profile) → Domain Manager*.

**Import lists domains I didn't expect, or is missing some.**
Import only lists what the provider's API actually returns for the credentials entered — if a domain is missing, check that the API token/account has access to it at the provider directly. A domain flagged "Registrar mismatch" already exists in GLPI under a different Supplier; reassign it from the same dialog rather than importing a duplicate.
