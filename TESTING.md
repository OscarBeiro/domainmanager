# Testing checklist — Domain Manager

Cumulative manual regression checklist. Each item maps back to the requirement it
verifies (§ references point to `ARCHITECTURE.md`). Append new items per phase; do
not remove earlier ones.

**Environment:** GLPI 11.0.x with this repo available as `plugins/domainmanager`.
The commands below assume the podman rig from `~/containers/glpi` (GLPI 11.0.8 at
`glpi_glpi_1`, MariaDB at `glpi_db_1`, web login `glpi`/`glpi`); adapt paths for any
other instance. DB shell:

```bash
podman exec glpi_db_1 mariadb -uglpi -pglpi glpi -e "<SQL>"
```

---

## Phase 1 — Foundation (setup, installer, right, cron shell)

### 1.1 Clean install from CLI (§7, §9-P1)
- **Steps:**
  ```bash
  podman exec glpi_glpi_1 php /var/www/glpi/bin/console glpi:plugin:install domainmanager
  podman exec glpi_glpi_1 php /var/www/glpi/bin/console glpi:plugin:activate domainmanager
  ```
- **Expected:** both commands succeed; `SHOW TABLES LIKE 'glpi_plugin_domainmanager%'`
  lists exactly `_supplierconfigs`, `_states`, `_records`, `_locks`.
- [ ] Pass

### 1.2 Seeds present and idempotent (§2, §7)
- **Steps:** after install, run install again (expect "already installed" refusal),
  then check:
  `SELECT COUNT(*) FROM glpi_domaintypes WHERE name='Internet Domain';`
  `SELECT name FROM glpi_domainrecordtypes WHERE name IN ('A','AAAA','CNAME','MX','NS','TXT');`
- **Expected:** exactly 1 "Internet Domain" row; all six record types exist; no duplicates.
- [ ] Pass

### 1.3 Plugin right registered with correct defaults (§8)
- **Steps:** `SELECT rights, COUNT(*) FROM glpi_profilerights WHERE name='domainmanager:unlock_imported' GROUP BY rights;`
- **Expected:** one row per profile; `rights=1` only for profiles holding *config*
  UPDATE (default: Super-Admin), `rights=0` for the rest.
- [ ] Pass

### 1.4 Profile tab rights matrix (§8)
- **Steps:** log in as glpi → *Administration → Profiles → (any profile)* → tab
  **Domain Manager**; toggle *Unlock imported domain data* and save; reload the tab.
- **Expected:** tab renders a one-row matrix; the checkbox state persists;
  `glpi_profilerights` reflects the change for that profile.
- [ ] Pass

### 1.5 Automatic action registered and tunable (§6.4)
- **Steps:** *Setup → Automatic actions* → search `DomainSync`.
- **Expected:** task exists on itemtype `GlpiPlugin\Domainmanager\Cron`, daily
  frequency, run window 23–24h, parameter (batch size) 20, logs kept 30 days; all
  fields editable.
- [ ] Pass

### 1.6 Cron shell executes (§9-P1)
- **Steps:**
  ```bash
  podman exec glpi_glpi_1 php /var/www/glpi/front/cron.php --force DomainSync
  ```
  (`--force` must precede the task name.) Then check the task log in
  *Setup → Automatic actions → DomainSync → Executions*.
- **Expected:** run completes; log contains "Domain Manager sync engine is not
  implemented yet; nothing to do." and ends with "Action completed, no processing
  required"; `lastrun` is set.
- [ ] Pass

### 1.7 Residue-free uninstall, native data kept (§7)
- **Steps:**
  ```bash
  podman exec glpi_glpi_1 php /var/www/glpi/bin/console glpi:plugin:deactivate domainmanager
  podman exec glpi_glpi_1 php /var/www/glpi/bin/console glpi:plugin:uninstall domainmanager
  ```
  Then check: plugin tables, `glpi_profilerights WHERE name LIKE 'domainmanager%'`,
  `glpi_crontasks WHERE itemtype LIKE '%Domainmanager%'`, orphan `glpi_crontasklogs`,
  `glpi_displaypreferences WHERE itemtype LIKE 'GlpiPlugin\\\\Domainmanager%'`.
- **Expected:** all counts 0 and no plugin tables remain; **but** the "Internet
  Domain" type and the six record types remain (user inventory is never removed).
- [ ] Pass

### 1.8 Reinstall after uninstall (§7)
- **Steps:** run 1.1 again after 1.7.
- **Expected:** identical result to a first install; no duplicated seeds or task.
- [ ] Pass

---

## Phase 2 — Itemtypes, Supplier credentials tab, NS provider registry

### 2.1 Supplier tab visibility gated by right (§6.1, §8)
- **Steps:** open any supplier (*Management → Suppliers*) as a user whose profile
  has supplier READ but **not** supplier UPDATE; then as a user with supplier
  UPDATE.
- **Expected:** the **Domain Manager** tab appears for both (supplier READ — i.e.
  being able to open the supplier — suffices); the form (driver select + save) is
  editable only with supplier UPDATE, entity-aware — otherwise the tab is a
  read-only driver display. (Amended 2026-07-19: the original gating on *config*
  READ/UPDATE failed — the profile UI exposes no usable config READ; the tab now
  follows native supplier rights, `contact_enterprise`.)
- [ ] Pass

### 2.2 Credentials stored encrypted (§6.1, GLPIKey)
- **Steps:** on a supplier's Domain Manager tab select driver *Cloudflare*, enter API
  Token `secret-token-123`, save. Then:
  `SELECT api_driver, api_credentials FROM glpi_plugin_domainmanager_supplierconfigs;`
- **Expected:** save redirects back to the supplier tab; `api_driver='cloudflare'`;
  `api_credentials` is a non-empty encrypted blob that does **not** contain
  `secret-token-123`.
- [ ] Pass

### 2.3 Secrets never echoed back (§6.1)
- **Steps:** reload the tab after 2.2; view page source of the token input.
- **Expected:** the token input is empty (type `password`) with placeholder
  "●●●●●●●● (saved, leave empty to keep)"; the plaintext token appears nowhere in
  the HTML.
- [ ] Pass

### 2.4 Empty submit keeps the stored secret (§6.1)
- **Steps:** with the token saved, submit the form again leaving the token empty.
- **Expected:** the stored token is preserved (re-check with 2.2's SQL: blob
  unchanged, still decryptable — or verify later against the real API in Phase 3).
- [ ] Pass

### 2.5 Submitting a new secret replaces the old one
- **Steps:** enter a different token and save.
- **Expected:** save succeeds; the encrypted blob changes.
- [ ] Pass

### 2.6 Switching driver drops the other driver's credentials
- **Steps:** switch the driver to *Dinahosting*, fill username `testuser` +
  password, save; reload tab.
- **Expected:** username is prefilled (non-secret), password shows the "saved"
  placeholder; the previously stored Cloudflare token is gone from the stored JSON
  (only `user`/`password` keys remain).
- [ ] Pass

### 2.7 Per-driver fields toggle without reload (§6.1)
- **Steps:** on the tab, cycle the driver select through all four options.
- **Expected:** only the selected driver's fields are visible/enabled — Cloudflare:
  API Token; IONOS: API Key + API Secret; Dinahosting: Username + Password; None:
  no fields.
- [ ] Pass

### 2.8 Form POST is rights- and CSRF-protected (§6.1, §0.5)
- **Steps:** POST to `/plugins/domainmanager/front/supplierconfig.form.php` without
  a CSRF token (e.g. curl with a valid session cookie); then, in the UI, try saving
  as a user without supplier UPDATE (edit the form's HTML to re-enable it if
  needed).
- **Expected:** missing/invalid CSRF token is rejected by core; the save without
  supplier UPDATE on the target supplier is refused (access denied). (Amended
  2026-07-19 with the 2.1 rights realignment.)
- [ ] Pass

### 2.9 Supplier purge cascade (§6.2)
- **Steps:** purge (delete permanently) a supplier that has a saved configuration
  and is referenced by a row in `glpi_plugin_domainmanager_states`
  (`registrar_suppliers_id`/`dns_suppliers_id`).
- **Expected:** its `supplierconfigs` row is deleted; both supplier references in
  `states` rows are reset to 0.
- [ ] Pass

### 2.10 NS registry file valid with sourced patterns (§4)
- **Steps:** `php -r 'json_decode(file_get_contents("resources/ns-providers.json"), true, 512, JSON_THROW_ON_ERROR); echo "ok\n";'`
  and review each entry.
- **Expected:** valid JSON; the first 3 providers (Cloudflare, IONOS, Dinahosting)
  each have a `driver` matching a hardcoded driver key and a `source` URL
  documenting the pattern. (Phase 5 appends detection-only entries after them —
  see 5.1.)
- [ ] Pass

### 2.11 NS matching semantics (§4)
- **Steps:** exercise `NsProviderRegistry::match()` (e.g. via a scratch script in
  the container) with: `ADA.NS.CLOUDFLARE.COM.` (uppercase + trailing dot),
  `ns1042.ui-dns.biz`, `ns.dinahosting.com`, `blue.foundationdns.com`,
  `ns1.example.com`, and an empty list.
