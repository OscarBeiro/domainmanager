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
- **Steps:** open any supplier (*Management → Suppliers*) as a user **with** *config*
  READ; then as a user **without** it.
- **Expected:** the **Domain Manager** tab appears only for the user with *config*
  READ; the form (driver select + save) is editable only with *config* UPDATE —
  otherwise the tab is a read-only driver display.
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
  as a user without *config* UPDATE (edit the form's HTML to re-enable it if needed).
- **Expected:** missing/invalid CSRF token is rejected by core; the save without
  *config* UPDATE is refused (access denied).
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
- **Expected:** valid JSON; exactly 3 providers (Cloudflare, IONOS, Dinahosting),
  each with a `driver` matching a hardcoded driver key and a `source` URL
  documenting the pattern.
- [ ] Pass

### 2.11 NS matching semantics (§4)
- **Steps:** exercise `NsProviderRegistry::match()` (e.g. via a scratch script in
  the container) with: `ADA.NS.CLOUDFLARE.COM.` (uppercase + trailing dot),
  `ns1042.ui-dns.biz`, `ns.dinahosting.com`, `blue.foundationdns.com`,
  `ns-123.awsdns-45.net`, and an empty list.
- **Expected:** Cloudflare, IONOS, Dinahosting, Cloudflare, `null` (unknown),
  `null` respectively — case-insensitive, trailing dot stripped, wildcards per
  fnmatch, first matching entry in file order wins.
- [ ] Pass

### 2.12 Malformed registry entries are skipped, not fatal (§4)
- **Steps:** temporarily add an entry without `patterns` (or with an unknown
  `driver`) to a **copy** of the registry on a writable instance, reload.
- **Expected:** the bad entry is ignored, remaining providers still match, and a
  line is appended to `files/_log/plugin_domainmanager.log`.
- [ ] Pass

> Verification status: Phase 1 items were exercised on GLPI 11.0.8 via CLI on
> 2026-07-17/18. Phase 2 items 2.2–2.6 (model level), 2.9, 2.10 and 2.11 were
> exercised by a scripted run on GLPI 11.0.8 on 2026-07-18 (27/27 checks passed);
> browser-level items (2.1, 2.3 UI, 2.7, 2.8) still need a manual pass.
