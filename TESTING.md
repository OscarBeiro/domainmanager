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

### 3.9 Stub drivers surface as pipeline errors (§3)
- **Steps:** configure a supplier with the IONOS or Dinahosting driver and run a
  sync using it.
- **Expected:** the affected pipeline ends with status `error` and the message
  "The IONOS/Dinahosting driver is not implemented yet"; the other leg is not
  aborted.
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

---

## Phase 4 — Domain panel, Update Now, cron batching

### 4.1 Registrar field on the domain form (§0.1, §6.2)
- **Steps:** open a domain with *domain* UPDATE; the Domain Manager panel shows a
  **Registrar** supplier dropdown. Select a supplier, save; reopen. Then change it
  to another supplier (or empty) and save again. Also save the form without
  touching the dropdown.
- **Expected:** the selection persists across saves (stored in
  `glpi_plugin_domainmanager_states.registrar_suppliers_id`, never in
  `glpi_domains`); changing it updates the state row; saving other fields leaves
  it untouched. Users with only *domain* READ see the value read-only.
- [ ] Pass

### 4.2 Status card and DNS provider display (§6.2)
- **Steps:** open a synced domain with *domain* READ.
- **Expected:** panel shows the detected DNS provider (linked to the supplier when
  resolved), Registrar/DNS badges colored by status (green ok, red error, grey
  never/unconfigured, yellow unsupported/unknown), last sync date, and the
  per-pipeline messages. A domain never synced shows "Never synchronized".
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