- **Expected:** Cloudflare, IONOS, Dinahosting, Cloudflare, `null` (unknown),
  `null` respectively — case-insensitive, trailing dot stripped, wildcards per
  fnmatch, first matching entry in file order wins. (The original fifth input,
  `ns-123.awsdns-45.net`, expected `null` before Phase 5; it now matches the
  detection-only "AWS Route 53" entry — see 5.2.)
- [ ] Pass

### 2.12 Malformed registry entries are skipped, not fatal (§4)
- **Steps:** temporarily add an entry without `patterns` (or with an unknown
  `driver`) to a **copy** of the registry on a writable instance, reload.
- **Expected:** the bad entry is ignored, remaining providers still match, and a
  line is appended to `files/_log/plugin_domainmanager.log`.
- [ ] Pass

---

## Phase 3 — Drivers, sync engine, reconciliation, lock enforcement

### 3.1 Zone record validation (§5 sanitisation)
- **Steps:** construct `Dto\ZoneRecord` with an unsupported type (`SRV`), an
  oversized name (>255 chars), and a valid lowercase type (`a`).
- **Expected:** the first two throw `InvalidArgumentException` (drivers skip such
  records); the third normalizes to `A`; `getHash()` is identical for equal
  type/name/data/ttl regardless of remote id.
- [ ] Pass

### 3.2 Idempotent record import (§5.4)
- **Steps:** call `RecordReconciler::reconcile()` on a domain with a fixed
  upstream snapshot (A + MX + TXT); run it twice.
- **Expected:** first run adds 3 native records with ownership rows
  (`glpi_plugin_domainmanager_records`); second run reports 3 unchanged, 0
  added — never duplicates.
- [ ] Pass

### 3.3 Upstream change updates in place (§5.4)
- **Steps:** change the content of a record that has a `remote_id` and re-reconcile.
- **Expected:** the same native record row is updated (no new row); the ownership
  hash is refreshed.
- [ ] Pass

### 3.4 Upstream removal flags stale, never deletes (§5.4)
- **Steps:** drop one record from the upstream snapshot and re-reconcile.
- **Expected:** native record still exists; its comment gains the
  "[Domain Manager] Not present upstream since <date>" marker; ownership row has
  `is_stale = 1`.
- [ ] Pass

### 3.5 Reappearance restores (§5.4)
- **Steps:** re-add the dropped record and re-reconcile.
- **Expected:** counted as restored; stale marker removed; `is_stale = 0`.
- [ ] Pass

### 3.6 Locked domain fields enforced server-side (§0.3, §8)
- **Steps:** after a sync wrote locks (or via `ImportLock::replaceLocks`), edit the
  domain's `is_active`/expiration as a user **without**
  `domainmanager:unlock_imported`; then grant the right and retry. Also change an
  unlocked field (e.g. comment) without the right.
- **Expected:** without the right the locked change is stripped with a session
  warning while the unlocked field saves; with the right the change goes through.
  Enforcement also applies to massive actions and API (hook level).
- [ ] Pass

### 3.7 Imported records shielded (§0.3, §8)
- **Steps:** on a plugin-imported domain record, as a user without the unlock
  right: change data; soft-delete; purge. Then retry purge with the right.
- **Expected:** content change is stripped with a warning; delete and purge are
  refused with an error; with the right the purge succeeds and its ownership row
  is removed (so the next sync re-imports it).
- [ ] Pass

### 3.8 Domain purge cascade (§6.2)
- **Steps:** purge a synced domain.
- **Expected:** its state row, record-ownership rows and lock rows are all deleted.
- [ ] Pass

### 3.9 IONOS registrar and DNS legs are both real (§3.9, updated — registrar implemented per addendum "Implement the Real IONOS Driver")
- **Steps:** configure a supplier with the IONOS driver (real key/secret if
  available) and run a sync using it.
- **Expected:** the registrar leg calls the live IONOS Domains API
  (`https://api.hosting.ionos.com/domains/v1`, a separate product/base URL
  from the DNS API below) and either ends `ok` with `date_expiration`
  populated (`date_domaincreation` stays null — IONOS's Domains API exposes
  no registration/creation date at all) or reports a real, specific error
  (e.g. "Domain is not managed by this IONOS account" for a 404, §3.18); the
  DNS leg actually calls the live IONOS DNS API and either imports real
  A/AAAA/CNAME/MX/TXT records (MX prefixed with priority, TXT unquoted —
  see §3.9) or reports a real per-error message if the zone/key is wrong.
  Neither leg aborts the other. See 3.17/3.18 for the detailed real-token
  registrar pass.
- [ ] Pass

### 3.10 Engine statuses and leg isolation (§5)
- **Steps:** sync a domain with: no registrar supplier configured; a nonexistent
  FQDN; NS pointing at an unknown provider; NS pointing at a supported provider
  with no credentialed supplier.
- **Expected:** `registrar_status = unconfigured`; `dns_status` respectively
  `error` ("NS lookup failed"), `unknown`, `unconfigured`; every combination still
  upserts the state row with `last_sync_date`, and one leg failing never prevents
  the other from running.
- [ ] Pass

### 3.11 Real Cloudflare sync (manual, needs a real API token)
- **Steps:** save a Cloudflare API token (Zone.DNS read + optionally Registrar
  read) on a supplier; run `SyncEngine::sync()` (or, from Phase 4, "Update Now")
  against a domain whose zone the token can read.
- **Expected:** DNS leg imports only A/AAAA/CNAME/MX/NS/TXT records with correct
  data (MX prefixed with priority) and `dns_status = ok`; registrar leg fills
  `date_domaincreation`/`date_expiration`/`is_active` and locks them when the
  domain is on Cloudflare Registrar, or reports a clear per-leg error otherwise;
  the token never appears in messages, history or logs.
- [ ] Pass

### 3.12 managed_domainrecordtypes gate detection (§0.4)
- **Steps:** restrict a profile's *Manageable domain record types* to exclude e.g.
  TXT, then run a web-session sync (not cron) for a domain with a credentialed
  DNS provider.
- **Expected:** the DNS leg stops before importing anything, with
  `dns_status = error` and a message listing the missing record types; nothing is
  half-imported.
- [ ] Pass

### 3.13 Real Dinahosting sync (§3.8, needs real Dinahosting credentials)
- **Steps:** save real Dinahosting username/password on a supplier; run
  `SyncEngine::sync()` (or "Update Now") against a domain registered/hosted on
  that account.
- **Expected:** DNS leg imports A/AAAA/CNAME/TXT records with correct data
  (verified field names); MX/NS records may have unconfirmed field mapping —
  cross-check their `data` value against the real DNS zone and report back if
  wrong (see §3.8's documented gap) so the field names can be corrected.
  Registrar leg fills `date_domaincreation`/`date_expiration`/`is_active` from
  `Domain_GetRegistrationDate`/`Domain_GetExpirationDate`; a registrar-hold
  domain will currently show as `ok` rather than `suspended` (no confirmed
  status-check command exists yet — known gap, not a bug to "fix" without a
  documented response shape). The password never appears in messages,
  history, or `domainmanager.log`/`domainmanager-errors.log`.
- [ ] Pass

### 3.14 Dinahosting domain-not-managed error (§3.8)
- **Steps:** with valid Dinahosting credentials, run a sync/fetch against a
  domain name that is valid but NOT registered under that account.
- **Expected:** a clear `DriverException` message ("Domain is not managed by
  this Dinahosting account"), not a generic/unhelpful error — confirms the
  `2303` (`OBJECT_NOT_EXISTS`) response code is mapped correctly.
- [ ] Pass

### 3.15 Real IONOS DNS sync (§3.9, needs real IONOS key/secret)
- **Steps:** save a real IONOS API key/secret on a supplier; run
  `SyncEngine::sync()` (or "Update Now") against a domain whose zone the key
  can read.
- **Expected:** DNS leg imports A/AAAA/CNAME/MX/TXT records with correct data
  (MX prefixed with priority from the `prio` field; TXT content unquoted —
  IONOS returns it double-quoted) and `dns_status = ok`; the key/secret never
  appear in messages, history, or `domainmanager.log`/`domainmanager-errors.log`.
- [ ] Pass

### 3.16 IONOS zone-not-found error (§3.9)
- **Steps:** with a valid IONOS key/secret, run a sync/fetch against a domain
  name whose DNS zone is not managed under that account.
- **Expected:** a clear `DriverException` message ("No IONOS DNS zone found
  for `<domain>` with this key"), not a generic/unhelpful error.
- [ ] Pass

### 3.17 Real IONOS registrar/lifecycle sync (manual, needs real IONOS Domains API credentials — addendum "Implement the Real IONOS Driver")
- **Steps:** re-run the three IONOS-registrar domains from the original bug
  report (`adegamoraima.com`, `beiro.net`, `desmarque.es`) with real IONOS
  API key/secret saved on the resolved supplier; run
  `SyncEngine::sync()`/"Update Now"/cron for each.
- **Expected:** each domain now shows a real, **per-domain** registrar
  result (`date_domaincreation` stays null — IONOS's Domains API exposes no
  registration/creation date at all, confirmed absent from its spec —
  `date_expiration` populated from `expirationDate`, `is_active` set
  correctly), instead of an identical generic "Error" on all three as
  before this change. A domain still mid-registration/transfer
  (`provisioningStatus.type = REGISTRATION_IN_PROGRESS`) reports the new
  `LifecycleStatus::Pending` (shows as `is_active = 0`, same as any other
  non-Ok state — verify this doesn't crash/mis-render anywhere it's
  displayed). The key/secret never appear in messages, history, or either
  log file.
- [ ] Pass

### 3.18 IONOS domain-not-managed error (registrar API, addendum)
- **Steps:** with valid IONOS Domains API credentials, run a sync/fetch
  against a domain name that is valid but not registered under that
  account (or is registered under a *different* IONOS account than the
  one the credentials belong to).
- **Expected:** a clear `DriverException` message ("Domain is not managed
  by this IONOS account"), not a generic/unhelpful error or a leaked raw
  API error body — confirms the Domains API's 404 response is mapped
  correctly, and that its two-shaped error envelope (single `{message}`
  object at the gateway layer vs. a `[{code, message}]` array at the
  application layer) is both handled without crashing.
- [ ] Pass

### 3.19 Dinahosting `Domain_GetRegistrationDate` semantics (§3.8, opportunistic — needs a transferred-in domain)
- **Steps:** find a domain in a real Dinahosting account that is known to
  have been **transferred in** from a different registrar (not one
  originally registered through Dinahosting). Run `SyncEngine::sync()`
  (or "Update Now") against it and note the resulting
  `date_domaincreation`. Separately look up that same domain's real
  WHOIS/RDAP "Creation Date".
- **Expected:** the two dates match, confirming `Domain_GetRegistrationDate`
  returns the domain's real/original registry creation date (as currently
  assumed/documented in §3.8) rather than the date it was added to this
  Dinahosting account. If they *don't* match, this is a real bug: update
  §3.8's note and treat `DomainLifecycle::$registrationDate` as unreliable
  for this driver until fixed.
- **Caveat:** genuinely opportunistic — transferring a domain into an
  account isn't a routine, frequent event, so this may stay unchecked for
  a long time. Not a blocker for anything; run it whenever a
  transferred-in domain happens to be available.
- [ ] Pass

---

## Phase 4 — Domain panel, Update Now, cron batching

### 4.1 Registrar field on the domain form mirrors Infocom, read-only (§0.1, §6.2, §9 Phase 5.5)
- **Steps:** open a domain; the Domain Manager panel shows a **Registrar**
  value (never an editable dropdown, regardless of rights) with a "Set via
  the Financial and administrative information tab" link. Open that Infocom
  tab, change its **Supplier** field, save; reopen the domain's main tab.
  Then clear Infocom's Supplier field (save) and reopen again.
- **Expected:** the Domain Manager panel's Registrar value always matches
  Infocom's Supplier field exactly, both directions (never diverges); it is
  never itself editable/clickable-into-an-input from the Domain Manager
  panel; clearing Infocom's Supplier makes the panel show "No registrar
  supplier set". Confirm the mirror lands in
  `glpi_plugin_domainmanager_states.registrar_suppliers_id` (`SELECT
  registrar_suppliers_id FROM glpi_plugin_domainmanager_states WHERE
  domains_id=<id>` should equal `glpi_infocoms.suppliers_id` for that
  domain's Infocom row) — `glpi_domains` itself is never touched (§0.1).
- [ ] Pass

### 4.1.1 Registrar change logs to both native History and the plugin's own entry (§3.7, §9 Phase 5.5)
- **Steps:** as 4.1, change Infocom's Supplier field and save, then open the
  Domain's own **Historical** tab.
- **Expected:** two entries appear for that change — GLPI's own native
  Infocom-field-change entry (field "Supplier") **and** the plugin's own
  "Registrar supplier set to X"/"...changed from X to Y"/"...cleared" entry
  (field "Domain Manager", §3.7) — both landing at the same timestamp.
- [ ] Pass

### 4.2 Status table and DNS provider display (§6.2, §6.5)
- **Steps:** open a synced domain with *domain* READ.
- **Expected:** panel shows a ribbon-banner header ("Domain Manager", same
  treatment as every other Domain Manager panel, §6.5) with **Update Now**
  inside it, then a one-row table with columns Registrar, DNS/NS Provider
  (hyperlinked to that supplier's own Domain Manager tab when resolved,
  plain text otherwise), Registrar sync, DNS sync (badges colored by status
  — green ok, red error, grey never/unconfigured, yellow
  unsupported/unknown), and Last sync. Per-leg detail messages sit below
  the table. A domain never synced shows "Never synchronized" in the DNS
  Provider column.
- [ ] Pass

### 4.2.1 Registrar and DNS Provider render identically and hyperlink correctly (§6.2)
- **Steps:** (a) open a domain with no registrar set and an unsupported or
  unknown detected provider. (b) open a domain with both a registrar
  supplier set (via Infocom) and a DNS-driver-matched, configured supplier.
- **Expected:** (a) both values render as plain text, same font
  weight/size/style, no box/select styling, no link cursor/underline.
  (b) both values render as real, working hyperlinks (`Supplier::getLinkURL()`
  + `forcetab`) landing on the correct Supplier's Domain Manager tab.
- [ ] Pass

### 4.2.2 One-row table layout matches the Domains list's column shape (§6.2, §6.5)
- **Steps:** open a domain where registrar and DNS have different statuses
  (e.g. registrar `error`, DNS `ok`); compare the panel's table against the
  Supplier tab's "Domains" list table for the same domain.
- **Expected:** same table styling (`table table-sm`, same header-row
  treatment); Registrar's value/badge and DNS's value/badge sit in their
  own columns, never mixed into one cell. On a narrow viewport the table
  degrades the same way any native GLPI table does (column compression,
  not a custom breakpoint unique to this plugin) — verified in this
  session down to a 420px-wide viewport, no overlap/breakage.
- [ ] Pass

### 4.2.3 "Not configured" reflects staleness, not a lookup bug (§6.2, investigated 2026-07-21)
- **Steps:** configure a Supplier with a real driver + credentials (e.g.
  Dinahosting) whose NS a domain genuinely resolves to; if that domain's
  state was last computed *before* the credentials were saved, its DNS
  badge will show "Not configured" with "No supplier is configured with
  the X driver" even though a matching Supplier now exists. Click
  **Update Now**.
- **Expected:** confirmed **not a bug** — `SyncEngine::findSupplierConfigForDriver()`
  correctly finds a Supplier with matching `api_driver` and non-empty
  decrypted credentials; reproduced live with a real Supplier + real NS
  match (`dinahosting.com`, genuinely NS-hosted at Dinahosting) and got a
  real API call (a genuine auth error, not "unconfigured"). After
  **Update Now**, the badge must change to a real `ok`/`error` status — if
  it doesn't, that would be a real regression worth re-opening.
- [ ] Pass

### 4.2.4 All four Domain Manager panels share one visual design (§6.5)
- **Steps:** side by side, open the Supplier tab (showing "Domain Manager
  API access", "Connection diagnostics", and "Domains") and a Domain form
  (showing "Domain Manager").
- **Expected:** all four panels have the identical ribbon-banner header
  (same blue folded-ribbon icon badge, same title typography/position, same
  `card m-n2 border-0 shadow-none` flat-card treatment) — confirmed via
  real browser screenshots in this session (not just markup diffing).
  "Connection diagnostics" shows **one** combined status indicator (a
  Supplier's connection test is one login probe — confirmed intentional,
  not reverted back to separate Registrar/DNS rows); the Domain form panel
  and the "Domains" list both show Registrar and DNS as genuinely separate
  columns (they can differ per domain, unlike a single connection test).
  Every badge across all four panels renders from the same
  `status_classes`/`status_labels` pattern — no panel has a differently-
  colored or differently-shaped pill for a status of the same kind.
- [ ] Pass

### 4.2.5 `path()`-based fetch URLs still work correctly (§6.5)
- **Steps:** on both "Check Connection" (Supplier tab) and "Update Now"
  (Domain form), inspect the actual `fetch()` URL sent (browser dev tools
  or `podman logs`) after the `path()` fix.
- **Expected:** identical URL as before the fix on this root-installed dev
  environment (`/plugins/domainmanager/connectiontest/<id>` /
  `/plugins/domainmanager/sync/<id>`) — verified live in this session,
  including one real end-to-end "Update Now" POST that returned a correct
  200 with real sync results. Not independently verifiable in this
  environment: a subdirectory-installed GLPI (`$CFG_GLPI['root_doc']` !=
  `''`) should now get that prefix correctly prepended, which the earlier
  raw-string-concatenation fix would have silently omitted — reasoned from
  reading `Glpi\Application\View\Extension\RoutingExtension::path()` and
  `Html::getPrefixedUrl()` directly (§6.5), not assumed.
- [ ] Pass

### 4.3 Update Now (§6.3)
- **Steps:** with *domain* UPDATE, click **Update Now** on a domain.
- **Expected:** POST to `/plugins/domainmanager/sync/{id}` with the
  `X-Glpi-Csrf-Token` header; the button spins, then badges, messages, provider
  and last-sync refresh in place without a page reload. Errors surface as an alert.
- [ ] Pass

### 4.4 Sync endpoint is rights- and CSRF-gated (§6.3, §8)
- **Steps:**
  ```bash
  curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:11108/plugins/domainmanager/sync/1
  ```
  and, authenticated as a user without *domain* UPDATE (or outside the domain's
  entity), trigger the same POST from the browser console.
- **Expected:** unauthenticated/tokenless POST → 403 (never 404: route exists);
  authenticated but unauthorized → 403 JSON error; entity scope enforced via
  `can()`.
- [ ] Pass

### 4.5 Unsupported/unknown provider warning (§4, §6.2)
- **Steps:** sync a domain whose NS point at a provider without a driver (e.g.
  AWS Route 53) and one whose NS match nothing in the registry.
- **Expected:** the panel shows the yellow warning ("API integration for X is not
  currently supported." / provider unknown) with the "Help us support this
  provider" link to the plugin repository.
- [ ] Pass

### 4.6 Lock JS on the domain form (§0.3 cosmetic layer)
- **Steps:** after a registrar sync locked fields, open the domain as a user
  without `domainmanager:unlock_imported`; then as a right holder.
- **Expected:** without the right, locked inputs (name, dates, is_active) are
  disabled with a lock icon on their labels; with the right, all inputs are
  editable. (Server-side stripping — item 3.6 — protects regardless of the JS.)
- [ ] Pass

### 4.7 Cron batching loop (§5, §6.4)
- **Steps:** with several domains (active, inactive, deleted, template) and the
  task parameter set to a small batch size, force the run:
  ```bash
  podman exec glpi_glpi_1 php /var/www/glpi/front/cron.php --force DomainSync
  ```
- **Expected:** only active, non-deleted, non-template domains are processed,
  least-recently-synced first, at most `param` per run; a failing domain does not
  abort the batch; the task log reports "Synchronized N domain(s), M with errors"
  and the volume counter equals N.
- [ ] Pass

---

## Phase 5 — NS registry sweep, batch 1 (detection-only providers) + UI icon

### 5.1 Registry sweep entries valid and sourced (§4, §9-P5)
- **Steps:** `php -r 'json_decode(file_get_contents("resources/ns-providers.json"), true, 512, JSON_THROW_ON_ERROR); echo "ok\n";'`
  and review the entries after the three driver-backed ones.
- **Expected:** valid JSON; 11 providers total. Entries 4–11 are AWS Route 53,
  Google Cloud DNS, Azure DNS, GoDaddy, OVHcloud, DigitalOcean, Linode (Akamai)
  and Vercel; each has non-empty `patterns`, a `source` URL pointing at official
  vendor documentation of the nameserver hostnames, and **no** `driver` key. The
  driver-backed three remain first so first-match order is unchanged.
- [ ] Pass

### 5.2 New providers are detected as unsupported (§4, §5, §6.3)
- **Steps:** sync a domain whose live NS records sit on one of the new providers
  (e.g. Route 53: `ns-*.awsdns-*.com`), via *Update Now* or the cron; open the
  domain's Domain Manager panel and check the state row:
  `SELECT detected_provider, dns_status FROM glpi_plugin_domainmanager_states WHERE domains_id=<ID>;`
- **Expected:** `detected_provider` shows the registry display name (e.g.
  "AWS Route 53"), `dns_status='unsupported'`; the panel renders the *"API
  integration for AWS Route 53 is not currently supported."* warning with the
  contribution link; the registrar leg still runs normally.
- [ ] Pass

### 5.3 Detection-only entries never enter the DNS pipeline (§4, §5)
- **Steps:** with at least one supplier holding valid Cloudflare credentials,
  sync a domain hosted on a detection-only provider (as in 5.2); watch
  `files/_log/plugin_domainmanager.log` and the state row.
- **Expected:** no DNS supplier is resolved (`dns_suppliers_id` stays 0), no
  provider API call is attempted, no records are imported; `dns_status` is
  `unsupported`, not `error`.
- [ ] Pass

### 5.4 No false positives on adjacent hostnames (§4)
- **Steps:** exercise `NsProviderRegistry::match()` with
  `ns1.googledomains.com` (legacy Google Domains, not Cloud DNS),
  `ns201.anycast.me`, `ns4.digitalocean.com` and `ns6.linode.com`.
- **Expected:** all four return `null` (provider "Unknown") — the narrow
  patterns (`ns-cloud-*` prefix, literal `ns200`/`dns200.anycast.me`,
  `ns[1-3].digitalocean.com`, `ns[1-5].linode.com`) do not overmatch.
- [ ] Pass

### 5.5 `ti-world-cog` icon on every Domain Manager mention
- **Steps:** open (a) a supplier's **Domain Manager** tab, (b) *Administration →
  Profiles →* any profile's **Domain Manager** tab, (c) a domain form (panel
  header card), (d) the supplier tab's "Domain Manager API access" card title.
- **Expected:** all four show the Tabler *world-cog* icon — the two tabs render
  it via the classes' `getIcon()` through `createTabEntry()`; the domain panel
  header uses `ti ti-world-cog` (no longer `ti-world-www`); the supplier card
  title carries the icon too.
- [ ] Pass

### 5.6 Registry sweep batch 2 entries valid and sourced (§4, §9-P5)
- **Steps:** `php -r 'json_decode(file_get_contents("resources/ns-providers.json"), true, 512, JSON_THROW_ON_ERROR); echo "ok\n";'`
  and review the entries appended after Vercel.
- **Expected:** valid JSON; 10 more providers appended — Gandi, Namecheap,
  Hetzner, Squarespace, Wix, Hostinger, Porkbun, cdmon, one.com, and
  NS1 (IBM NS1 Connect) — each with non-empty `patterns`, a `source` URL
  pointing at official vendor documentation, and **no** `driver` key. Strato
  and Arsys were investigated but **deliberately omitted**: no authoritative
  official-vendor page stating their nameserver hostnames could be found
  (only third-party/community sources), so no entry was added rather than
  guessing — flagged to Óscar; add them once an official source surfaces.
- [ ] Pass

### 5.7 Batch 2 providers are detected as unsupported, no collisions (§4, §5, §6.3)
- **Steps:** sync a domain whose live NS records sit on one of the batch 2
  providers (e.g. one.com: `ns01.one.com`/`ns02.one.com`); also exercise
  `NsProviderRegistry::match()` directly with `ns1.googledomains.com` and any
  Squarespace-hosted domain using `nsone.net`-suffixed nameservers.
- **Expected:** the batch 2 domain shows `detected_provider` = the registry
  display name and `dns_status='unsupported'` with the contribution-link
  warning, same as batch 1. The Squarespace/NS1 case in particular must
  resolve to **NS1** (or Google Cloud DNS for `googledomains.com`), not
  Squarespace — the Squarespace entry's patterns deliberately exclude
  `googledomains.com`/`nsone.net` to avoid a false collision (§4 registry
  entry note); confirm first-match file order still gives the right result.
- [ ] Pass

> The "Test credentials" flat feature originally sketched in this section
> (stored-credentials-only, single `SupplierConfigTestController`) never
> shipped and has been replaced before release by the richer per-capability
> "Check Connection" diagnostics — see **Phase 3.5** below.

---

## Phase 3.5 — Connection diagnostics (§3.5, §3.6)

### 3.5.1 Happy path — Cloudflare DNS capability (§3.5.1, §6.1)
- **Steps:** on a supplier with driver *Cloudflare* and a **valid** API token
  saved, click **Check Connection** on the Domain Manager tab.
- **Expected:** a green `glpi_toast_success` toast captioned "Connection"
  appears; the detail panel's single Connection badge turns green ("Success"),
  shows HTTP 200 and an updated "Last checked" timestamp (Cloudflare only ever
  had one capability to show here anyway, §3.5.1).
- [ ] Pass

### 3.5.2 Auth failure persists the error (§3.5.2, §2)
- **Steps:** edit the Cloudflare token field to an invalid value (do **not**
  save) and click **Check Connection**.
- **Expected:** a red `glpi_toast_error` toast with the auth-failed message;
  the Connection badge turns red with HTTP 401 and the message
  "Authentication failed — the API token or credentials were rejected.";
  since this supplier already has a saved `supplierconfigs` row, the result
  (status `auth_failed`, http code 401, message, timestamp) is persisted to
  `dns_test_*` columns — reload the page and confirm the panel still shows it
  without re-testing.
- [ ] Pass

### 3.5.3 IONOS: registrar not-implemented, DNS is a real check (§3.5.1, §3.9, §6.1)
- **Steps:** select driver *IONOS*, fill in (a) deliberately wrong key/secret,
  or (b) real IONOS key/secret if available, click **Check Connection**.
- **Expected:** the UI only shows **one** Connection badge, driven by the
  `dns` result (§6.1's `getPrimaryTestableCapability()` — `registrar` is
  still tested and persisted in the background per §3.9, but never
  surfaced): (a) wrong credentials → red badge, HTTP 401, "Authentication
  failed — the API token or credentials were rejected."; (b) valid
  credentials → green badge, "Connection successful." Confirm via a direct DB
  read (`SELECT registrar_test_status, dns_test_status FROM
  glpi_plugin_domainmanager_supplierconfigs WHERE id=<id>`) that
  `registrar_test_status` is still being recorded as `unknown_error`
  ("Not yet implemented for this driver") even though nothing in the UI shows
  it.
- [ ] Pass

### 3.5.4 Unsaved credentials are tested but never persisted (§3.5.3)
- **Steps:** (a) on an **existing saved** supplier config, edit a credential
  field without saving, click Check Connection. (b) On a **brand-new
  supplier** with no `supplierconfigs` row yet, pick a driver, fill in
  credentials, click Check Connection without ever saving first.
- **Expected:** (a) tests the new unsaved value (not the previously-saved
  one) and **does** persist the result to the existing row (`SupplierConfig`
  row already exists). (b) tests and shows the result in the toast/panel but
  **does not create** a `supplierconfigs` row — confirm via
  `SELECT * FROM glpi_plugin_domainmanager_supplierconfigs WHERE
  suppliers_id = <id>` returning no row after the test.
- [ ] Pass

### 3.5.5 Empty secret field falls back to the stored value (§6.1)
- **Steps:** on a supplier with a saved Cloudflare token, clear the token
  field (leave it empty, same driver selected) and click Check Connection.
- **Expected:** the test runs against the **still-stored** token (mirrors the
  save form's "empty submit keeps the stored secret" semantics) rather than
  failing on an empty credential.
- [ ] Pass

### 3.5.5.1 CRITICAL REGRESSION: credentials survive repeated connection tests and syncs (§3.5.3, fixed 2026-07-21)
- **Steps:** save real (or real-shaped) credentials for a supplier. Click
  **Check Connection** three or more times in a row, with no other action
  in between. Separately: with the same saved credentials, trigger
  **Update Now** on a domain synced through that supplier, more than once.
- **Expected:** every single click/sync returns the same real, credential-dependent
  result (e.g. a live `auth_failed`/401 or a real success) — **never**
  "API key/secret are not configured" after the first click. Confirm via
  DB: `SELECT LENGTH(api_credentials) FROM
  glpi_plugin_domainmanager_supplierconfigs WHERE suppliers_id=<id>` must
  stay constant (non-zero) across every click. **Root cause of the
  original bug:** `recordConnectionTestResults()`'s `update()` call (only
  touching `*_test_*` columns) was misread by
  `SupplierConfig::prepareInputForUpdate()` as a driver-less credentials
  save, silently wiping `api_credentials` as a side effect of persisting
  the *previous* test's result — so the credentials were destroyed one
  click after being confirmed to work, which is exactly what made this
  look like it correlated with unrelated actions (a plugin
  disable/re-enable cycle, in the original report) rather than with the
  Check Connection click itself. Also confirm the *unaffected* paths still
  work: an empty-submit save with the same driver selected still keeps the
  stored secret (3.5.5); switching to a genuinely different driver still
  clears the old driver's now-irrelevant credentials.
- [ ] Pass

### 3.5.6 Rights and CSRF on the endpoint (§8)
- **Steps:** hide check: confirm the **Check Connection** button is absent
  for a user without supplier UPDATE on that supplier. Then hand-craft a POST
  to `/plugins/domainmanager/connectiontest/<id>` without supplier UPDATE
  (403 expected), for a nonexistent supplier id (404 expected), and without
  the `X-Glpi-Csrf-Token` header (rejected by core).
- [ ] Pass

### 3.5.7 Two-file consolidated logging (§3.6, §0.8)
- **Steps:** run one connection test that succeeds and one that fails, then
  open **Setup → Logs**.
- **Expected:** both `domainmanager.log` and `domainmanager-errors.log`
  appear in the list with no extra registration step; `domainmanager.log` has
  exactly one line per capability tested regardless of outcome (supplier id,
  capability, status, http code, duration); `domainmanager-errors.log` has an
  additional line, with technical detail, only for the failing capability —
  the detail contains no raw secret value even if the underlying exception
  message happened to include the word "token"/"secret" (redaction, §3.6).
- [ ] Pass

### 3.5.8 `rawDetail` never reaches the browser (§3.5.2)
- **Steps:** trigger an auth failure (3.5.2) and inspect the network
  response body (`/connectiontest/<id>`) and the rendered HTML/DOM.
- **Expected:** only `status`, `capability`, `http_status_code`,
  `user_message`, `checked_at` are present (`ConnectionTestResult::toArray()`
  shape) — `rawDetail` never appears anywhere client-side.
- [ ] Pass

### 3.5.9 Plugin log files appear even when `use_log_in_files` is unset (§0.9, §3.6)
- **Steps:** on an instance where Setup → General → System does not have
  logging-to-files explicitly enabled (the stock default), run any connection
  test, then open **Setup → Logs**.
- **Expected:** `domainmanager.log` (and `domainmanager-errors.log` on a
  failing test) appear and contain the expected lines — `PluginLogger` forces
  the write (`Toolbox::logInFile(..., true)`) regardless of that core setting.
- [ ] Pass

### 3.5.10 A persistence failure never breaks the response (§3.5.3, `ConnectionTestController`)
- **Steps:** simulate `SupplierConfig::recordConnectionTestResults()` throwing
  (e.g. temporarily rename one of the `*_test_*` columns, or otherwise force a
  DB error) and run a connection test against an already-saved supplier
  config.
- **Expected:** the toast and detail panel still show the real pass/fail
  result (the JSON response still contains an entry per capability,
  unaffected by the persistence failure — only the UI's single combined
  badge changes, §6.1); a line documenting the persistence failure appears in
  `domainmanager-errors.log`;
  the endpoint still returns HTTP 200 with `ok: true`.
- [ ] Pass

### 3.5.11 Failed requests surface HTTP status + detail instead of a bare message (§3.5.6)
- **Steps:** force a few failure modes against `/connectiontest/<id>` — an
  unknown supplier id (404), a user without supplier UPDATE (403), and (if
  reproducible) a 500 from an uncaught exception — each via the Check
  Connection button.
- **Expected:** the error toast reads `"Connection test request failed: HTTP
  <code>: <message>"` (or, for a non-JSON body, `"HTTP <code> - <truncated
  body>"`) instead of a bare, uninformative string; the same detail is visible
  in the browser console (`console.error`).
- [ ] Pass

### 3.5.12 Log files appear immediately after activation, before any sync/test (§3.6.1)
- **Steps:** deactivate then reactivate the plugin (or reinstall it fresh) in
  the test container, without running any sync or connection test, then open
  **Setup → Logs**.
- **Expected:** `domainmanager.log` exists immediately, containing a single
  "Domain Manager activated, logging initialized" line; `domainmanager-errors.log`
  also exists (0 bytes is expected and fine, matching core's own
  `sql-errors.log`/`mail-errors.log` convention) — both visible without
  needing to trigger any feature first. Root cause this regression-tests: the
  files were previously never created at all in this environment because
  nothing had ever called into `PluginLogger` (no connection test had been
  run, and the daily sync cron task had never executed — see 3.5.14 below).
- [ ] Pass

### 3.5.13 Log file content renders correctly in the Setup → Logs viewer (§3.6)
- **Steps:** after 3.5.7 or 3.5.12, open `domainmanager.log` and
  `domainmanager-errors.log` **through the Setup → Logs viewer itself**
  (click into the file, not just confirm it's listed).
- **Expected:** content displays as plain text lines matching what's on disk
  (`podman exec glpi_glpi_1 cat /var/glpi/logs/domainmanager.log`), no
  parsing/formatting errors or truncation from the viewer.
- [ ] Pass

### 3.5.14 Daily sync cron task is actually registered
- **Steps:** after install/reactivation, check **Setup → Automatic actions**
  for a task named `DomainSync` against itemtype
  `GlpiPlugin\Domainmanager\Cron`; alternatively query
  `SELECT * FROM glpi_crontasks WHERE itemtype LIKE '%Domainmanager%'`.
- **Expected:** exactly one row/task listed (state waiting, daily frequency,
  hourmin 23–24, per `Installer::registerCronTasks()`).
- **Resolved 2026-07-20** (was briefly logged here as a suspected bug —
  correction below): a `glpi_crontasks` query taken mid-session showed zero
  `domainmanager` rows, which was misread as `CronTask::register()` never
  succeeding. Root cause was a stale snapshot, not a code defect: two of the
  earliest recorded installs (2026-07-18 06:34/07:08) predate commit
  `ad3a658` (2026-07-18 08:43, Phase 1), which is what introduced
  `registerCronTasks()` in the first place — the code simply didn't exist on
  disk yet at those install times. A later install that day (10:06) did
  postdate the code, but was immediately followed by a manual uninstall
  (10:19, dropping the plugin tables), which calls `CronTask::unregister()`
  and removes the row — the "zero rows" check landed in that
  just-uninstalled window. A clean `glpi:plugin:install domainmanager`
  console run afterward created the row correctly on the first try (id 75,
  correct `frequency`/`hourmin`/`hourmax`/`param`, plus a matching
  `Log::history` entry) — `Installer::registerCronTasks()` /
  `CronTask::register()` work as intended; no fix was needed.
- [ ] Pass

### 3.5.15 Dinahosting Check Connection reflects real auth outcomes (§3.8)
- **Steps:** select driver *Dinahosting*; (a) fill in a deliberately wrong
  username/password, click Check Connection; (b) fill in real, valid
  Dinahosting credentials (if available), click Check Connection.
- **Expected:** (a) the single Connection badge/toast shows red
  "Authentication failed — the username or password was rejected." (status
  `auth_failed`, no HTTP code shown), and `domainmanager-errors.log` still
  gets a line per capability (registrar + dns, both recorded even though only
  one is shown, §6.1) with the real Dinahosting `responseCode`/message
  (verified in this session directly against the live API:
  `responseCode=2200 message="" errors=[code=2200 msg=Authentication
  error.]`) — no credential value in the log line. (b) shows a green
  "Connection successful." toast/badge.
- [ ] Pass

## Phase 3.7 — Audit trail via native History (§3.7)

### 3.7.1 Saving a new driver + credentials logs to the Supplier's Historical tab
- **Steps:** on a supplier with no prior Domain Manager configuration, select
  a driver (e.g. Cloudflare) and fill in its credential field(s), Save, then
  open that Supplier's own **Historical** tab.
- **Expected:** the **"field" column reads "Domain Manager"** (not blank) on
  every new row — verifies the `plugin_domainmanager_getAddSearchOptionsNew()`
  registration (§3.7.1) resolved correctly. One row's change text is `"Change
  to Cloudflare"` (driver set) and another `"Change to API Token set"` (or
  the equivalent per-field lines for a multi-field driver) — never containing
  the actual token/secret value.
- [ ] Pass

### 3.7.2 Updating a single credential field logs only that field
- **Steps:** on an already-configured supplier (same driver), change only one
  credential field (e.g. just the secret, leaving other fields as their
  "saved" placeholder) and Save.
- **Expected:** exactly one new Historical row, field "Domain Manager", change
  `"Change to <Field label> updated"`, for the changed field only — no rows
  for the untouched fields, no driver-change row.
- [ ] Pass

### 3.7.3 Clearing a secret field logs "cleared"
- **Steps:** on an already-configured supplier, submit the form with a
  previously-saved secret field now empty (same driver).
- **Expected:** field "Domain Manager", change `"Change to <Field label>
  cleared"` in the Historical tab; the field is actually removed from the
  stored encrypted payload (not just cosmetically).
- [ ] Pass

### 3.7.4 Switching driver logs the driver change + new fields only
- **Steps:** on a supplier configured with one driver (e.g. Dinahosting),
  switch the driver select to a different one (e.g. Cloudflare), fill its
  field(s), Save.
- **Expected:** field "Domain Manager", change `"Change to API driver changed
  from Dinahosting to Cloudflare"` plus one `"Change to <Field> set"` row per
  newly-populated field of the new driver — no spurious "cleared"/"updated"
  rows referencing the old driver's now-irrelevant fields (user/password).
- [ ] Pass

### 3.7.5 Purging a SupplierConfig row logs removal
- **Steps:** delete/purge a supplier's Domain Manager configuration (via the
  right-holder unlock path or direct DB-admin action), then check the
  Supplier's Historical tab.
- **Expected:** field "Domain Manager", change `"Change to API configuration
  removed (was <driver label>)"`.
- [ ] Pass

### 3.7.6 Registrar supplier assignment (via Infocom) logs to the Domain's Historical tab
- **Steps:** on a Domain's **Infocom** tab, set its **Supplier** field, Save;
  then change it to a different supplier, Save; then clear it, Save (§9
  Phase 5.5 — this is no longer a plugin-owned dropdown, see 4.1). Check
  the Domain's own **Historical** tab after each save.
- **Expected:** three distinct rows, field "Domain Manager" on each —
  `"Change to Registrar supplier set to X"`, `"...changed from X to Y"`,
  `"...cleared"` — logged only on saves where the value actually changed (a
  no-op save produces no new row). Sync milestones (`SyncEngine`'s "Update
  Now"/cron outcomes, §5) also now appear as "Domain Manager" rows on the
  same tab.
- [ ] Pass

### 3.7.7 Search-option ID collision check
- **Steps:** run `php tools/getsearchoptions.php --type=Supplier` and
  `--type=Domain` against the target instance (requires DB access) before
  go-live; separately, check that "Domain Manager" appears exactly once as a
  selectable column in Supplier's and Domain's Search config (Setup → search
  options / the search page's "+" column picker) and that adding it as a
  displayed/sorted column doesn't error (it's bound to the itemtype's own
  `name` column, so it should just behave like a redundant Name column, not
  crash).
- **Expected:** IDs `9401` (Supplier) / `9402` (Domain,
  `PLUGIN_DOMAINMANAGER_SO_SUPPLIER`/`PLUGIN_DOMAINMANAGER_SO_DOMAIN` in
  `setup.php`) are not already used by another installed plugin for that
  itemtype; no SQL error when the column is added to a search/sort. If a
  collision is found, change the constants in `setup.php` to unused values
  and re-test. Once confirmed clean, flip `verified_collision_free` to `true`
  for both entries in `search-options-registry.json` (repo root) — the
  TICGAL-wide ledger of every search-option ID any TICGAL plugin registers.
- [ ] Pass

> Verification status: Phase 1 items were exercised on GLPI 11.0.8 via CLI on
> 2026-07-17/18. Phase 2 items 2.2–2.6 (model level), 2.9, 2.10 and 2.11 were
> exercised by a scripted run on GLPI 11.0.8 on 2026-07-18 (27/27 checks passed);
> browser-level items (2.1, 2.3 UI, 2.7, 2.8) still need a manual pass.
> Phase 3 items 3.1–3.10 were exercised by a scripted run on GLPI 11.0.8 on
> 2026-07-18 (35/35 checks, including live NS detection of a Cloudflare-hosted
> domain); 3.11 needs real credentials and 3.12 a manual profile setup.
> Phase 4: a scripted run on GLPI 11.0.8 (2026-07-18, stopped early) confirmed
> registrar persistence on add, panel rendering (dropdown, button, badges), lock
> JS with/without the right, itemtype filtering, cron filtering of
> inactive/template domains, and endpoint gating over HTTP (403 unauthenticated,
> 404 only for unknown routes). The three failed scripted checks were diagnosed
> against the GLPI `11.0/bugfixes` source on 2026-07-18:
> - **4.1 was a real bug, now fixed:** `CommonDBTM::update()` fires the
>   `item_update` hook only when a `glpi_domains` column actually changed, so a
>   save touching only the (virtual) Registrar dropdown never persisted.
>   Persistence moved to `pre_item_update` (fires unconditionally); re-run 4.1
>   including a dropdown-only save.
> - **4.3 rewritten:** the panel now generates the sync URL from the named route
>   via Twig `path('@domainmanager:domainmanager_sync')` instead of the
>   deprecated `Plugin::getWebDir()` (which logged a deprecation per form render
>   and returns a wrong `/marketplace/…` base on marketplace installs); re-run
>   4.3 in the browser.
> - **4.7: no defect found on review** (`LIMIT`, ordering, filtering and
>   `CronTask::log`/`addVolume` all match core usage); the scripted failure is
>   attributed to invoking `cronDomainSync()` with a hand-built CronTask. Verify
>   manually through `front/cron.php` as the item describes.
> Phase 5: registry matching semantics (5.1, 5.4, plus the amended 2.10/2.11
> expectations) were verified on 2026-07-19 by a standalone PHP simulation of
> `NsProviderRegistry::match()` against the real JSON (21/21 host cases, incl.
> false-positive guards) and `php -l` on the icon-touched classes; no container
> run — 5.2, 5.3 and 5.5 need a manual pass on a live instance.
> 2.1 failed its manual pass on 2026-07-19 (gating used the *config* right, which
> the profile UI does not expose as READ): supplier-tab gating was realigned to
> native supplier rights (READ to see, entity-aware UPDATE to save) — re-run the
> amended 2.1 and 2.8.
> The "Test credentials" button (5.6–5.8) was added 2026-07-19: `php -l` clean,
> route/CSRF/rights pattern mirrors the verified SyncController; needs a manual
> pass on a live instance (5.6 requires a real Cloudflare token).
> Phase 3.5 (connection diagnostics, 3.5.1–3.5.8) replaced the above button
> 2026-07-19 before it ever shipped: `php -l` clean on every new/touched file;
> `front/logs.php`'s generic `*.log` enumeration and the `glpi_toast_*` JS API
> were verified directly against the `11.0/bugfixes` source (§0.8) rather than
> assumed. No container run yet — all 3.5.x items need a manual pass on a live
> instance (3.5.1/3.5.2/3.5.5 require a real Cloudflare token).
> A live manual pass on 2026-07-19 surfaced two real bugs, fixed same day
> (3.5.9, 3.5.10): `Toolbox::logInFile()` silently no-ops unless
> `$CFG_GLPI['use_log_in_files']` is set or `$force=true` is passed (verified
> against `src/Toolbox.php` on `11.0/bugfixes` — not present in this repo's
> default config, so every log call was previously a silent no-op);
> `PluginLogger` now forces both writes. A persistence failure in
> `recordConnectionTestResults()` could also break the whole HTTP response
> with no client-side detail; the call is now try/caught and the client-side
> fetch handling (3.5.11) rewritten to always surface HTTP status + body
> detail instead of a bare generic message. Phase 3.7 (audit trail, 3.7.1–3.7.6)
> is new — `SupplierConfig`/`HookHandler` now call `Log::history()`, verified
> against real `CommonDBTM::post_addItem()`/`post_updateItem()`/
> `post_purgeItem()` and `Dropdown::getDropdownName()` signatures on
> `11.0/bugfixes`. `php -l` clean on all touched files; no container run yet —
> all of 3.5.9–3.5.11 and 3.7.1–3.7.6 need a manual pass on a live instance.
> 2026-07-20: the Historical tab's "field" column was blank on the first live
> pass — traced to `Log::history()`'s generic `id_search_option = 0` never
> matching a real search option (verified against `src/Log.php` on
> `11.0/bugfixes`). Fixed by registering a real, non-functional "Domain
> Manager" search option per itemtype via
> `plugin_domainmanager_getAddSearchOptionsNew()` (`setup.php`,
> `PLUGIN_DOMAINMANAGER_SO_SUPPLIER`/`_SO_DOMAIN`) and passing it as
> `id_search_option` everywhere; all message text dropped its now-redundant
> `"[Domain Manager] "` text prefix. New item 3.7.7 covers the required
> collision check for these IDs, which cannot be verified without a live
> instance. `php -l` clean; no container run yet.

---

## Phase 5.5 — Supplier-scoped "Domains" list + Registrar mirrors Infocom (§9)

### 5.5.1 Domains list shows every domain where this supplier is registrar and/or DNS
- **Steps:** on a Supplier's Domain Manager tab, with at least one Domain
  having this supplier as Infocom's Supplier (Registrar) and/or a different
  Domain having it as the resolved, plugin-managed DNS provider.
- **Expected:** a "Domains" card below the credentials/connection panels
  lists every such Domain, each name a real hyperlink to
  `/front/domain.form.php?id=<id>`. A Domain unrelated to this supplier
  (neither Registrar nor DNS) never appears.
- [ ] Pass

### 5.5.2 Registrar/DNS columns show the right role and provider
- **Steps:** on the same list, check a row where this supplier is the
  Registrar but a *different* supplier is the actively-managed DNS provider.
- **Expected:** the Registrar column links to this supplier itself with a
  status badge from `registrar_status`; the DNS column links to the *other*
  supplier (not this one) with a green "Plugin managed" badge. A row where
  this supplier is only the DNS provider (not the registrar) shows "None" in
  the Registrar column.
- [ ] Pass

### 5.5.3 DNS status three-way classification (§9 Phase 5.5)
- **Steps:** find or create rows in each of these states: (a) a driver
  actively syncing this domain's DNS (`dns_status='ok'`/`'error'`,
  `dns_suppliers_id` set), (b) NS detected a real provider with a driver but
  no Supplier has valid credentials configured (`dns_status='unconfigured'`),
  (c) NS detected a real provider with no driver implemented at all
  (`dns_status='unsupported'`), (d) NS didn't match anything
  (`dns_status='unknown'`).
- **Expected:** (a) green "Plugin managed" (red if `error`); (b) and (c) both
  show amber "Known, unmanaged (yet)" with the real detected provider name —
  confirm via DB that these are genuinely different `dns_status` values even
  though the badge/label is intentionally the same; (d) grey "Unknown".
- [ ] Pass

### 5.5.4 List respects entity visibility and the `domain` READ right
- **Steps:** (a) as a user with supplier READ but domains in an entity this
  user cannot see, open the Domains list. (b) as a user with supplier READ
  but no `domain` READ right at all.
- **Expected:** (a) domains outside the user's visible entities never appear
  in the list (`getEntitiesRestrictCriteria()`). (b) the whole "Domains" card
  either shows no rows or is suppressed — never a DB error.
- [ ] Pass

### 5.5.5 Registrar field on the Domain form is never editable, always matches Infocom (§0.1, 4.1)
- See **4.1** above (moved/rewritten in Phase 4's own section) — repeated
  here as a cross-reference since this is the other half of the same change.
- [ ] Pass

> Implemented and smoke-tested 2026-07-21 on `glpi-claude` (Óscar's request:
> "the list will be easy to build I want it before v1" — pulled forward from
> the original Phase 6 sketch since it needed no schema change and no
> discovery/entity-assignment design work; the Registrar-mirrors-Infocom
> change followed the same session, requested separately once he saw the
> old dropdown's "No registrar supplier with API access configured" message
> next to an already-selected value and asked for it to just directly follow
> Infocom's own Supplier field instead). Verified live: created a real Domain
> (`beiro.net`) with a real Infocom Supplier assignment; confirmed the
> `HookHandler::infocomSaved()` mirror fires correctly both ways (set and
> clear, via a real `front/infocom.form.php` POST) and that both GLPI's own
> native History entry and the plugin's own entry appear together. Caught and
> fixed a real classification bug during this testing: `dns_status =
> 'unconfigured'` (a driver exists for the detected provider but no Supplier
> has valid credentials) was initially falling into the generic "Never
> synced" bucket instead of "known, unmanaged (yet)" — `detected_provider`
> was already populated and a sync had genuinely run, so "never synced" was
> actively misleading; fixed in `SupplierTab::describeDnsProvider()` before
> committing. `php -l` clean on all touched files.

### 5.5.6 Domains list matches the native "Items" tab count (§9 Phase 5.5, fixed 2026-07-21)
- **Steps:** on a Supplier with several domains whose Infocom "Supplier"
  field is this supplier, but where a real sync has only ever run for
  *some* of them (a common existing-instance-install scenario) — compare
  the native "Items" tab's count against the "Domains" panel's row count
  and the "Domain Manager" tab's own badge.
- **Expected:** all three numbers agree, including domains that have never
  been synced at all. Confirmed **not a bug in the earlier
  implementation's intent, but a real query defect**: the original query
  `INNER JOIN`ed the plugin's own state table, so a domain was invisible
  until a sync had already produced a state row for it — even though its
  registrar link (`glpi_infocoms.suppliers_id`) was already real. Fixed
  via a `LEFT JOIN` read live from Infocom, unioned with the DNS-side
  condition.
- [ ] Pass

### 5.5.7 Registrar status only trusted when the state row's mirror agrees with this supplier
- **Steps:** find or create a domain with a real state row (DNS already
  synced) whose `registrar_suppliers_id` does not match the supplier you're
  viewing (e.g. set before the Infocom-mirror hook existed, or via a direct
  DB write bypassing hooks) but whose Infocom "Supplier" field genuinely is
  this supplier.
- **Expected:** the Registrar column still correctly links to this
  supplier (from the live Infocom read), but its status badge shows "Not
  yet checked" — not whatever stale `registrar_status` the state row
  happens to hold, which describes a different (often nonexistent)
  registrar relationship, not this one. After running Update Now or the
  massive action (5.5.9) on that domain, `registrar_suppliers_id` in the
  state row should update to match, and the badge should switch to a real
  status.
- [ ] Pass

### 5.5.8 Recommendation banner appears/disappears correctly, links to a real filtered search
- **Steps:** on a supplier with at least one registrar-linked-but-never-verified
  domain, open the Domains panel; click the banner's "Review and sync"
  link; then sync every flagged domain and reload the panel.
- **Expected:** banner text correctly pluralized (singular/plural via
  `_n()`) and counts only unverified registrar links, not the whole list.
  The link lands on Domain's native search, already filtered by the new
  "Registrar (Financial information)" search option
  (`PLUGIN_DOMAINMANAGER_SO_DOMAIN_REGISTRAR = 9403`) — confirm the value
  dropdown resolves to the Supplier's real name (not "-----" / an Infocom
  record lookup — an earlier, incorrect version of this search option
  broke exactly that way, silently returning zero results too). After
  every flagged domain has been synced once, the banner disappears
  entirely.
- [ ] Pass

### 5.5.9 "Sync now (Domain Manager)" massive action (§9 Phase 5.5)
- **Steps:** from the filtered search above (or any Domain list), select
  several domains, open Actions, choose Sync now (Domain Manager), submit.
- **Expected:** the action appears in GLPI's native massive-action
  dropdown alongside core's own actions (Update, Clone, etc.) — not a
  bespoke UI. Each domain is synced independently; one domain's sync
  reporting a real error (`STATUS_ERROR` on either leg) doesn't stop the
  rest of the batch from processing, and GLPI's native result summary
  reports per-item OK/KO correctly (a domain with only
  `unconfigured`/`unsupported`/`unknown` outcomes counts as OK — those are
  expected states, not failures). Confirm every processed domain's
  `registrar_suppliers_id` mirror is corrected to match live Infocom
  afterward (5.5.7).
- [ ] Pass

> Undercount bug + SyncEngine live-Infocom fix + banner + massive action +
> tab badge implemented and verified live on `glpi-claude` 2026-07-21, same
> session as 5.5.1-5.5.5 above (Óscar's report: "Supplier ID 5 (IONOS)'s
> native Items tab correctly shows 3 linked domains... The plugin's own
> Domains panel... shows only 1"). Root cause confirmed against the actual
> query before fixing, per his explicit request, rather than assumed —
> `glpi_domains` genuinely has no `suppliers_id` column at all (verified
> earlier this session too), the real native link is `glpi_infocoms`.
> Reproduced live: 3 domains with real `Infocom.suppliers_id=5`, only 1
> with a matching `states.registrar_suppliers_id` mirror.
>
> The massive action hit a real, fatal bug during verification, not a
> cosmetic one: registering only `processMassiveActionsForOneItemtype()`
> produced an `UndefinedMethodError` (`showMassiveActionsSubForm()` also
> required, confirmed via `php-errors.log`) the moment a real browser
> selected the action — caught via a full Playwright-driven browser test
> (select-all, open Actions modal, choose the action, submit), not just
> markup inspection, since GLPI's massive-action confirm/submit UI is
> loaded via a live AJAX call.
>
> The new search option went through two wrong shapes before the working
> one: first `'table' => 'glpi_infocoms', 'field' => 'suppliers_id',
> 'datatype' => 'dropdown'` (GLPI resolved the dropdown's itemtype from
> `table`, so it tried to look up `Infocom` records instead of `Supplier`s
> — silently wrong display and zero query results, verified live via the
> rendered `<select>`'s ajax config showing `"itemtype":"Infocom"`); then
> adding `'searchtype' => ['equals', 'notequals']` alone (fixed the
> searchtype list but not the underlying resolution). The working shape
> combines `linkfield` (Computer's `users_id_tech` pattern) with
> `joinparams.beforejoin` (Infocom's own `CartridgeItem`/`ConsumableItem`
> pattern) for a genuine two-hop dropdown — verified by checking the
> rendered value dropdown resolved to "IONOS" and the ajax config showed
> `"itemtype":"Supplier"`, then confirming the actual filtered search
> returned the right 3 domains, not just that no fatal error occurred.
>
> Separately during this session: a "credentials disappeared" report
> turned out to be the concurrent-testing collision described earlier in
> this file's connection-diagnostics sections — Claude was actively
> toggling the same supplier's driver/credentials for its own test cleanup
> while Óscar was independently testing the same shared `glpi-claude`
> instance. Confirmed by asking which instance and getting agreement to
> pause concurrent testing; not a regression in this session's actual code
> changes (verified separately with a clean, uncontaminated save
> round-trip). See the `glpi-dev-environment` memory for the standing
> caveat this added.

## Phase 5.6 — API driver exclusivity + alphabetical dropdown (addendum)

### 5.6.1 A driver already assigned to Supplier A is hidden from Supplier B's dropdown
- **Steps:** save IONOS as Supplier A's API driver. Open Supplier B's
  Domain Manager tab.
- **Expected:** Supplier B's API driver dropdown does not offer "IONOS".
  "None" and every other unclaimed driver are still offered.
- [ ] Pass

### 5.6.2 Supplier A's own dropdown still shows/selects its claimed driver
- **Steps:** with IONOS saved on Supplier A (5.6.1), reopen Supplier A's
  Domain Manager tab.
- **Expected:** "IONOS" is present in the dropdown and pre-selected;
  credentials fields still show their saved state. Excluding
  drivers-claimed-elsewhere never hides a supplier's own current driver.
- [ ] Pass

### 5.6.3 Direct POST cannot assign an already-claimed driver (server-side guard)
- **Steps:** with IONOS saved on Supplier A, submit a direct POST to
  `SupplierConfig::getFormURL()` for Supplier B with `api_driver=ionos`
  (e.g. via curl/browser devtools), bypassing the dropdown entirely.
- **Expected:** the save is rejected with a clear error message ("IONOS is
  already assigned to another supplier") via
  `Session::addMessageAfterRedirect`, and Supplier B's stored
  `api_driver`/credentials are unchanged. This must hold even though the
  client-side dropdown would never have offered "IONOS" for Supplier B —
  the check in `SupplierConfig::prepareDriverAndCredentials()` is
  independent of the dropdown's filtering.
- [ ] Pass

### 5.6.4 Dropdown order is alphabetical by label, "None" always first
- **Steps:** open the Domain Manager tab for a supplier with no driver set,
  then for one with an existing driver selected.
- **Expected:** options render as None, Cloudflare, Dinahosting, IONOS (or
  whatever subset remains after 5.6.1's exclusion) — sorted alphabetically
  by display label with "None" always pinned first, in both cases.
- [ ] Pass

> No massive-action / bulk-edit path exists for `SupplierConfig.api_driver`
> — `MassiveActionHandler` only implements the Domain "Sync now" action
> (5.5.9), and `SupplierConfig` has no search/list page of its own, so
> there is no bulk path that could assign the same driver to multiple
> suppliers today. The addendum's massive-action requirement is therefore
> not applicable; if a bulk-edit path for the driver is added later, it
> must reuse `SupplierConfig::isDriverClaimedByOtherSupplier()` /
> `getDriversClaimedByOtherSuppliers()` and skip colliding items with a
> per-item reason rather than failing the whole batch.

## Phase 5.7 — Skip inactive suppliers, surface it clearly (addendum)

### 5.7.1 Check Connection is disabled with a notice on an inactive supplier
- **Steps:** on a supplier with a saved driver + credentials, set
  Active = No on the supplier's own native form. Reopen its Domain Manager
  tab.
- **Expected:** the Check Connection button is disabled (tooltip explains
  why), and the connection diagnostics panel shows only the inline notice
  "This supplier is inactive. Activate it to test or use its Domain
  Manager credentials." — not the last stored badge/message/timestamp.
  The credentials form itself (driver select + fields) remains editable.
- [ ] Pass

### 5.7.2 Server-side guard rejects a direct connection-test POST too
- **Steps:** with the same inactive supplier, POST directly to
  `/plugins/domainmanager/connectiontest/{suppliers_id}` (curl/devtools),
  bypassing the disabled button.
- **Expected:** rejected (409) with a clear "this supplier is inactive"
  message; no credentials are decrypted and no request reaches the
  driver's API (verify nothing new appears in `domainmanager.log` /
  `domainmanager-errors.log` for this call other than the rejection, if
  logged at all).
- [ ] Pass

### 5.7.3 Domain form shows "Supplier inactive," not "Not configured" or a red error
- **Steps:** with a Domain whose Infocom registrar (or detected DNS
  provider) resolves to the now-inactive supplier, open the Domain form
  or run Update Now / cron / the "Sync now" massive action.
- **Expected:** the Registrar sync and/or DNS sync badge reads "Supplier
  inactive" (a calm/secondary badge, not red `text-bg-danger`) — distinct
  from "Not configured" (which means no supplier is resolved at all).
  Registrar/DNS Provider columns still link to the resolved supplier (it's
  known, just inactive).
- [ ] Pass

### 5.7.4 Cron and the mass-sync massive action skip cleanly, no error
- **Steps:** trigger the `DomainSync` cron task (or wait for its window)
  and separately run the "Sync now (Domain Manager)" massive action over
  a domain resolving to the inactive supplier.
- **Expected:** cron's own error counter does NOT increment for this
  domain (only `domainmanager.log` gets a normal "... is inactive"
  activity line, never `domainmanager-errors.log`). The massive action's
  result summary reports it as "Skipped <domain> — resolved supplier is
  inactive", distinguishable from the generic "Sync reported an error"
  message used for real failures.
- [ ] Pass

### 5.7.5 Reactivating the supplier resumes normal behavior with no other change
- **Steps:** set the supplier back to Active = Yes. Reopen its Domain
  Manager tab; re-run Check Connection; re-sync an affected domain.
- **Expected:** Check Connection is enabled again and works normally; the
  domain's badges return to their real ok/error/unconfigured outcome from
  an actual API call — no leftover "inactive"/disabled state anywhere.
- [ ] Pass
