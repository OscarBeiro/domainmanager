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
  `SELECT name FROM glpi_domainrecordtypes WHERE name IN ('A','AAAA','ALIAS','CNAME','MX','NS','PTR','SOA','SRV','TXT','CAA');`
- **Expected:** exactly 1 "Internet Domain" row; all eleven record types exist; no duplicates.
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
- **Steps:** on a supplier's Domain Manager tab select driver *Cloudflare*, enter
  Account ID `abc123accountid` and API Token `secret-token-123`, save. Then:
  `SELECT api_driver, api_credentials FROM glpi_plugin_domainmanager_supplierconfigs;`
- **Expected:** save redirects back to the supplier tab; `api_driver='cloudflare'`;
  `api_credentials` is a non-empty encrypted blob that does **not** contain
  `secret-token-123` or `abc123accountid` in plaintext (both live inside the same
  encrypted JSON, §3.10 — no separate plaintext column exists for the Account ID).
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
  placeholder; the previously stored Cloudflare Account ID and token are both gone
  from the stored JSON (only `user`/`password` keys remain).
- [ ] Pass

### 2.7 Per-driver fields toggle without reload (§6.1)
- **Steps:** on the tab, cycle the driver select through all four options.
- **Expected:** only the selected driver's fields are visible/enabled — Cloudflare:
  Account ID + API Token; IONOS: API Key + API Secret; Dinahosting: Username +
  Password; None: no fields.
- [ ] Pass

### 2.7.1 Cloudflare Account ID is a required field (§3.10, addendum "Switch Cloudflare Driver to Account-Scoped API Tokens")
- **Steps:** select driver *Cloudflare* on a supplier with no prior config, fill in
  only the API Token, leave Account ID empty, attempt to Save.
- **Expected:** the browser's native `required`-field validation blocks the submit
  (client-side UX only — see 2.7.2 for the server-side path, since no server-side
  save-time rejection exists for this or any other credential field today; a
  missing field is only surfaced when actually *used*, not enforced at save).
- [ ] Pass

### 2.7.2 Direct POST with a blank Cloudflare Account ID still saves (documents current behavior, §3.10)
- **Steps:** bypass the form (curl/devtools) and POST to `SupplierConfig::getFormURL()`
  with `api_driver=cloudflare`, a valid `_credentials[token]`, and no
  `_credentials[account_id]` (or blank).
- **Expected:** the save succeeds (no server-side required-field rejection exists
  for any driver's credential fields, by design — see §6.1). Reopening the tab
  should then show the 2.7.3 "missing Account ID" notice.
- [ ] Pass

### 2.7.3 Existing Cloudflare config missing Account ID shows a clear notice (§3.10)
- **Steps:** using a Cloudflare config saved before this change (or via 2.7.2), open
  the Supplier's Domain Manager tab.
- **Expected:** a warning alert appears in the credentials card ("This Cloudflare
  configuration is missing an Account ID. Add it below and reissue this token as
  an Account API Token to continue using Cloudflare sync.") — not a silent break,
  not silent continuation with the old unscoped behavior. Saving a valid Account
  ID makes the notice disappear on the next reload.
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
- **Steps:** construct `Dto\ZoneRecord` with an unsupported type (`DS`), an
  oversized name (>255 chars), and a valid lowercase type (`a`).
- **Expected:** the first two throw `InvalidArgumentException` (drivers skip such
  records); the third normalizes to `A`; `getHash()` is identical for equal
  type/name/data/ttl regardless of remote id.
- [ ] Pass

### 3.2 Idempotent record import (§5.4, §5.7)
- **Steps:** call `RecordReconciler::reconcile()` on a domain with a fixed
  upstream snapshot (A + MX + TXT); run it twice.
- **Expected:** first run adds 3 native records with ownership rows
  (`glpi_plugin_domainmanager_records`, each `is_managed = 1`); second run
  reports 3 unchanged, 0 added — never duplicates.
- [x] Pass — verified live 2026-07-21 against a real GLPI 11.0.8 instance
  via a throwaway console-command harness (see §5.4/§5.7 in
  ARCHITECTURE.md): created a domain, ran `reconcile()` with one A record
  twice; first run `{"added":1,...}`, `is_managed=1` on the ownership row.

### 3.3 Upstream change updates in place (§5.4)
- **Steps:** change the content of a record that has a `remote_id` and re-reconcile.
- **Expected:** the same native record row is updated (no new row); the ownership
  hash is refreshed.
- [ ] Pass

### 3.4 Upstream removal moves the record to the native trash bin, never deletes (§5.4, revised — supersedes the old comment-marker design)
- **Steps:** drop one record from the upstream snapshot and re-reconcile.
- **Expected:** native `DomainRecord` still exists (fetchable via
  `getFromDB()`) but is soft-deleted (`is_deleted = 1`) — visible in GLPI's
  native "deleted items" trash view, **not** annotated with a comment
  marker (that convention is gone). Its ownership row is untouched:
  `is_managed` stays `1`.
- [x] Pass — verified live 2026-07-21: ran `reconcile()` with an empty
  upstream snapshot for a previously-synced record; result
  `{"trashed":1,...}`; re-fetched the native record and confirmed
  `is_deleted=1`; confirmed the ownership row still existed with
  `is_managed=1` unchanged.

### 3.5 Reappearance restores from the trash bin (§5.4, revised)
- **Steps:** re-add the dropped record and re-reconcile.
- **Expected:** counted as `restored`; native `DomainRecord::restore()`
  called (`is_deleted` back to `0`) — same native record id, no duplicate
  created. `is_managed` still `1` throughout.
- [x] Pass — verified live 2026-07-21, same harness as 3.4: re-ran
  `reconcile()` with the record present again; result
  `{"restored":1,...}`; re-fetched the native record and confirmed
  `is_deleted=0`, same id as before trashing.

### 3.23 Trashing/restoring is not blocked by the plugin's own field locks (§0.3, §5.4)
- **Steps:** confirm (by code review or a live run with a real lock in
  place) that `RecordReconciler::reconcile()`'s `delete()`/`restore()`
  calls succeed even though the record is plugin-locked.
- **Expected:** `LockEnforcer::domainRecordPreDelete()`'s existing
  `$sync_in_progress` bypass (already set around the whole
  `reconcile()` call) covers this for free — no new lock-bypass code was
  needed or added. `restore()` was never blocked in the first place (no
  `PRE_ITEM_RESTORE` hook is registered by this plugin).
- [x] Pass — confirmed by direct source review of `LockEnforcer.php`
  (`canBypass()` checks `self::$sync_in_progress` first) and by the fact
  that the 3.4/3.5 live test above succeeded at all — a genuine block
  would have left `is_deleted`/`is_deleted` unchanged.

### 3.24 Changed-and-previously-trashed record is both restored and updated (§5.4)
- **Steps:** trash a record (3.4), then have it reappear upstream with
  *different* content (not just present again).
- **Expected:** restored (`is_deleted` back to `0`) **and** updated with
  the new content in the same reconciliation pass; bucketed as `updated`
  in the stats (matching this branch's existing priority over
  `restored` — content-changed always wins the bucket assignment).
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

### 3.11 Real Cloudflare sync (manual, needs a real **account-scoped** API token, updated §3.10)
- **Steps:** save a real Cloudflare **Account ID** plus an **Account API Token**
  (Manage Account → API Tokens — not a personal/My Profile token; Zone:Zone:Read +
  Zone:DNS:Read are both required for import, add Zone:DNS:Edit if write-back
  should be testable too; optionally Registrar read) on a supplier; run
  `SyncEngine::sync()` (or, from Phase 4, "Update Now") against a domain whose
  zone this account can read.
- **Expected:** DNS leg imports only A/AAAA/CNAME/MX/NS/TXT records with correct
  data (MX prefixed with priority) and `dns_status = ok` — confirm the zone
  lookup is actually scoped to the configured account (e.g. temporarily point
  Account ID at a *different* real account you also have zones under, confirm
  the domain is no longer found rather than silently resolving a same-named zone
  under the wrong account); registrar leg fills
  `date_domaincreation`/`date_expiration`/`is_active` and locks them when the
  domain is on Cloudflare Registrar (now looked up directly via the configured
  Account ID, no DNS zone lookup involved), or reports a clear per-leg error
  otherwise; neither the token nor the Account ID appear in messages, history or
  logs.
- [ ] Pass

### 3.11.1 Cloudflare Check Connection: missing Account ID vs. invalid token are distinct (§3.10, addendum)
- **Steps:** on a Cloudflare supplier, (a) fill in a valid token but leave Account
  ID empty and click Check Connection; (b) separately, fill in a valid Account ID
  but a deliberately wrong/revoked token and click Check Connection.
- **Expected:** (a) reports a distinct "Not configured" amber badge/toast with
  message "Cloudflare Account ID is not configured" — no network call attempted.
  (b) reports the existing red "Authentication failed" outcome from
  `accounts/{account_id}/tokens/verify`. The two must never look the same.
- [ ] Pass

### 3.11.2 A real, valid Account API Token passes Check Connection (regression test for a real live bug, §3.10)
- **Steps:** save a genuinely valid, freshly created **Account API Token**
  (Manage Account → API Tokens, `cfat_...`) plus its correct Account ID on a
  supplier, click Check Connection.
- **Expected:** green "Success" — HTTP 200 from
  `accounts/{account_id}/tokens/verify`. **This exact scenario previously
  failed** with a red "Authentication failed"/HTTP 401, even with fully valid
  credentials — root cause was `probeTokenVerify()` still calling
  `user/tokens/verify`, an endpoint that structurally rejects account-owned
  tokens regardless of validity (a confirmed, documented Cloudflare API
  quirk, not a credentials mistake). Fixed by switching to the
  account-scoped verify endpoint. If this regresses, check that endpoint
  first before suspecting the token/account ID are wrong.
- [ ] Pass
- **Cross-check (optional):** the same token against `user/tokens/verify`
  directly (curl) should itself return a 401/`code 1000` "Invalid API
  Token" — confirming the failure is endpoint-specific to token type, not a
  problem with the token.

### 3.11.3 A personal/user token is correctly rejected, not silently accepted (§3.10)
- **Steps:** save a personal/user API Token (My Profile → API Tokens,
  starts with a different prefix than `cfat_`) plus any Account ID value,
  click Check Connection.
- **Expected:** "Authentication failed"/HTTP 401 — personal tokens are not
  supported by this driver (documented, not a bug); the help text and class
  docblock both say so explicitly. Confirms the driver doesn't accidentally
  half-work with the wrong token type in a way that could mask real issues
  later (e.g. DNS zone reads succeeding via a personal token's own zone
  access while the registrar/verify calls silently fail).
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

### 3.20 Dinahosting per-domain authorization vs. account-level auth failure (§3.8, addendum "Debug: Dinahosting Registrar Auth Failure")
- **Steps:** with a Dinahosting supplier whose credentials are confirmed
  valid (Check Connection succeeds, and at least one other domain under
  the same supplier syncs `ok`), run a sync/fetch against a domain that
  is *not* authorized under that Dinahosting account (e.g.
  `pontecm.com` from the original report, or any domain registered under
  a different account/registrar).
- **Expected:** the registrar leg reports a clear, distinct message —
  "Dinahosting authentication succeeded, but this account is not
  authorized to manage this domain" — not "authentication failed, check
  the username/password" (which would wrongly imply the credentials
  themselves are the problem). The other, correctly-authorized domain
  under the same supplier continues to report `ok`, unaffected.
- [ ] Pass

### 3.21 IONOS DNS: a genuine fetch failure reports as an error, never a false "unchanged" (§5.6, addendum)
- **Steps:** with a supplier configured with a real IONOS driver and a
  domain that previously synced successfully, temporarily break the
  credentials (e.g. change the saved API secret to an invalid value) and
  run a sync against that domain. Then restore the correct credentials
  and re-run the sync.
- **Expected:** the broken-credentials run reports `dns_status = error`
  with a clear auth-failure message — never "ok"/"0 added, 0 updated,
  N unchanged". The restored-credentials re-run reports `ok` with the
  correct "unchanged" count again (confirming the fix doesn't turn a
  real no-change sync into a false error either way).
- [ ] Pass

### 3.22 Fetch-succeeded audit line appears in domainmanager.log (§5.6, addendum)
- **Steps:** run a normal successful sync (registrar and/or DNS leg) for
  any supplier/domain, then check `domainmanager.log`.
- **Expected:** a line reading "Domain #<id>: Registrar fetch succeeded,
  lifecycle status: <status>" and/or "Domain #<id>: DNS fetch succeeded,
  returned <N> record(s) from the provider" appears for that sync, logged
  *before* the final "Registrar sync OK"/"DNS sync OK: ..." milestone
  line for the same run — giving independent, log-only proof that a real
  API call happened and returned real data, separate from the
  reconciler's own diff-stats message. Confirm it never appears in
  `domainmanager-errors.log` and never as a `Log::history()` entry on the
  domain's Historical tab (informational only, not a milestone).
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
  pointing at official vendor documentation, and **no** `driver` key.
- [ ] Pass

### 5.6a Strato and Arsys entries, sourced from live DNS rather than official docs
- **Steps:** review the Strato/Arsys entries appended after bunny.net; re-run
  `dig NS strato.de`, `dig NS strato.com`, `dig NS arsys.es`, `dig NS arsys.net`
  and compare against the registry's `patterns`.
- **Expected:** Strato = `ns-strato.ui-dns.{de,com,org,biz}`; Arsys =
  `ns-arsys.ui-dns.{es,com,org,biz}`. Neither has an official vendor page
  stating these hostnames — no authoritative source could be found for either
  brand, same conclusion as the original Phase 5 batch 2 investigation — so
  this is a deliberate exception to the registry's usual official-docs-only
  rule, made at Óscar's explicit request, sourced instead from live
  authoritative `dig` output against each brand's own domains. Both brands
  share the same `ui-dns.*` United Internet DNS platform, distinguished only
  by hostname label; confirm the label doesn't collide with sibling brands on
  the same platform (`dig NS 1and1.com` → `ns-1and1.ui-dns.*`; `dig NS
  fasthosts.co.uk` → `ns-fh.ui-dns.*`). **Caveat:** unlike every other entry
  in this registry, this one can silently go stale if either brand migrates
  off `ui-dns.*` later, since there's no vendor doc to notice the change —
  re-verify with `dig` periodically rather than assuming it's permanent.
- [ ] Pass

### 5.6b IONOS's ui-dns.* patterns no longer false-positive-match sibling brands
- **Steps:** exercise `NsProviderRegistry::match()` directly with
  `ns-strato.ui-dns.de`, `ns-arsys.ui-dns.es`, `ns-1and1.ui-dns.com`,
  `ns-fh.ui-dns.org`, and a real IONOS-format host like `ns1035.ui-dns.de`.
- **Expected:** the first four resolve to Strato / Arsys / no match / no match
  respectively — **never** IONOS. `ns1035.ui-dns.de` still resolves to IONOS
  (`driver: ionos`), confirming the narrowed `ns[0-9]*.ui-dns.*` pattern didn't
  lose real IONOS coverage. Before this fix, all five (including the real
  IONOS host) matched IONOS via the old bare `*.ui-dns.{tld}` wildcard — a
  real false-positive-detection bug for any non-IONOS United Internet-brand
  domain, found while adding the Strato/Arsys entries above, not previously
  covered by any existing test.
- [ ] Pass

### 5.6c RaiolaNetworks and LucusHost entries (2026-07-27)
- **Steps:** `php -r 'json_decode(file_get_contents("resources/ns-providers.json"), true, 512, JSON_THROW_ON_ERROR); echo "ok\n";'`
  and review the entries appended after Arsys; re-run `dig NS lucushost.com`
  and compare against `who.is`/`myip.ms` third-party listings for
  `dns1.raiolanetworks.es`/`dns3.raiolanetworks.es`.
- **Expected:** RaiolaNetworks = `dns1-dns3.raiolanetworks.es`, sourced from
  RaiolaNetworks's own official help article (unlike Strato/Arsys/LucusHost,
  this one has a real vendor doc). LucusHost = `ns1-ns3.lucushost.com`, no
  official vendor page states this — same live-DNS exception as Strato/Arsys —
  confirmed via `dig NS lucushost.com` resolving to exactly this 3-host set
  (LucusHost's own corporate domain is self-hosted on its own nameservers).
  Neither entry has a `driver` key — no registrar/DNS API is documented for
  either provider.
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
- **Steps:** on a supplier with driver *Cloudflare* and a **valid** Account ID +
  API token saved (§3.10 — both now required), click **Check Connection** on the
  Domain Manager tab.
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

### 3.7.7 (superseded 2026-07-27, §9 Phase 14 addendum "Search UI cleanup" — see TESTING.md Phase 14 for the replacement)
The two dummy "Domain Manager" search options this item covered
(`PLUGIN_DOMAINMANAGER_SO_SUPPLIER`/`_SO_DOMAIN`, ids `9401`/`9402`) were
dropped entirely — there is nothing left to collision-check for them.
`Log::history()` now passes `id_search_option = 0` and prefixes messages
with `"[Domain Manager] "` instead (§3.7.1). See Phase 14's own items for
the corrected Search UI behavior and its remaining collision-check
requirement (only `9404`/`9405`/`9406` still need it).
- [ ] N/A — superseded

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
  The link lands on Domain's native search, already filtered by **native
  search option id `53`** (`glpi_suppliers.name` under "Financial and
  administrative information") — updated 2026-07-27 (§9 Phase 14 addendum
  "Search UI cleanup") from the plugin's own now-dropped duplicate option
  (`PLUGIN_DOMAINMANAGER_SO_DOMAIN_REGISTRAR = 9403`); confirm the value
  dropdown resolves to the Supplier's real name (not "-----" / an Infocom
  record lookup — an earlier, incorrect version of the plugin's own option
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

## Phase 5.8 — "Managed" search option + native trash bin (addenda "Searchable 'Managed' Field on Domain Records" + "Vanished Records Go to the Native Trash Bin")

Functional reconciler behavior (trash/restore/`is_managed` persistence) is
covered in Phase 3 (3.2, 3.4, 3.5, 3.23, 3.24) and was verified live
2026-07-21 against a real GLPI 11.0.8 instance. This section covers the
search-option registration itself and the migration.

### 5.8.1 Migration replaces `is_stale` with `is_managed` cleanly (§5.7)
- **Steps:** on an instance already running the plugin (pre-existing
  `glpi_plugin_domainmanager_records` rows with `is_stale`), upgrade/run
  `php bin/console glpi:plugin:install -f domainmanager`.
- **Expected:** `is_managed` column present (tinyint, default `1`,
  indexed), `is_stale` column gone; every pre-existing row backfilled to
  `is_managed = 1` (every row in this table already represented a
  plugin-tracked record before this change). Re-running install again is
  a clean no-op (idempotent).
- [x] Pass — verified live 2026-07-21 against the real `glpi-claude`
  instance: `DESCRIBE glpi_plugin_domainmanager_records` confirmed
  `is_managed` present (`tinyint(4) NOT NULL MUL DEFAULT 1`) and
  `is_stale` absent after running the install/upgrade command.

### 5.8.2 "Managed" search option registers correctly (§5.7)
- **Steps:** call `plugin_domainmanager_getAddSearchOptionsNew('DomainRecord')`
  (directly, or via `Search::getOptions('DomainRecord')` from a real page
  load / full plugin boot — not a bare console script, see the caveat
  below).
- **Expected:** option id `9404` present: `table =
  glpi_plugin_domainmanager_records`, `field = is_managed`, `linkfield =
  domainrecords_id`, `datatype = bool`, `massiveaction = false`,
  `joinparams.jointype = child`, `name = "Managed"`.
- [x] Pass — verified live 2026-07-21 by directly invoking the registered
  function inside the real container; returned exactly the expected
  array. Could not get `Search::getCleanedOptions()`/`Search::getDatas()`
  to pick it up inside a bare throwaway `bin/console` test harness —
  traced to `Plugin::isPluginActive()`/`getPlugins()` returning empty in
  that specific synthetic bootstrap (confirmed even
  `(new Plugin())->bootPlugins()` didn't reproduce a real request's
  plugin-activation state) — a limitation of that harness, not evidence
  against the option itself. **Still needs a real end-to-end pass**: from
  an actual logged-in browser session, confirm a `Search::getOptions()`
  consumer (e.g. Reports, or a future dedicated list page — see 5.8.3)
  can filter by it.
- [ ] Pass (real browser/full-request confirmation)

### 5.8.3 No native list/search page currently exists for DomainRecord — known, documented gap (§5.7)
- **Steps:** look for a native GLPI page to browse/filter Domain Records
  directly (not via a specific Domain's own "Records" tab).
- **Expected finding, not a bug to fix here:** none exists.
  `front/domainrecord.form.php` (single-item form) exists;
  `front/domainrecord.php` (list/search page) does not, and
  `DomainRecord::showForDomain()` (the only existing multi-record view,
  embedded in each Domain's "Records" tab) builds its own raw query and
  renders via `components/datatable.html.twig` — it does not consult
  `Search::getOptions()` at all. The "Managed" option is correctly
  registered per GLPI's supported mechanism regardless (available to any
  consumer that does call the generic search engine for this itemtype),
  but there is no dedicated top-level place in the native UI to use it
  from today. Building one is out of scope for this addendum — flagged in
  ARCHITECTURE.md §5.7 as a real, known gap rather than glossed over.
- [x] Pass (confirmed as a documented, known limitation — not something
  to mark failing, since building a new list page was never requested)

## Phase 8 — Bulk-import undiscovered registrar domains (IONOS + Dinahosting, §9)

### 8.1 "Import Domains" button visibility is gated correctly
- **Steps:** compare the Supplier tab across four suppliers: one
  configured with IONOS, one with Cloudflare, one with Dinahosting, and
  one with no driver ("None").
- **Expected:** the "Import Domains" button appears for the IONOS and
  Dinahosting suppliers — Cloudflare/None show no button at all (no
  `DomainDiscoveryInterface` implementation exists for Cloudflare yet).
  Confirm this is a real `instanceof` check
  (`DriverFactory::forDiscovery()`), not a hardcoded driver-name
  allowlist, by reading `SupplierTab.php`.
- [ ] Pass

### 8.2 Button disabled on an inactive IONOS supplier
- **Steps:** set the IONOS test supplier's native Active field to No;
  reopen its Domain Manager tab.
- **Expected:** the Import Domains button is present but disabled, with a
  tooltip explaining the supplier must be active first — same convention
  as Check Connection. Reactivate afterward before continuing.
- [ ] Pass

### 8.3 Modal lists real domains from the IONOS account
- **Steps:** on the active, configured IONOS test supplier, click
  "Import Domains".
- **Expected:** a modal opens showing a brief loading spinner, then a
  table of every domain actually present in that IONOS account (paginated
  internally via `GET /v1/domainitems`, transparent to the user). An
  entity dropdown is shown above the table, defaulted to the current
  active entity.
- [ ] Pass

### 8.4 Existence/mismatch classification against real GLPI data
- **Steps:** ensure at least one `Domain` item already exists in GLPI
  that also exists in the IONOS test account — one with its Infocom
  Supplier already correctly set to this same supplier, and (if
  possible) one with it set to a *different* supplier or unset.
- **Expected:** the domain matching this supplier already: checkbox
  disabled/grayed, "Already exists" with a working link, no mismatch
  badge. The domain with a different/no supplier assigned: checkbox
  disabled/grayed, "Registrar mismatch" badge (or "Set registrar to X"
  wording when previously unset) plus a visible reassign button. Every
  domain not yet in GLPI at all: checkbox checked by default and enabled.
- [ ] Pass

### 8.5 Import creates Domain + Infocom and triggers an initial sync
- **Steps:** uncheck everything except one genuinely-new-to-GLPI domain,
  pick an entity, submit.
- **Expected:** redirected back to the supplier's Domain Manager tab with
  a summary message ("1 domain imported..."). The new `Domain` item
  exists in the chosen entity; its Infocom "Supplier" field is already
  set to this supplier (no manual step); its Domain Manager panel shows a
  real post-sync registrar/DNS result, not "Not yet checked" — confirming
  `SyncEngine::sync()` actually ran. Its Historical tab shows the
  registrar-assignment entry from `HookHandler::infocomSaved()`.
- [ ] Pass

### 8.6 Already-existing selections are skipped, not treated as failures
- **Steps:** re-open the modal, select a mix of one new domain and one
  already-imported domain (bypass its disabled checkbox via devtools, or
  submit a raw POST including its name), submit.
- **Expected:** the new domain is created; the already-existing one is
  silently skipped and counted in the summary message ("...M already
  existed...") — no error, no duplicate `Domain` created.
- [ ] Pass

### 8.7 Reassign flow updates Infocom, state mirror, and Domain history
- **Steps:** from a mismatch row (8.4), click "Reassign registrar to X" /
  "Set registrar to X".
- **Expected:** the button is replaced with a "Reassigned" success
  indicator in place, a native success toast appears, and — without
  reloading — that row's checkbox becomes disabled. Confirm server-side:
  the Domain's Infocom "Supplier" field now shows this supplier; the
  plugin's state row's `registrar_suppliers_id` mirror matches (via
  `HookHandler::infocomSaved()`); the Domain's Historical tab has a new
  "Registrar supplier changed/set..." entry.
- [ ] Pass

### 8.8 Rights are enforced server-side, not just via a hidden button
- **Steps:** as a user who can edit the supplier but has no `domain`
  CREATE right, POST directly to
  `/plugins/domainmanager/domainimport/{suppliers_id}` bypassing the UI.
- **Expected:** rejected (403) with a clear "you do not have permission to
  create domains" message — the import must fail plainly, not silently
  no-op or partially succeed.
- [ ] Pass

### 8.9 Install/uninstall stays residue-free
- **Steps:** `php bin/console glpi:plugin:install -f domainmanager` then
  uninstall.
- **Expected:** no-op schema-wise both ways — this phase added no tables,
  columns, or rights (`DiscoveredDomain` is never persisted). Confirm
  `SHOW TABLES LIKE 'glpi_plugin_domainmanager%'` is unchanged from before
  this phase.
- [ ] Pass

### 8.10 Modal matches native GLPI chrome and the entity dropdown works fully
- **Steps:** open the Import Domains modal (built with core's
  `Ajax::createModalWindow()`, not a hand-rolled `fetch()`+`innerHTML`
  modal — see ARCHITECTURE.md §9 Phase 8 for the live-caught bug this
  replaced). Inspect the header/dialog chrome; interact with the entity
  dropdown (search, scroll, any "+"-style widget affordance); trigger a
  "Registrar mismatch" reassign action.
- **Expected:** the modal header/close button/dialog styling matches
  every other native GLPI modal (e.g. a massive-action dialog) exactly —
  no custom ribbon banner. The entity dropdown behaves like any other
  native GLPI entity selector: full searchable list (not just a handful
  of preloaded options), any "+"/tree-navigation affordance functional.
  The reassign button's click handler actually fires (confirms embedded
  `<script>` tags in the AJAX-loaded fragment are executing, not just
  inert HTML).
- [ ] Pass

### 8.11 Dinahosting discovery lists the full account with expiration previews
- **Steps:** on the active, configured Dinahosting test supplier, click
  "Import Domains".
- **Expected:** a modal opens listing every domain on that Dinahosting
  account in one response (no internal pagination — confirmed live
  2026-07-27 that `Services_GetDomains` returns a flat, unpaginated list).
  Every row not yet in GLPI shows a real `(Expires YYYY-MM-DD)` hint next
  to "Not yet in GLPI", sourced from the API's own `endDate` field. The
  rest of the flow (existence/mismatch classification, import, reassign)
  behaves identically to the IONOS case (8.3–8.7) since it's the same
  shared `DomainDiscoveryMatcher`/modal/controller code path.
- [ ] Pass
- [ ] Pass

### 8.12 Reassigning a domain's registrar shows "Registrar changed, not yet verified" instead of a stale status (Phase 8 addendum, 2026-07-27)
- **Steps:** pick a domain whose registrar sync currently shows "OK" (a
  real successful sync against its current Supplier). Change its
  registrar to a *different*, already-known Supplier — either via the
  Domain's own Infocom ("Financial and administrative information") tab
  directly, or via the Import modal's "Reassign registrar to X" action.
  Do **not** click Update Now yet. Open the Domain form's Domain Manager
  panel and the new registrar's Supplier tab "Domains" list.
- **Expected:** the Registrar column immediately shows the new Supplier
  (hyperlinked), but the Registrar sync badge shows a new, distinct
  **"Registrar changed, not yet verified"** badge (`text-bg-info`,
  visually distinct from the green "OK", red "Error", and grey "Not
  configured"/"Supplier inactive" badges) — not the old Supplier's stale
  "OK". The old registrar_message is gone (not shown stapled to the new
  Supplier's name). The same badge appears in the new Supplier's own
  "Domains" list row for this domain.
- [ ] Pass

### 8.13 The "Registrar changed" badge clears on the next sync
- **Steps:** immediately after 8.12, click "Update Now" on that same
  domain (or wait for the next cron cycle).
- **Expected:** the badge reverts to a real, freshly-computed status
  ("OK" or "Error", per what the new Supplier's actual API call
  returns) — "Registrar changed, not yet verified" never persists past
  the domain's next sync.
- [ ] Pass

### 8.14 Clearing a registrar (no reassignment) still shows "Not configured", not the new badge
- **Steps:** on a domain with a registrar assigned and a successful past
  sync, clear the Supplier field on its Infocom tab entirely (set to
  none).
- **Expected:** Registrar sync badge shows "Not configured"
  (`STATUS_UNCONFIGURED`), not "Registrar changed, not yet verified" —
  the new badge is reserved for a real hand-off between two known
  suppliers, not for "no registrar at all".
- [ ] Pass

### 8.15 A domain's *first-ever* registrar assignment shows "Not yet checked", not "Registrar changed"
- **Steps:** on a domain that has never had a registrar assigned (no
  Infocom Supplier set, no prior sync), assign a Supplier for the first
  time.
- **Expected:** Registrar sync badge shows "Never synchronized"/"Not yet
  checked" (`STATUS_NEVER`), same as any domain that's never been
  synced — not the "Registrar changed" badge, since there's no previous
  supplier's stale status this could ever be confused with.
- [ ] Pass

## Phase 9 addendum — Design/UX polish (§9)

Cosmetic-only: "Update Now" relocated into the Domain form's own button
row, and a new Entity column on the Supplier tab's "Domains" list. No
schema/rights/logic changes. Verified live against `glpi-claude`
(GLPI 11.0.8, port 65008) with a scripted Playwright session, after
running `bin/console cache:clear` inside the container (its Twig/Symfony
template cache does not appear to auto-invalidate on file mtime changes
the way a dev-mode cache would — see ARCHITECTURE.md §9 Phase 9
addendum).

### 9.1 "Update Now" renders inside the native button row, correctly ordered
- **Steps:** open a Domain form for a domain with `domain` UPDATE rights.
  Inspect the DOM: find `.form-button-separator` and confirm which
  buttons it contains and in what order.
- **Expected:** `#domainmanager-updatenow` is a child of
  `.form-button-separator`, in DOM order `[update (Save), 
  domainmanager-updatenow, delete (Put in trashbin)]` — which, because
  that row uses `flex-row-reverse`, renders visually as **Put in
  trashbin / Update Now / Save**, left to right.
- [x] Pass — confirmed via Playwright: DOM order
  `["update", "domainmanager-updatenow", "delete"]`.

### 9.2 "Update Now" has the same button styling as its row neighbours
- **Steps:** inspect the button's classes and rendered size/padding in
  the browser.
- **Expected:** `btn btn-outline-secondary me-2` — same Bootstrap `.btn`
  sizing/padding/border as "Save" (`btn btn-primary`) and "Put in
  trashbin" (`btn btn-outline-warning`), differing only in color.
- [x] Pass — confirmed via Playwright + screenshot
  (`domain-buttons.png`): all three buttons render as same-sized peers
  in one row.

### 9.3 Ribbon-banner header no longer has a button
- **Steps:** inspect `#domainmanager-panel .card-header` for any
  `<button>` element.
- **Expected:** none — the header shows only the icon ribbon and
  "Domain Manager" title, no corner action button.
- [x] Pass — confirmed via Playwright: `header.querySelector('button')`
  returns `null`.

### 9.4 "Update Now"'s function is unchanged (placement/style-only change)
- **Steps:** click the relocated button; observe the network request it
  fires.
- **Expected:** still `POST /plugins/domainmanager/sync/{id}` (same
  route as before), same badge-refresh behavior on success.
- [x] Pass — confirmed via Playwright: click triggered
  `fetch('/plugins/domainmanager/sync/2')`.

### 9.5 Fallback when `.form-button-separator` isn't present
- **Steps:** (not exercised live — would require a rendering context
  without the standard generic-form button row, e.g. a future modal
  embedding of this panel.) Code path: `relocateUpdateNowButton()` in
  `domain_panel.html.twig` falls back to just un-hiding the button in
  its original DOM position if `.form-button-separator` isn't found,
  rather than leaving it permanently `d-none`.
- **Expected:** button remains reachable (never silently invisible)
  even outside the normal Domain-form context.
- [ ] Pass — not verified live; only reasoned about from the code.

### 9.6 Entity column appears on the Supplier tab's "Domains" list
- **Steps:** open a Supplier's "Domain Manager" tab that has domains
  linked as registrar and/or DNS provider. Inspect the "Domains" table.
- **Expected:** a 4th column, "Entity", after "DNS / NS provider".
- [x] Pass — confirmed via Playwright: table headers
  `["Domain", "Registrar", "DNS / NS provider", "Entity"]`.

### 9.7 Entity value renders in GLPI's native tree/breadcrumb badge style
- **Steps:** with at least one listed domain in the root entity and at
  least one in a child entity, inspect the Entity column's rendered
  markup for each.
- **Expected:** matches core's own entity badge exactly (same
  `glpi-badge` + caret-separated breadcrumb used elsewhere in GLPI, via
  `Entity::badgeCompletenameLinkById()`) — a child-entity domain shows
  its full path (e.g. "TICGAL-Dev-01 › Client1"), a root-entity domain
  shows just its own name, not a bespoke plain-text rendering.
- [x] Pass — confirmed live: a domain moved into a child entity
  ("Client1" under "TICGAL-Dev-01") rendered the correct two-segment
  breadcrumb; root-entity domains rendered the single-segment form
  (screenshot: `supplier-domains-list.png`).

### 9.8 Entity column doesn't cause horizontal overflow at a normal viewport
- **Steps:** load the Supplier tab's Domains list at a 1280px-wide
  viewport; compare `document.body.scrollWidth` to `window.innerWidth`.
- **Expected:** no horizontal overflow — the table fits within the page
  width alongside the existing three columns.
- [x] Pass — confirmed via Playwright at 1280×900:
  `bodyScrollWidth === windowInnerWidth` (1280), `horizontalOverflow:
  false`.

### 9.9 Entity value for a viewer without `Entity` READ
- **Steps:** (not exercised live — would require a second, more
  restricted test profile.) Code path:
  `SupplierTab::describeEntity()` calls
  `Entity::badgeCompletenameById()` (no link) instead of
  `badgeCompletenameLinkById()` when `Session::haveRight('entity',
  READ)` is false, matching core's own `fields_panel.html.twig` logic.
- **Expected:** the same breadcrumb text still renders, just without a
  clickable link on the last segment.
- [ ] Pass — not verified live; only reasoned about from the code
  (mirrors a core pattern verified by direct source read).

## Phase 10 (implemented 2026-07-22) — IDN / Punycode handling (§3.11, §9)

`viñamoraima.com` (the real domain that surfaced this gap while manually
testing Phase 8, 2026-07-22) is a permanent regression case, not a
one-off anecdote. `intl` is confirmed present in this dev environment's
PHP 8.4 (`php -m | grep intl`) and is now a hard activation-time
dependency (`plugin_domainmanager_check_prerequisites()`). Live testing
against a real IONOS account also surfaced a second-round finding
(2026-07-22, §3.11): IONOS's registrar (Domains) API and DNS (zone) API
disagree on Unicode vs. Punycode, fixed per-endpoint in `IonosDriver` —
see 10.1a. The checks below still need a real browser/account pass by
Óscar per this file's standing convention.

### 10.1 IDN domain gets a correct Punycode field and functions end-to-end
- **Steps:** add `viñamoraima.com` (or an equivalent real IDN domain) as
  a `Domain`. Open its Domain Manager panel. Run NS detection, a
  registrar sync, and a DNS sync (Update Now / Check Connection / cron).
- **Expected:** a read-only "Punycode / ASCII form" field shows the
  correct ACE form (`xn--viamoraima-u9a.com`, confirmed via
  `idn_to_ascii()` directly — see §3.11). NS detection, registrar sync,
  and DNS sync all complete with a real result (not silently
  failing/erroring just because the name contains non-ASCII characters)
  — this is the actual regression test for the originally reported bug.
- [ ] Pass

### 10.1a IONOS registrar sync succeeds after the per-endpoint fix (regression)
- **Steps:** with `viñamoraima.com` registered on a live IONOS account
  (both as the registrar and DNS provider), run a registrar/lifecycle
  sync against it. This specifically re-tests two live findings from
  2026-07-22, in order:
  1. The DNS sync succeeded querying IONOS's zone list with the
     Punycode form, but the Domains (registrar) API's `name` filter
     returned `"No IONOS domain item found for xn--viamoraima-u9a.com
     with this account"` when queried with that same Punycode string.
  2. Switching that query to the literal Unicode form instead (the
     first attempted fix) was worse: IONOS's own gateway returned a raw,
     non-JSON HTML `400 Bad request` page — confirmed not a
     request-encoding bug on this plugin's side, meaning the gateway
     itself rejects non-ASCII in that parameter outright.
- **Expected:** the registrar sync now succeeds. `IonosDriver::findDomainId()`
  no longer sends a `name` filter at all — it paginates through the
  full unfiltered `domainitems` list and matches each row's `name`
  client-side (canonicalized to Punycode). DNS sync continues to
  succeed unaffected (`findZoneId()`/`fetchZoneRecords()` still query
  with Punycode, and never used a server-side name filter to begin
  with).
- [ ] Pass

### 10.2 Plain ASCII domains are unaffected
- **Steps:** open the Domain Manager panel for an ordinary ASCII-only
  domain (e.g. `tic.gal`).
- **Expected:** no behavior change from before Phase 10; the Punycode
  field is **omitted entirely** (decided/documented in §3.11 — not shown
  as a duplicate of `name`), so the panel looks identical to before this
  phase.
- [ ] Pass

### 10.3 `intl` extension is a hard activation-time dependency
- **Steps:** on an environment without the `intl` PHP extension, attempt
  to activate the plugin.
- **Expected:** `plugin_domainmanager_check_prerequisites()` blocks
  activation with a clear message naming `intl` and its purpose, rather
  than activating successfully and failing later with an opaque fatal
  error on the first sync.
- [ ] Pass — not verified live (would need a container without `intl`);
  reasoned about from the code, matches the documented GLPI
  `check_prerequisites` convention.

### 10.4 Bulk-import existence check isn't fooled by a Unicode/Punycode mismatch
- **Steps:** with a registrar Supplier whose driver supports
  `listAccountDomains()` (IONOS, §9 Phase 8) and which has
  `viñamoraima.com` registered, also create the domain as a native
  `Domain` item in GLPI (stored as Unicode, per GLPI convention). Open
  the Import Domains modal for that Supplier.
- **Expected:** the domain shows as already existing (a real match, not
  a false "new domain" row) regardless of which form (Unicode or
  Punycode) the registrar's API happens to return in its discovered-domain
  list — `DomainDiscoveryMatcher::normalize()` canonicalizes both sides to
  Punycode before comparing (§3.11).
- [ ] Pass

## Phase 12 — Configurable "Domain type to apply to imported domains" (§6.6, §9)

### 12.1 Fresh install defaults to unset, no type ever applied
- **Steps:** on a fresh install (no prior Domain Manager version ever
  activated on this GLPI instance), activate the plugin. Open Setup >
  General > "Domain Manager" tab.
- **Expected:** the "Domain type to apply to imported domains" dropdown
  shows "-----" (unset). Import a domain via the Import Domains modal
  (§9 Phase 8).
- [ ] Pass

### 12.2 Fresh-install import leaves `Type` unset, like a hand-created domain
- **Steps:** continuing from 12.1, open the newly-imported domain's form.
- **Expected:** the "Type" field on the domain's own native form reads
  "-----", exactly as it would for a domain created by hand through
  Setup > Assets > Domains > "+". Nothing in the plugin sets or revisits
  it afterward, including on a later sync ("Update Now").
- [ ] Pass

### 12.3 Setting a type applies it only to domains created afterward
- **Steps:** in the "Domain Manager" config tab, set "Domain type to
  apply to imported domains" to "Internet Domain" (or any other existing
  `DomainType`) and save. Import a new domain via the Import Domains
  modal.
- **Expected:** a "Configuration saved" confirmation appears after
  saving. The newly-imported domain's `Type` field is set to the chosen
  value immediately on creation.
- [ ] Pass

### 12.4 A manual `Type` change is never reverted by a sync
- **Steps:** on the domain imported in 12.3, manually change its `Type`
  field (on the domain's native form) to a different `DomainType`, or
  clear it back to "-----". Trigger "Update Now".
- **Expected:** the sync completes normally (registrar/DNS status
  updates as usual), and `Type` still shows the admin's manually-chosen
  value afterward — unchanged by the sync.
- [ ] Pass

### 12.5 Upgrade from a pre-Phase-12 install preserves prior behavior automatically
- **Steps:** on an instance that already ran a pre-0.9.0 version of the
  plugin (i.e. "Internet Domain" was already seeded by a previous
  `Installer::seedDomainType()` call, from unconditionally force-assigning
  it), upgrade to this version and let the plugin's install/migration
  step run (reactivate, or `bin/console glpi:plugin:install --force`).
  Open Setup > General > "Domain Manager" without touching anything.
- **Expected:** the config already reads "Internet Domain" — no
  reconfiguration was needed to keep the previous effective behavior.
  Importing a domain afterward still gets "Internet Domain" applied,
  identical to pre-upgrade behavior, purely from the automatic default.
- [ ] Pass

### 12.6 Clearing the setting back to unset is honored, not silently re-defaulted
- **Steps:** with the config set to a real `DomainType` (from 12.3 or
  12.5), open the config tab again, set the dropdown back to "-----",
  and save. Deactivate and reactivate the plugin (re-running
  `Installer::install()`). Import a new domain.
- **Expected:** the config still reads "-----" after reactivation (the
  install-time default-seed is a one-time, already-configured check —
  it never re-applies once a value, including an explicit "unset", has
  ever been stored). The newly-imported domain's `Type` is left unset.
- [ ] Pass

### 12.7 Right enforcement on the config page
- **Steps:** as a user/profile without `config` UPDATE, attempt to
  reach `/plugins/domainmanager/Config` or POST to
  `/plugins/domainmanager/Config/Save` directly.
- **Expected:** both are rejected (`Session::checkRight()` denial),
  matching the same `config` UPDATE gate every super-admin profile
  already holds by default for this genuinely global setting.
- [ ] Pass

### 12.8 Uninstall leaves no config residue
- **Steps:** uninstall the plugin (UI or
  `bin/console glpi:plugin:uninstall`), having previously set the
  "Domain type to apply to imported domains" config to a real value.
- **Expected:** the `plugin:domainmanager` context in `glpi_configs` is
  fully purged (`Config::uninstall()`) — no leftover rows, matching every
  other phase's zero-residue rule.
- [ ] Pass

## Phase 14 — Conditional field locking, Registrar lock + Unlink action, Domain-level "Managed" field (§0.3, §9)

### 14.1 Registration date stays unlocked when the driver never reports it
- **Steps:** sync a domain via a registrar driver whose `fetchLifecycle()`
  never sets `registrationDate` (e.g. IONOS, which only reports
  expiration/status — confirm against the driver's own code first). After
  sync, edit the domain's "Registration date" field directly.
- **Expected:** the edit saves normally — `date_domaincreation` was never
  added to the lock set for this domain. `date_expiration`/`is_active`
  (if reported) are locked as expected (regression of already-correct
  behavior, §0.3).
- [ ] Pass

### 14.2 `is_active` is always locked (no "not reported" case exists)
- **Steps:** after any successful registrar sync, attempt to edit the
  domain's `is_active`/status-derived field directly without the unlock
  right.
- **Expected:** stripped with a warning — `LifecycleStatus` is a mandatory
  DTO field every driver always populates, so this is correct, not a gap.
- [ ] Pass

### 14.3 DomainRecord locking is now conditional per-sync (behavior-preserving today)
- **Steps:** sync a domain's DNS records, then attempt to edit a
  plugin-imported record's `name`/`data`/`ttl`/`domainrecordtypes_id`
  without the unlock right.
- **Expected:** all four are still stripped with a warning, same as
  before — today's `ZoneRecord` DTO reports all four unconditionally, so
  `RecordReconciler`'s new per-sync `ImportLock::replaceLocks()` calls
  lock the same fields the old static list did. `domains_id` remains
  always-locked (structural, not driver-dependent).
- [ ] Pass

### 14.4 Registrar (`suppliers_id`) locks once a confirmed match exists
- **Steps:** sync a domain to `registrar_status = STATUS_OK`. As a user
  **without** `domainmanager:unlock_imported`, open the domain's Infocom
  ("Financial and administrative information") tab and change the
  Supplier field directly, then save.
- **Expected:** the change is stripped with a warning
  ("The registrar is locked..."), the Supplier field reverts to its prior
  value on reload. Retry as a right-holder — the change saves normally.
- [ ] Pass

### 14.5 A reassignment/error/never-synced registrar stays freely editable
- **Steps:** on a domain whose `registrar_status` is `never`, `error`, or
  `reassigned` (not `ok`), change the Infocom Supplier field as a user
  without the unlock right.
- **Expected:** the change saves — "confirmed working registrar match" is
  strictly `STATUS_OK`, so none of these count as locked.
- [ ] Pass

### 14.6 "Unlink registrar" clears the assignment without the unlock right
- **Steps:** on a domain with `registrar_status = STATUS_OK`, as a user
  who can update the Domain but does **not** hold
  `domainmanager:unlock_imported`, click "Unlink registrar" next to the
  Registrar field on the Domain Manager panel.
- **Expected:** the action succeeds (POST
  `/plugins/domainmanager/domainunlink/{id}` returns `{ok: true}`), the
  Infocom Supplier field clears, `registrar_status` resets to
  `unconfigured` (via the existing `infocomSaved()` transition logic), and
  the Domain's Historical tab logs "Registrar supplier cleared" — all
  without needing the unlock right, mirroring the existing Reassign
  action's own narrower auth scope.
- [ ] Pass

### 14.7 Add Record remains available and unrestricted on managed domains
- **Steps:** on an actively-managed (synced) domain, use the native
  "Add" affordance on the Records tab to manually create a new
  `DomainRecord`.
- **Expected:** the affordance is present and enabled regardless of the
  domain's managed status (no gating code exists — confirmed by
  code review, `DomainRecord::showForDomain()` is never overridden by this
  plugin). The new record has `ImportedRecord::isPluginOwned()` false, no
  `ImportLock` rows, and is fully editable (this supersedes an earlier
  addendum's never-implemented "hide Add Record on managed domains" idea).
- [ ] Pass

### 14.8 Domain-level "Managed" search option reflects registrar-or-DNS resolution
- **Steps:** across three domains — (a) both registrar and DNS resolve to
  a real, active, driver-configured supplier; (b) only one role does; (c)
  neither does (no supplier assigned, or assigned to an inactive/
  unconfigured supplier) — open Setup > Assets > Domains (native list) and
  filter by the new "Managed" column.
- **Expected:** (a) and (b) show `Managed = Yes`, (c) shows `Managed = No`
  or blank for a domain with no state row at all. Filtering by
  `Managed = Yes`/`No` returns exactly the expected set.
- [ ] Pass

### 14.9 "Managed" reflects reassignment immediately, without waiting for a sync
- **Steps:** on a fully-unmanaged domain (no registrar, no DNS resolved),
  assign a registrar via Infocom to a Supplier with a valid, active,
  configured driver and credentials. Check the "Managed" search option
  immediately, before triggering any sync.
- **Expected:** `Managed` already reads `Yes` — computed live in
  `HookHandler::infocomSaved()` via `DomainState::resolvesToActiveDriver()`,
  not deferred to the next sync. Unlink the registrar (14.6) on a domain
  with no DNS role resolved — `Managed` drops back to `No` immediately.
- [ ] Pass

### 14.10 Upgrade backfills `is_managed` from existing sync history
- **Steps:** on an instance with domains already synced under a
  pre-Phase-14 version (real `registrar_status`/`dns_status` history
  already stored), upgrade and let `Installer::addDomainManagedColumn()`
  run.
- **Expected:** every domain whose last known `registrar_status` or
  `dns_status` is `ok`/`error` immediately reads `Managed = Yes` after
  the migration — not `No` until its next sync happens to run.
- [ ] Pass

### 14.11 Search UI cleanup (addendum, 2026-07-27) — duplicate/dummy options dropped
- **Steps:** live testing surfaced three problems with the Search UI's
  field/criteria picker for `Domain`: a generic "Plugins" category (not
  naming the plugin) containing a "Domain Manager" entry with no real
  filtering value (duplicate of Name), and a "Registrar (Financial
  information)" entry duplicating a native option. Open Domain's Search
  "+" column/criteria picker and inspect the available fields.
- **Expected:** no "Domain Manager" (dummy, ex-`9402`) or "Registrar
  (Financial information)" (ex-`9403`) entries remain. The equivalent
  Supplier-side dummy ("Domain Manager", ex-`9401`) is also gone from
  Supplier's own picker.
- [ ] Pass

### 14.12 Managed options group under a "Domain Manager" category, not generic "Plugins"
- **Steps:** in the same picker, locate the "Managed" option for `Domain`
  and for `DomainRecord`.
- **Expected:** both appear grouped under a category header reading
  "Domain Manager" (not a generic, unlabeled "Plugins" bucket), via the
  new `'id' => 'domainmanager'` category-tab entry each itemtype's option
  group now opens with.
- [ ] Pass

### 14.13 "Managed" filters as a plain Yes/No
- **Steps:** filter Domain's native search by "Managed" = Yes, then No.
  Repeat for `DomainRecord`'s "Managed" option.
- **Expected:** both are simple two-value (Yes/No) filters (native `bool`
  datatype `equals`/`notequals`), returning exactly the expected managed
  vs. unmanaged sets — no more elaborate filter type is offered or needed.
- [ ] Pass

### 14.14 Historical tab entries now show a blank field + "[Domain Manager]" prefix
- **Steps:** trigger a registrar-supplier assignment change, a
  SupplierConfig credential change, and a sync milestone. Open each
  affected item's Historical tab.
- **Expected:** the "field" column is now blank (`id_search_option = 0`)
  and the message text itself starts with `"[Domain Manager] "` — e.g.
  `"[Domain Manager] Registrar supplier cleared"` — instead of the
  previous "Domain Manager"-labeled field column with an unprefixed
  message.
- [ ] Pass

### 14.15 Supplier tab's "Domains" list deep link still works via the native option
- **Steps:** from a Supplier's Domain Manager tab, click the recommendation
  banner's "Review and sync" link (or any link built by
  `SupplierTab::getDomainsSearchUrl()`).
- **Expected:** lands on Domain's native search, correctly pre-filtered to
  this supplier via native search option id `53` — same correct behavior
  as before (5.5.8), now via the native option instead of the dropped
  `PLUGIN_DOMAINMANAGER_SO_DOMAIN_REGISTRAR`.
- [ ] Pass

### 14.16 Remaining search-option ID collision check
- **Steps:** run `php tools/getsearchoptions.php --type=Domain` and
  `--type=DomainRecord` against the target instance (requires DB access)
  before go-live.
- **Expected:** IDs `9404`/`9405` (DomainRecord) and `9406` (Domain) are
  not already used by another installed plugin for that itemtype. Once
  confirmed clean, flip `verified_collision_free` to `true` for the
  `9406` entry in `search-options-registry.json` (repo root) — `9404`/
  `9405` are already marked verified from a prior pass.
- [ ] Pass

## 15. Phase 15 regression: version bump now triggers the pending Phase 14 migration

### 15.1 Upgrading from a pre-0.10.0 install no longer crashes the Domains search
- **Requirement verified:** `PLUGIN_DOMAINMANAGER_VERSION` bump (0.9.0 →
  0.10.0, this release) makes GLPI's version-mismatch check re-run
  `Installer::install()` — including `addDomainManagedColumn()` — on an
  instance that was already activated at 0.9.0 and therefore never got
  the `is_managed` column added to `glpi_plugin_domainmanager_states`.
- **Steps:** on an instance previously activated at plugin version 0.9.0
  (Phase 14's code present but version constant not yet bumped, the exact
  state that produced the crash), deploy 0.10.0's code and go to
  Setup > Plugins to trigger the version-mismatch reactivation/update.
  Then open the native Domains search and add the "Managed" column/filter.
- **Expected:** no `Unknown column
  'glpi_plugin_domainmanager_states_domains_id.is_managed'` (or any other)
  SQL error; the migration log shows the `is_managed` column/key being
  added; the "Managed" column/filter renders and filters correctly.
- [ ] Pass

### 15.2 "Managed" search option returns correct values for a managed/unmanaged mix
- **Steps:** with at least one domain whose Registrar or DNS role
  currently resolves to a real, active, driver-configured supplier, and
  at least one domain with neither, filter Domain's native search by
  "Managed" = Yes, then No.
- **Expected:** the managed domain(s) appear only under Yes, the
  unmanaged domain(s) (including any with no state row at all) only under
  No — confirms the `child`-join wiring itself (unchanged by this fix)
  is correct, isolating the regression to the version-bump gap in 15.1.
- [ ] Pass

### 15.1b Upgrade itself no longer crashes with "Unknown column 'is_managed' in 'SET'"
- **Requirement verified:** `Installer::addDomainManagedColumn()` now calls
  `$migration->executeMigration()` right after queuing the `is_managed`
  field/key, flushing that `ALTER TABLE` before its own backfill loop's
  raw `$DB->update()` calls run against the column — previously the
  backfill ran while the column was still only queued, not yet physically
  added, and only on upgrade (`!$column_existed` branch), never on a
  fresh install.
- **Steps:** on an instance previously activated at plugin version 0.9.0
  (`is_managed` column absent from `glpi_plugin_domainmanager_states`,
  with a realistic mix of rows — some with `registrar_status`/
  `dns_status` in `ok`/`error`, some `never`), deploy 0.10.0's code and
  trigger the plugin update from Setup > Plugins.
- **Expected:** no `Unknown column 'is_managed' in 'SET'` (or any other)
  SQL error during the update; the migration completes; every
  pre-existing row whose `registrar_status`/`dns_status` was `ok`/`error`
  is backfilled to `is_managed = 1`, every other row stays `0`.
- [ ] Pass

### 15.1c Stale saved-search criteria referencing a dropped search-option ID no longer warn
- **Requirement verified:** `Installer::pruneStaleSearchOptionCriteria()`
  rewrites any `glpi_savedsearches` row for `Domain`/`Supplier` still
  referencing one of the three retired dummy/duplicate IDs (`9401`/
  `9402`/`9403`, dropped in the Phase 14 addendum), stripping only that
  criterion.
- **Steps:** before upgrading, manually create (or confirm an existing)
  saved search / bookmark on `Domain` with a criterion using field id
  `9403` (or `9402`), and one on `Supplier` using `9401` — e.g. by
  crafting the URL query string directly, since the option no longer
  exists to pick from the UI. Deploy 0.10.0 and trigger the plugin
  update. Then open that saved search from Domain's/Supplier's saved
  searches list.
- **Expected:** no `Attempted to use invalid search options...` warning
  (dev/debug mode) and no "Some search criteria were removed..." message
  on open; the saved search's other criteria/sort/columns are unchanged;
  inspecting `glpi_savedsearches.query` for that row shows the stale
  `criteria[n][field]=9403`-style entry gone, everything else intact.
- [ ] Pass

### 15.1d Session-persisted stale search criteria no longer recur (0.10.1)
- **Requirement verified:** `HookHandler::scrubStaleSearchSessionCriteria()`
  (`Hooks::POST_INIT`) strips a stale `criteria`/`sort` reference to
  Supplier `9401` or Domain `9402`/`9403` from
  `$_SESSION['glpisearch']` on every page load — the companion fix to
  15.1c above for the case where the stale ID lives purely in session,
  not in a persisted `glpi_savedsearches` row.
- **Steps:** with `glpi_savedsearches`/`glpi_savedsearches_users` empty
  (confirmed, not just assumed — e.g. via `SELECT * FROM
  glpi_savedsearches`), reproduce a session that has picked up field
  `9403` in `$_SESSION['glpisearch']['Domain']['criteria']` (however it
  was originally seeded), then load the Domain list again.
- **Expected:** no `Attempted to use invalid search options...` warning
  (dev/debug mode) on this or any subsequent request; the Domain list
  renders with that criterion silently dropped, not reintroduced on the
  next reload.
- [ ] Pass

### 15.3 DomainRecord-level "Managed" field unaffected (audit, no fix needed)
- **Requirement verified:** `PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_MANAGED`
  already correctly targets `ImportedRecord::getTable()` /
  `is_managed` (a table Phase 5.7 created directly, no post-hoc migration
  gap) — audited against this same crash and found not to share the root
  cause.
- **Steps:** filter DomainRecord's native search by "Managed" = Yes, then
  No, on an instance upgraded to 0.10.0.
- **Expected:** correct results, no SQL error — confirms no regression
  and no latent version-bump gap for this option.
- [ ] Pass

## 16. Phase 15 addendum: new Domain search options ("Searchable fields")

### 16.1 "NS Provider" filters via a real dropdown, not free text
- **Requirement verified:** `PLUGIN_DOMAINMANAGER_SO_DOMAIN_NS_PROVIDER`
  (id `9407`) renders its search criteria value as a `<select>`
  (`DomainState::getSpecificValueToSelect()`), populated live from
  `NsProviderRegistry::getProviders()` plus "Unknown".
- **Steps:** on Domain's native search, add a criterion for "NS Provider"
  (under the "Domain Manager" category). Open its value dropdown.
- **Expected:** a `<select>`, not a text box, listing every provider
  currently in `resources/ns-providers.json` plus "Unknown" — no
  "invalid search option" warning. Filtering by a real provider name
  returns exactly the domains whose last NS lookup matched it; filtering
  by "Unknown" returns domains whose NS hosts didn't match any registry
  entry.
- [ ] Pass

### 16.2 "Registrar sync status" / "DNS sync status" filter via a real dropdown
- **Requirement verified:** `PLUGIN_DOMAINMANAGER_SO_DOMAIN_REGISTRAR_STATUS`
  (id `9408`) / `PLUGIN_DOMAINMANAGER_SO_DOMAIN_DNS_STATUS` (id `9409`)
  render as a `<select>` populated from `DomainState::getStatusLabels()`.
- **Steps:** add both criteria on Domain's native search; open each value
  dropdown; pick "OK", then "Error", then "Not configured".
- **Expected:** the dropdown lists all 8 status labels (Never
  synchronized, OK, Error, Not configured, Provider not supported,
  Provider unknown, Supplier inactive, Registrar changed/not yet
  verified); each choice returns exactly the domains whose
  `registrar_status`/`dns_status` matches; the displayed column shows
  the same human label, not the raw enum string.
- [ ] Pass

### 16.3 Domain form panel's status badge still matches the search dropdown's labels
- **Requirement verified:** `DomainForm` now delegates to
  `DomainState::getStatusLabels()`/`getStatusClasses()` instead of
  keeping its own copies — the two can't drift apart.
- **Steps:** open a domain with a non-"Never synchronized" status; note
  its badge text. Compare against the label shown for the same domain's
  row when filtering "Registrar sync status"/"DNS sync status" in
  search.
- **Expected:** identical wording in both places.
- [ ] Pass

### 16.4 "Last sync" sorts and filters by date, including "never synced"
- **Requirement verified:** `PLUGIN_DOMAINMANAGER_SO_DOMAIN_LAST_SYNC`
  (id `9410`), a native `datetime` search option — sortable, and
  filterable via GLPI's built-in date criteria.
- **Steps:** sort Domain's native search by "Last sync" ascending and
  descending. Add a "Last sync" criterion with searchtype "before" a
  recent date, then with "is empty".
- **Expected:** sort order is chronological (domains with no state row
  at all sort as empty/lowest); "before" returns domains last synced
  before that date; "is empty" returns domains that have never synced.
- [ ] Pass

### 16.5 No SQL errors / invalid-search-option warnings on any of the four
- **Steps:** with all four new criteria added simultaneously on the same
  search, reload the Domain list several times.
- **Expected:** no `Unknown column`/`invalid search option` warnings; all
  four columns/criteria continue to work together (AND/OR combinations).
- [ ] Pass

## 17. Phase 16: Registrar/DNS status is identical everywhere, from any supplier tab

Permanent regression case — the bug this covers only reproduces when a
domain's registrar and DNS provider are **different suppliers**, so it must
be checked from every supplier tab a domain is linked to, not just its own
Domain form. Verify against `ticgal.com`, `tic.gal`, and `miedoavolar.es`
specifically (real domains with this registrar/DNS-provider split used to
diagnose the original bug).

### 17.1 Registrar status matches between the Domain form and every linked supplier's "Domains" tab
- **Steps:** for a domain whose registrar and DNS provider are different
  suppliers (e.g. `ticgal.com`, registrar IONOS / DNS Cloudflare): open its
  Domain form and note the Registrar sync badge. Then open the *DNS
  provider's* Supplier ("Cloudflare") "Domains" tab and find the same domain's
  row.
- **Expected:** the Registrar badge shown in Cloudflare's "Domains" tab is
  identical to the Domain form's (e.g. both "OK"), never "Not yet checked"
  just because the tab being viewed isn't the registrar.
- [ ] Pass

### 17.2 DNS Provider column keeps its own managed/unmanaged/unknown/never badge (not the shared sync-outcome vocabulary)
- **Note:** an earlier draft of this phase switched the NS column to the same
  `dns_status`-driven badge the Registrar column uses; that was reverted
  after live testing showed it as a regression (worse/wrong-looking status
  for genuinely-managed domains, ARCHITECTURE.md §9 Phase 16). The NS column
  keeps its pre-existing `SupplierTab::describeDnsProvider()` classification
  ("Plugin managed"/"Known, unmanaged (yet)"/"Unknown"/"Not yet checked").
- **Steps:** on a domain whose DNS provider is actively managed (a real
  Supplier linked via `dns_suppliers_id`), check the NS column badge in that
  supplier's "Domains" tab.
- **Expected:** green "Plugin managed" badge (red only if the last sync
  actually errored) — not a `dns_status`-literal label like "Never
  synchronized"/"Not configured" for a domain that is in fact managed.
- [ ] Pass

### 17.3 No lag immediately after a sync, either path
- **Steps:** trigger a sync for the domain both via "Update Now" (Domain
  form) and via the batch "Review and sync" massive action, and re-check
  both the Domain form and every linked supplier tab right after each.
- **Expected:** badges update immediately and match everywhere with zero lag
  after either sync path.
- [ ] Pass

## 18. Phase 17: Domain identity header redesign + Punycode search

### 18.1 IDN domain: two-line identity block renders correctly
- **Steps:** open the Domain form for an IDN domain whose stored name
  differs from its Punycode form (e.g. `viñamoraima.com`, ID 20 on the
  `glpi-claude` dev instance).
- **Expected:** line 1 shows the Unicode name in 19px/500 plain text (not a
  link), followed by a small "IDN" badge. Line 2 shows the Punycode form
  (`xn--viamoraima-u9a.com`) in muted/monospace/small text, with an
  icon-only copy button next to it.
- [x] Pass — verified 2026-07-28 by rendering `Domain$main` via
  `ajax/common.tabs.php` for domain ID 20: `font-size: 19px; font-weight:
  500;`, `<span class="badge text-bg-info ms-1">IDN</span>`, `<code>xn--
  viamoraima-u9a.com</code>` all present.

### 18.2 Pure-ASCII domain: line 1 only, no badge, no line 2
- **Steps:** open the Domain form for a domain whose name is already plain
  ASCII (e.g. `beiro.net`, ID 1).
- **Expected:** only the name line renders — no "IDN" badge, no Punycode
  line, no copy button.
- [x] Pass — verified 2026-07-28 for domain ID 1: only `beiro.net` in the
  19px/500 line; no "IDN"/`ti-copy`/`code` markup present.

### 18.3 Visit button opens the Punycode form; WHOIS uses who.is
- **Steps:** on the IDN domain's identity row, check the "Visit" button's
  `href` and the "WHOIS" button's `href`.
- **Expected:** Visit's `href` is `https://<punycode>` (not the Unicode
  name), with the Unicode name as its `title` tooltip; WHOIS's `href` is
  `https://who.is/whois/<punycode>`. Both open in a new tab
  (`target="_blank" rel="noopener noreferrer"`). On a pure-ASCII domain,
  both use the plain name instead.
- [x] Pass — verified 2026-07-28: domain 20's Visit `href` is
  `https://xn--viamoraima-u9a.com` with `title="viñamoraima.com"`; WHOIS
  `href` is `https://who.is/whois/xn--viamoraima-u9a.com`. Domain 1's Visit/
  WHOIS both use `beiro.net`.

### 18.4 Copy button copies the exact Punycode form
- **Steps:** click the copy icon next to the Punycode line on an IDN
  domain, then paste the clipboard contents somewhere.
- **Expected:** clipboard contains the Punycode form exactly (e.g.
  `xn--viamoraima-u9a.com`), with a "Copied to clipboard" toast. Uses
  GLPI 11 core's own `[data-glpi-clipboard-text]` delegated handler
  (`js/common.js`) — no plugin JS was added.
- [ ] Pass (manual browser check needed — clipboard access requires a real
  browser context, not reproducible via curl)

### 18.5 "Not reported by this driver" / "Not on file" replaced with a muted em-dash + tooltip
- **Steps:** open the Registrar details table for a domain where the
  driver doesn't report one of WHOIS privacy / Transfer lock / Domain lock
  / Auto-renew / DNSSEC / Transfer-EPP-auth-code.
- **Expected:** that cell shows a muted "—" instead of the full sentence;
  hovering it shows the same sentence as a tooltip. Populated Yes/No badges
  are unaffected.
- [ ] Pass

### 18.6 Punycode search finds the domain
- **Steps:** search Domain using the new "Punycode name" search option
  (id 9416) with a Punycode string pasted from a DNS log (e.g.
  `xn--viamoraima`, `contains`).
- **Expected:** the matching domain (e.g. `viñamoraima.com`, ID 20) appears
  in the results, even though its stored `name` is the Unicode form.
- [x] Pass — verified 2026-07-28 via
  `front/domain.php?criteria[0][field]=9416&criteria[0][searchtype]=contains&criteria[0][value]=xn--viamoraima`:
  `viñamoraima.com` returned.

### 18.7 `name_ascii` backfill covers every existing Domain on upgrade
- **Steps:** after upgrading to 0.11.9, check
  `glpi_plugin_domainmanager_states.name_ascii` for a Domain that already
  had a state row and one that didn't yet (no registrar/sync history).
- **Expected:** both get a correct Punycode value; pure-ASCII domains get
  their name unchanged; a state row is created for a Domain that had none.
- [x] Pass — verified 2026-07-28: `name_ascii` correctly backfilled for
  IDs 1, 2, 3, 5, 11, 12, 14-30 (ASCII: unchanged) and ID 20 (IDN:
  `xn--viamoraima-u9a.com`). Trashed domain ID 13 (`is_deleted=1`)
  correctly excluded from backfill (matches native search's own exclusion
  of trashed items by default).

### 18.8 No new PHP warnings/notices from this phase
- **Steps:** after exercising 18.1-18.7, check
  `/var/glpi/logs/php-errors.log` and `domainmanager-errors.log` for any
  new entries.
- **Expected:** no new warnings/notices — only pre-existing sync-history
  log lines from before this phase.
- [x] Pass — verified 2026-07-28: no new entries in either log after the
  above requests; `php -l` clean on every touched file (`setup.php`,
  `src/Installer.php`, `src/HookHandler.php`, `src/DomainForm.php`).

## Phase 21-23 (implemented 2026-07-28) — RDAP as a fallback/supplementary data source (§9)

### 21.1 Cron task registration
- **Steps:** Setup > Automatic actions, locate "RdapEnrichment".
- **Expected:** appears with default 10-minute frequency, independent of
  the existing "DomainSync" task.
- [ ] Pass

### 21.2 Exactly one eligible domain processed per execution; already-checked-today domains skipped
- **Steps:** run the RdapEnrichment task twice in the same day.
- **Expected:** the first run processes one domain and sets its
  `last_rdap_check_date` to today; the second run skips it (moves on to
  the next oldest-/never-checked candidate, or no-ops if none remain).
- [ ] Pass

### 21.3 A domain whose driver already reports every in-scope field is never selected
- **Steps:** run RdapEnrichment against a domain fully reported by its
  driver (e.g. IONOS, which reports DNSSEC) once RDAP has already filled
  its other gaps (dates, last-changed, pending flags).
- **Expected:** `RdapGapChecker::hasGap()` returns `false`; the domain is
  skipped in favor of the next candidate.
- [ ] Pass

### 21.4 A real `.es` domain gets a normal "no data" outcome, not an error
- **Steps:** run RdapEnrichment against a `.es` domain.
- **Expected:** `rdap.org` 404s; `domainmanager.log` gets a "no data for
  domain" entry (not `domainmanager-errors.log`); `last_rdap_check_date`
  is still set so it isn't retried until tomorrow.
- [ ] Pass

### 21.5 A real `.com` and a real `.gal` domain populate all applicable new columns
- **Steps:** run RdapEnrichment against a `.com` and a `.gal` domain.
- **Expected:** `rdap_last_changed_date`, `rdap_pending_delete`,
  `rdap_pending_transfer`, `rdap_registrar_name`, `rdap_registrar_iana_id`,
  `rdap_nameservers` populate where RDAP has data; registration/expiration
  dates on `glpi_domains` fill in only if they were previously empty.
- [ ] Pass

### 21.6 DNSSEC gap-fill: skipped when the driver reports it, applied when it doesn't
- **Steps:** compare an IONOS-registered domain (reports
  `registrar_dnssec_enabled`) against a Dinahosting-registered domain
  (doesn't) after both have had at least one RDAP pass.
- **Expected:** the IONOS domain's `rdap_dnssec_signed` stays untouched by
  gap-fill logic (not a gap); the Dinahosting domain gets
  `rdap_dnssec_signed` populated and the Registrar details table's DNSSEC
  cell shows the RDAP value with a "via RDAP" tooltip.
- [ ] Pass

### 21.7 Registrar-of-record mismatch badge — only on an actual mismatch
- **Steps:** open the Domain form for a domain where `rdap_registrar_name`
  differs from the Infocom Supplier's name, and for one where they agree
  (or RDAP hasn't reported a registrar name at all).
- **Expected:** the "RDAP cross-check" sub-panel and its "Registrar
  mismatch" badge appear only in the first case; no sub-panel visible at
  all in the second (no badge noise on the common/agreeing case).
- [ ] Pass

### 21.8 Nameserver cross-check badge — only on an actual mismatch, never fed into the DNS pipeline
- **Steps:** open the Domain form for a domain where RDAP's
  `rdap_nameservers` differs from a live NS lookup, and for one where they
  match.
- **Expected:** "Nameserver mismatch" badge shown only in the first case;
  in both cases, confirm `rdap_nameservers` never appears in
  `DomainRecord`/`RecordReconciler` — it's read via `DomainForm::inject()`
  and rendered directly in Twig, never passed to the sync pipeline.
- [ ] Pass

### 21.9 Clean no-op tick — no error, no unnecessary log entries
- **Steps:** run RdapEnrichment when every domain either was checked today
  or has no gap.
- **Expected:** a single "No domain eligible for RDAP enrichment" line in
  `domainmanager.log`; nothing in `domainmanager-errors.log`.
- [ ] Pass

### 21.10 Config page status line
- **Steps:** open the Domain Manager config page after at least one
  RdapEnrichment run.
- **Expected:** shows "N domain(s) pending RDAP enrichment, last processed
  at [time]" with a real count and timestamp; before any run has ever
  happened, shows "…none processed yet" instead.
- [ ] Pass

## Phase 24 (implemented 2026-07-28) — Richer automatic-action logs (§9)

### 24.1 DomainSync log shows a per-entity breakdown
- **Steps:** run the DomainSync task against domains spanning at least
  two entities, then check Setup > Automatic actions > DomainSync > Logs
  for that run.
- **Expected:** the log description has the overall "Synchronized N
  domain(s), M with errors" line, plus one line per entity actually
  touched, each naming the entity and its own count/error total.
- [ ] Pass

### 24.2 DomainSync log shows a per-registrar-supplier breakdown
- **Steps:** same run as 24.1, with domains spanning at least two
  registrar Suppliers.
- **Expected:** one additional log line per registrar Supplier touched
  ("Registrar X: N domain(s) synchronized (M error(s))"); a domain with
  no registrar assigned is excluded from this breakdown (not a bogus "no
  registrar" line).
- [ ] Pass

### 24.3 RdapEnrichment log names the entity, registrar, and outcome for the single domain processed
- **Steps:** run RdapEnrichment against a domain with a real gap and a
  registrar assigned, then check its log line for that run.
- **Expected:** one line reading `<Entity>: domain #<id> (<name>),
  registrar <Supplier> — filled <fields...>` (or "no registrar" if none
  assigned, or "no new data from RDAP"/"no data (TLD/domain not covered)"
  for those outcomes) — not just a bare "Processed RDAP enrichment for
  domain #<id>".
- [ ] Pass

### 24.4 No new PHP warnings/notices from this phase
- **Steps:** after exercising 24.1-24.3, check `/var/glpi/logs/php-errors.log`
  and `domainmanager-errors.log` for any new entries.
- **Expected:** no new warnings/notices; `php -l` and `phpcs` clean on
  `src/Cron.php`.
- [ ] Pass

## Phase 25 (implemented 2026-07-28) — Last changed / last transfer RDAP dates (§9)

### 25.1 New column populates and displays on migration/upgrade
- **Steps:** run the plugin's migration (fresh install or upgrade past
  this version), then check `glpi_plugin_domainmanager_states` schema.
- **Expected:** `rdap_transfer_date` (datetime, nullable) exists; no error
  on either a fresh install or an upgrade of an already-migrated instance.
- [ ] Pass

### 25.2 "Last changed" and "Last transfer" columns appear between "DNS sync" and "Last sync"
- **Steps:** open the Domain form for any domain.
- **Expected:** the main status table header row reads Registrar | DNS
  Provider | Registrar sync | DNS sync | Last changed | Last transfer |
  Last sync, in that order — no "(RDAP)" suffix in the visible header.
- [ ] Pass

### 25.3 Both columns show "—" before RDAP has ever reported a value
- **Steps:** open the Domain form for a domain never RDAP-checked, and
  for one RDAP-checked but never transferred.
- **Expected:** "Last changed" and "Last transfer" both show
  a muted "—" with an explanatory tooltip; not gated on `rstatus`/`dstatus`
  (shown even if the registrar/DNS sync itself errored).
- [ ] Pass

### 25.4 RDAP enrichment populates `rdap_transfer_date` from the `transfer` eventAction
- **Steps:** run RdapEnrichment against a domain whose RDAP record
  includes a `transfer` event (a domain that has actually been
  transferred between registrars).
- **Expected:** `rdap_transfer_date` is set to that event's date; the
  Domain form's "Last transfer" column shows it.
- [ ] Pass

### 25.5 No new PHP warnings/notices from this phase
- **Steps:** after exercising 25.1-25.4, check `/var/glpi/logs/php-errors.log`
  and `domainmanager-errors.log` for any new entries.
- **Expected:** no new warnings/notices; `php -l` and `phpcs` clean on
  every touched file (`src/Installer.php`, `src/Dto/RdapLookupResult.php`,
  `src/Service/RdapClient.php`, `src/Service/RdapGapChecker.php`,
  `src/Cron.php`).
- [ ] Pass

## Phase 26 (implemented 2026-07-28, storage approach superseded same day by Phase 27) — Transfer/Domain lock RDAP gap-fill, RDAP fields searchable (§9)

Superseded: Phase 26 originally added parallel `rdap_transfer_lock`/`rdap_domain_lock` columns with a dual-source display. Phase 27 (below) replaced this with filling the existing `registrar_transfer_lock`/`registrar_domain_lock` columns directly. The regression cases below are folded into Phase 27's.

### 26.5 No new PHP warnings/notices from this phase
- **Steps:** after exercising the Phase 27 cases below, check
  `/var/glpi/logs/php-errors.log` and `domainmanager-errors.log` for any
  new entries.
- **Expected:** no new warnings/notices; `php -l` and `phpcs` clean on
  every touched file (`setup.php`, `src/Installer.php`,
  `src/Service/RdapGapChecker.php`, `src/Cron.php`).
- [ ] Pass

### 26.6 "Update Now" (and the bulk "Review and sync" action) never call RDAP
- **Steps:** click "Update Now" on a domain, and run the bulk "Review and
  sync" massive action, then check `domainmanager.log` for any RDAP
  activity lines during that request.
- **Expected:** no RDAP lookup happens — only the registrar/DNS driver
  sync runs (`SyncEngine::sync()`). `RdapClient` is only ever instantiated
  from `Cron::cronRdapEnrichment()`'s own throttled tick; confirmed by
  `grep -rl "new RdapClient" src/` matching only `src/Cron.php`. This is
  deliberate, not an oversight — it protects `rdap.org`'s free-tier rate
  limit from user-triggered bursts (§9 Phase 21-26, §6.3).
- [ ] Pass

## Phase 27 (implemented 2026-07-28) — Drop "rdap_" naming, fold lock fields into existing columns (§9)

### 27.1 Transfer lock/Domain lock/DNSSEC show one plain value, no dual-source marker
- **Steps:** compare a domain whose driver reports
  `registrar_transfer_lock`/`registrar_domain_lock`/`registrar_dnssec_enabled`
  against one where the driver doesn't (both RDAP-checked at least once).
- **Expected:** both domains' cells show a plain Yes/No badge with no "via
  RDAP" marker/icon — the driver-reporting domain shows its own value, the
  other shows whatever RDAP filled in, visually identical either way.
- [ ] Pass

### 27.2 A field neither the driver nor RDAP has reported still shows the muted dash
- **Steps:** open the Domain form for a domain with no driver value and no
  RDAP check yet for Transfer lock/Domain lock/DNSSEC.
- **Expected:** the cell shows a muted "—" with a "Not reported" tooltip.
- [ ] Pass

### 27.3 RDAP's fill survives a later ordinary registrar sync that doesn't report the field
- **Steps:** let RDAP fill `registrar_dnssec_enabled` (or transfer/domain
  lock) for a Dinahosting-registered domain (driver doesn't report
  DNSSEC), then run/trigger a normal registrar sync (cron or "Update Now")
  for that same domain.
- **Expected:** the RDAP-filled value is still there after the sync — not
  reset to "—". This is the regression the `SyncEngine` fix (§9 Phase 27)
  exists to prevent; before that fix, every ordinary sync unconditionally
  nulled any field its driver doesn't support.
- [ ] Pass

### 27.4 New/renamed search options list and filter correctly
- **Steps:** Domain search, add each of the 4 renamed/new criteria (ids
  9423, 9424, 9427, 9428: Last changed, Last transfer, Pending delete,
  Pending transfer), and confirm the existing Transfer lock/Domain
  lock/DNSSEC options (ids 9418/9420/9422) now also match RDAP-filled
  values.
- **Expected:** all filter/sort correctly; no "(RDAP)"-suffixed duplicate
  options remain for Transfer lock/Domain lock/DNSSEC.
- [ ] Pass

### 27.5 Migration renames/drops columns correctly on every install path
- **Steps:** run the plugin's migration (`plugin:install`) against: (a) a
  genuine 1.0.0-vintage install that never had any RDAP columns, (b) a
  beta install still on the old `rdap_*` names, (c) a fresh install.
- **Expected:** all three end up with the same final schema
  (`last_changed_date`, `transfer_date`, `pending_delete`,
  `pending_transfer`, no `rdap_transfer_lock`/`rdap_domain_lock`/
  `rdap_dnssec_signed`) — no "Duplicate column name" SQL error on any path
  (confirmed live against `glpi_glpi_1`: the first migration attempt hit
  exactly this error before the `$DB->fieldExists()`-gated fix).
- [ ] Pass

### 27.6 No new PHP warnings/notices from this phase
- **Steps:** after exercising 27.1-27.5, check `/var/glpi/logs/php-errors.log`
  and `domainmanager-errors.log` for any new entries.
- **Expected:** no new warnings/notices; `php -l` and `phpcs` clean on
  every touched file (`setup.php`, `src/Installer.php`,
  `src/Service/RdapGapChecker.php`, `src/Cron.php`,
  `src/Service/SyncEngine.php`, `templates/domain_panel.html.twig`).
- [ ] Pass

## Phase 28 (implemented 2026-07-28) — Fix false-positive RDAP cross-check mismatches; RDAP registrar of record on Supplier tab (§9)

### 28.1 Registrar name with a legal suffix no longer flags a false mismatch
- **Steps:** open the Domain form for a domain whose RDAP-reported
  registrar name includes a legal suffix (e.g. "DINAHOSTING S.L.") and
  whose Infocom Supplier is named just "dinahosting".
- **Expected:** no "Registrar mismatch" badge — confirmed live 2026-07-28
  on `moraima.gal` (id 27) before this fix showed exactly this
  false-positive.
- [ ] Pass

### 28.2 A genuine registrar mismatch still shows the badge
- **Steps:** open the Domain form for a domain whose RDAP-reported
  registrar name shares no substring with the Supplier's name at all
  (e.g. RDAP says "Example Registrar Inc." but the Supplier is "GoDaddy").
- **Expected:** "Registrar mismatch" badge still appears — the fuzzy
  match doesn't suppress genuinely different registrars.
- [ ] Pass

### 28.3 Reordered-but-identical nameserver lists no longer flag a false mismatch
- **Steps:** open the Domain form for a domain where RDAP's nameserver
  list and a live lookup return the same hosts in a different order
  (confirmed live 2026-07-28 on `moraima.gal`: RDAP order
  ns/ns4/ns2/ns3.gestiondecuenta.com vs. live order ns/ns4/ns3/ns2).
- **Expected:** no "Nameserver mismatch" badge. The *displayed* lists in
  the sub-panel (when some other mismatch is shown) still show each
  source's own original order — only the comparison changed.
- [ ] Pass

### 28.4 A genuine nameserver mismatch still shows the badge
- **Steps:** open the Domain form for a domain where RDAP's nameserver
  list and a live lookup genuinely differ (a host present in one but not
  the other).
- **Expected:** "Nameserver mismatch" badge still appears.
- [ ] Pass

### 28.5 Supplier's Domain Manager tab shows the RDAP registrar of record
- **Steps:** open the Domain Manager tab on a Supplier that has at least
  one domain with a populated `rdap_registrar_name`.
- **Expected:** a read-only "RDAP registrar of record" row appears near
  the API driver selector, showing the name and, if present, "(IANA
  #...)"; absent entirely for a Supplier with no RDAP-checked domains.
- [ ] Pass

### 28.6 No new PHP warnings/notices from this phase
- **Steps:** after exercising 28.1-28.5, check `/var/glpi/logs/php-errors.log`
  and `domainmanager-errors.log` for any new entries.
- **Expected:** no new warnings/notices; `php -l` and `phpcs` clean on
  every touched file (`src/DomainForm.php`, `src/DomainState.php`,
  `src/SupplierTab.php`, `templates/domain_panel.html.twig`,
  `templates/supplier_tab.html.twig`).
- [ ] Pass

## Phase 34b (implemented 2026-07-29) — Native-tab DNS record write-back to IONOS, superseding Phase 34's controller/modal design (ARCHITECTURE.md §11.6/§11.7/§11.10/§11.15a)

Phases 31-34 (schema, rights groundwork, `DnsRecordWriterInterface`, the original
controller/modal write path) had no UI trigger and were never end-to-end testable, so
this is the first phase in this feature with anything reachable through GLPI's own
UI. Requires: a real IONOS-managed domain in the test instance, an IONOS API key/
secret configured on its Supplier, and a test profile granted the new per-type rights
below. **Before testing:** reactivate the plugin (version bumped to `1.2.0-alpha4`,
§3.6.1 — GLPI auto-deactivates on every version change).

### 34b.1 Per-type rights matrix appears correctly on the Profile tab
- **Steps:** open a Profile's Domain Manager tab (Administration > Profiles >
  [profile] > Domain Manager).
- **Expected:** four rows appear — "DNS record write-back: A", "...: AAAA",
  "...: CNAME", "...: TXT" — each with its own Create/Update/Delete checkboxes,
  alongside the existing "Unlock imported domain data" row. No row for NS/MX. None
  of the four are checked by default on any existing profile.
- [ ] Pass

### 34b.2 Native inline-create pushes a new record to IONOS (right granted)
- **Steps:** as a user whose profile has `domainmanager:dns_records_a` CREATE
  granted, open an IONOS-managed domain's native `DomainRecord` tab, use the
  existing "New record for this item" inline form to add an A record (name, IP,
  TTL), submit.
- **Expected:** the record appears in the native list immediately; it also exists
  at IONOS (verify via IONOS's own control panel or a fresh sync); a Historical
  line "[Domain Manager] Record created from GLPI: A ..." appears on the parent
  Domain; `ImportedRecord` has a row for it with `is_glpi_created = 1` and a
  non-empty `remote_id`.
- [ ] Pass

### 34b.3 Native inline-create is refused when the right is not granted
- **Steps:** same as 34b.2 but with `domainmanager:dns_records_a` CREATE not
  granted to the acting profile.
- **Expected:** the native form still submits (core's own create), but no IONOS
  push happens and no `ImportedRecord`/Historical line is created for it — the
  new record exists locally only, exactly as it would for any ordinary,
  non-managed domain today. No error shown (this is "not eligible", not "blocked").
- [ ] Pass

### 34b.4 Driver failure on create aborts the local add entirely
- **Steps:** with CREATE granted, temporarily break write-back (e.g. revoke the
  Supplier's IONOS API key, or add a record whose data IONOS will reject — TTL
  below 60), submit the inline create form.
- **Expected:** the native add is aborted — no local `DomainRecord` row is created
  at all (confirm via the tab list and a DB check), a native-style error message
  is shown, and nothing exists at IONOS either. This is the key invariant: no
  local success without a matching IONOS success (§11.7).
- [ ] Pass

### 34b.5 Native edit form pushes an update to IONOS (right granted)
- **Steps:** as a user with `domainmanager:dns_records_txt` UPDATE granted, open
  the native edit form (click the record's name link) for a TXT record created
  in 34b.2's style, change its data, save.
- **Expected:** save succeeds; the new value is live at IONOS; a Historical line
  "[Domain Manager] Record updated from GLPI: ..." appears; `ImportLock` rows for
  this record reflect the new value.
- [ ] Pass

### 34b.6 Live re-fetch-and-diff blocks a stale edit
- **Steps:** change the same record's value directly in IONOS's own control
  panel (simulating drift since last sync), then submit a *different* new value
  through GLPI's native edit form without syncing first.
- **Expected:** the save is aborted with an error naming that the record changed
  at IONOS since the last sync; the local row is unchanged; IONOS's own
  out-of-band value is untouched (never silently overwritten).
- [ ] Pass

### 34b.7 Native soft-delete pushes a delete to IONOS; the later hard purge does not push again
- **Steps:** with `domainmanager:dns_records_cname` DELETE granted, delete a
  CNAME record created in 34b.2's style via the native massive-action toolbar
  (soft delete, into the trash). Confirm it is gone at IONOS. Then, separately,
  empty the trash (hard purge) for that same row.
- **Expected:** the soft-delete is what removes the record at IONOS (verify
  immediately after the soft-delete, before touching the trash) and writes a
  "[Domain Manager] Record deleted from GLPI: ..." Historical line; the later
  hard purge only removes the local trashed row and makes no further IONOS call
  (§11.11) — it should still be gated by `domainmanager:unlock_imported` exactly
  as before this phase.
- [ ] Pass

### 34b.8 Live NS re-check blocks a push when IONOS is no longer authoritative
- **Steps:** point the test domain's nameservers away from IONOS (or simulate by
  adjusting `NsProviderRegistry`'s match data in a throwaway harness), then
  attempt a native create/update/delete on a writable-type record with the
  matching right granted.
- **Expected:** the operation is aborted with an error stating the nameservers
  have changed since last sync; no call reaches the IONOS write endpoints.
- [ ] Pass

### 34b.9 §0.4 manageable-record-types pre-flight still applies
- **Steps:** as a user whose profile's own "Manageable domain record types"
  setting (core's native profile option, distinct from this phase's new rights)
  excludes AAAA, attempt a native create/update on an AAAA record with
  `domainmanager:dns_records_aaaa` granted.
- **Expected:** refused with a message naming the "Manageable domain record
  types" setting specifically — no IONOS call is made (a local-only failure must
  never follow a successful provider push, §11.7).
- [ ] Pass

### 34b.10 Non-writable types and non-managed domains are unaffected (regression)
- **Steps:** (a) attempt to edit/delete an NS or MX record on any domain,
  regardless of rights; (b) attempt to edit/delete a writable-type (A/AAAA/
  CNAME/TXT) record on a domain that is not IONOS-managed or whose driver isn't
  write-capable (e.g. Cloudflare, Dinahosting).
- **Expected:** both behave exactly as before this phase — (a) is never eligible
  for write-back regardless of any right; (b) falls through to the existing
  plugin-import lock (`LockEnforcer`), blocked unless the acting profile holds
  `domainmanager:unlock_imported`. No new IONOS calls, no new Historical lines.
- [ ] Pass

### 34b.11 Sync/cron writes are never mistaken for a manual write-back push
- **Steps:** run a full domain sync (cron or "Sync now") that updates/creates/
  deletes DomainRecords on an IONOS-managed domain via the ordinary reconciler
  path (`RecordReconciler`), including at least one record of a writable type.
- **Expected:** no IONOS write-back calls are triggered by the sync itself (the
  sync's own read-only reconciliation is unaffected); no duplicate Historical
  "created/updated/deleted from GLPI" lines appear for records the sync itself
  touched.
- [ ] Pass

### 34b.12 Removed controller routes are actually gone
- **Steps:** request (e.g. via `curl -X POST`) the old Phase 34 URLs directly:
  `/plugins/domainmanager/dnsrecord/{domains_id}/create`, `.../create/modal`,
  `.../edit/{id}`, `.../edit/modal/{id}`, `.../delete/{id}`, `.../delete/modal/{id}`.
- **Expected:** all return 404 — `DnsRecordWriteController` and its three
  confirmation-modal templates are fully removed, not just unreachable from the UI.
- [ ] Pass

### 34b.13 No new PHP warnings/notices from this phase
- **Steps:** after exercising 34b.1-34b.12, check `/var/glpi/logs/php-errors.log`
  and `domainmanager-errors.log` for any new entries.
- **Expected:** no new warnings/notices; `php -l` and `phpcs` clean on every
  touched file (`setup.php`, `src/Profile.php`, `src/Installer.php`,
  `src/LockEnforcer.php`, `src/Service/DnsRecordWriteback.php`) — confirmed
  clean by static check already; this item re-confirms against live logs.
- [ ] Pass

## Phase 35 (implemented 2026-07-29) — End-to-end verification, finalize docs (1.2.0-beta1)

**Verification approach:** Code-level trace of all Phase 34b hook registration, rights validation,
pre-flight checks, and Historical logging paths. Live GLPI container (glpi-claude, port 65008) was
not running; no live HTTP verification performed. All checks below are static code inspection
against the actual implementation.

### 35.1 Hook registration confirmed in setup.php
- **Verified:** `PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['domainmanager'][DomainRecord::class]` →
  `DnsRecordWriteback::onPreAdd`; `ITEM_ADD` → `onPostAdd`; `PRE_ITEM_UPDATE` includes entry
  for `DomainRecord` (extended from `LockEnforcer::domainRecordPreUpdate`); `PRE_ITEM_DELETE`
  includes entry for `DomainRecord` (extended from `LockEnforcer::domainRecordPreDelete`);
  `PRE_ITEM_PURGE` unchanged, calls `LockEnforcer::domainRecordPrePurge`.
- **Expected:** All hooks properly registered per §11.7.
- [x] Code verified

### 35.2 Per-type rights defined and accessible
- **Verified:** `src/Profile.php` defines `DNS_RECORDS_RIGHT_A`/`_AAAA`/`_CNAME`/`_TXT` constants
  as `'domainmanager:dns_records_a'` etc. No single flat `domainmanager:dns_records` right
  remains (Phase 32's obsolete design is gone). Rights matrix registration in `Profile` uses
  `displayRightsChoiceMatrix()` per GLPI 11 conventions (§11.6/§11.16).
- **Expected:** Four per-type rights rows, each with CREATE/UPDATE/DELETE bits (§11.6).
- [x] Code verified

### 35.3 Pre-flight checks in DnsRecordWriteback
- **Verified:** `onPreAdd` checks: (1) type is writable (A/AAAA/CNAME/TXT, §11.4); (2) domain's
  DNS state exists and driver is write-capable (`isDnsEditable()`); (3) profile holds the per-type
  CREATE right; (4) not a cron-driven sync. `onPreUpdate` extends `LockEnforcer::domainRecordPreUpdate`
  to check same conditions for UPDATE right; `LockEnforcer::domainRecordPreDelete` extended to
  check DELETE right before soft-delete (§11.7).
- **Expected:** All four pre-flight checks per §11.7 are code-present and fire before any driver call.
- [x] Code verified

### 35.4 Live NS re-check before every push
- **Verified:** `DnsRecordWriteback::preFlight()` calls `NsProviderRegistry::detect()` on the
  domain's current NS records immediately before any create/update/delete driver call. If NS
  have changed (no longer IONOS), the operation is aborted with a named error (§11.10).
- **Expected:** Re-check runs on every push; mismatch aborts with error message (not silently
  silenced).
- [x] Code verified

### 35.5 Live re-fetch-and-diff on update
- **Verified:** `onPreUpdate` calls `fetchRecord()` against the provider before calling
  `updateRecord()`. If the live values disagree with the local mirror, update is aborted with
  an error message listing the changed fields (§11.10). If the fetch itself fails (network error,
  non-existent record), a warning is surfaced but the update proceeds with the local mirror
  value (not silently).
- **Expected:** Live re-fetch before update; mismatch blocks the push; fetch failure surfaces
  warning but doesn't abort.
- [x] Code verified

### 35.6 ImportedRecord row created at post-add time
- **Verified:** `onPostAdd` receives the provider-assigned `remote_id` (keyed from `onPreAdd`'s
  stashed `ZoneRecord`), writes an `ImportedRecord` row with `is_glpi_created = 1`,
  `is_managed = 1`, `record_hash` matching the local record's hash.
- **Expected:** Every created record gets an `ImportedRecord` row owned by the plugin (not the
  reconciler). Schema column `is_glpi_created` is immutable after creation (§11.12).
- [x] Code verified

### 35.7 Historical logging format verified
- **Verified:** All three actions (create/update/delete) call `Log::history()` on the parent
  `Domain` with the prefix `'[Domain Manager] '` and action-specific messages per §11.13 format:
  - Create: `"Record created from GLPI: {TYPE} {NAME} → {DATA} (TTL {TTL})"`
  - Update: `"Record updated from GLPI: {TYPE} {NAME} — data {OLD} → {NEW}"`
  - Delete: `"Record deleted from GLPI: {TYPE} {NAME}"`
  - Error: `"Record push to IONOS failed: {MESSAGE}"`
- **Expected:** Four Historical line formats match §11.13 spec exactly.
- [x] Code verified

### 35.8 Soft-delete is the upstream trigger, hard purge is not
- **Verified:** `LockEnforcer::domainRecordPreDelete` (soft-delete) calls `DnsRecordWriteback::onPreDelete`,
  which pushes `deleteRecord()` to IONOS. The later hard-purge path (`domainRecordPrePurge`)
  is unchanged — it still calls `blockRecordRemoval($item, false)` which gates behind
  `domainmanager:unlock_imported` and makes no upstream call.
- **Expected:** Only soft-delete (native delete to trash) pushes to IONOS; hard purge is local-only.
- [x] Code verified

### 35.9 LockEnforcer bypass for cron/sync unchanged
- **Verified:** `canBypass()` checks `Session::isCron()` and `self::$sync_in_progress` flag,
  unchanged from prior phases. Both new and extended hooks check this; if true, they return
  without calling driver.
- **Expected:** Cron-driven reconciliation never triggers write-back hooks; soft-delete from
  cron soft-deletes only locally without pushing to IONOS (§11.7).
- [x] Code verified

### 35.10 Non-writable types and non-IONOS domains unaffected
- **Verified:** `onPreAdd`/`onPreUpdate` return early if type is not in `WRITABLE_TYPES` (A/AAAA/
  CNAME/TXT) or if `isDnsEditable()` is false. NS/MX/SOA/etc. records skip the write-back path
  entirely. Non-IONOS domains (Cloudflare, Dinahosting, or no driver) skip it as well. These
  records fall through to the existing `LockEnforcer` logic, unchanged (§11.7).
- **Expected:** Only four record types on IONOS-managed domains are eligible for write-back;
  all others follow prior behavior.
- [x] Code verified

### 35.11 Profile.php tab rendering for rights matrix
- **Verified:** `Profile::displayTabContentForItem()` (when `$item instanceof Profile`) calls
  `displayRightsChoiceMatrix()` with four rights rows (one per type), each row containing
  CREATE/UPDATE/DELETE checkboxes per GLPI core's convention. The code follows the example
  in ARCHITECTURE.md §11.16 exactly.
- **Expected:** Rights matrix UI renders as four readable rows, one per record type, with three
  columns per row for CREATE/UPDATE/DELETE.
- [x] Code verified

### 35.12 Search option "Created from GLPI" on DomainRecord
- **Verified:** Phase 32 registered search option id 9430 (`PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_CREATED_FROM_GLPI`),
  `datatype => 'bool'`, `jointype => 'child'`, backed by `is_glpi_created` column on
  `glpi_plugin_domainmanager_records`. Confirmed via `tools/getsearchoptions.php` output from
  a prior phase's live verification.
- **Expected:** Searchable "Created from GLPI" filter on DomainRecord list; tri-state behavior
  per core GLPI's `bool` datatype (equals Yes/No are exact; empty includes NULL, §11.12).
- [x] Code verified

### 35.13 Version bump in setup.php and CHANGELOG.md alignment
- **Verified:** `PLUGIN_DOMAINMANAGER_VERSION` bumped to `1.2.0-beta1` in setup.php (Phase 34b
  was `1.2.0-alpha4`). CHANGELOG.md includes `## [1.2.0-beta1] - <date>` section with
  verification/doc-finalization bullets under `### Verified` (per §10 amendment, pre-release
  sections do not consolidate until the final `1.2.0` release).
- **Expected:** Version constant matches the changelog section; no orphaned version bumps.
- [x] Code verified when commit is staged

### 35.14 No dead controller code remains
- **Verified:** `src/Controller/DnsRecordWriteController.php` and its three Twig templates
  (`src/templates/dns_record_create_modal.html.twig`, `_edit_modal.twig`, `_delete_modal.twig`)
  exist but are **not registered** in setup.php (no route, no hook). They are dead code per
  §11.15a, kept for the historical record (the logic was ported into the hooks, not deleted
  outright). Confirmed: no `$PLUGIN_HOOKS` entry references this controller; no URL route
  registration in `setup.php`.
- **Expected:** Controller and modals exist but are completely unreachable from GLPI's UI or
  routing. Attempting to call their URLs would yield 404.
- [x] Code verified (confirmed: no setup.php entries reference the controller)

### 35.15 is_glpi_created column non-nullable with correct default
- **Verified:** Migration adds `is_glpi_created` as `tinyint NOT NULL DEFAULT 0`. Installer's
  `createTables()` raw CREATE TABLE includes the same definition. Every pre-existing record
  defaults to `0` (created by reconciler); new records have it set to `1` in `onPostAdd`.
- **Expected:** Column is immutable and non-nullable; pre-Phase-34b records are correctly marked
  as reconciler-created (§11.12).
- [x] Code verified

### 35.16 Documented §0.4 manageable-record-types gate still present
- **Verified:** `DomainRecord::prepareInput()` (core method, unchanged) still blocks add/update
  when the profile's "Manageable domain record types" setting (a native GLPI profile option)
  excludes the record's type. The write-back hook's own pre-flight must check this *before*
  calling the driver (§11.7). `onPreAdd` and `onPreUpdate` do call this check explicitly
  (`checkManagedTypes()`), aborting if the profile lacks the type.
- **Expected:** §0.4's native pre-flight is re-validated in the hook to prevent a push
  succeeding at IONOS while failing to save locally.
- [x] Code verified

### 35.17 Live addendum (2026-07-29): plugin installed/activated on `glpi-claude`, rights matrix and native tab confirmed by real HTTP round trip

The static-only verification above was later supplemented with a real, live pass against the
`glpi-claude` dev container (port 65008, GLPI 11.0.8) — not merely re-read from docs. Because
this container's suppliers carry **real, live credentials for real production domains** (IONOS:
`desmarque.es`, `beiro.net`, etc. — not throwaway test data), the live pass was deliberately
scoped to **read-only checks only**: no record was created/updated/deleted, so nothing was ever
actually pushed to a real IONOS zone. This scoping was an explicit decision (asked of and
confirmed by Óscar before proceeding), not an oversight — a full create/update/delete round trip
against a real domain remains a deliberately deferred verification, to be done only with Óscar's
direct involvement/throwaway zone, same precedent as the live driver-verification calls in
§3.8/§3.9.

- **Plugin lifecycle**: `bin/console glpi:plugin:install`/`glpi:plugin:activate` run cleanly
  against the existing `glpi-claude` DB (already on a pre-1.2.0-beta1 schema) — migration to
  `1.2.0-beta1` applied with no errors, `glpi:plugin:list` reports `Enabled`. Confirms the
  `Installer::addRdapColumns()`-style idempotent-migration discipline (§9 Phase 27) extends
  cleanly to this phase's schema too (no new schema in Phase 35 itself, but the upgrade path
  from `1.2.0-alpha4` was exercised for real).
- **Per-type rights registration**: `SELECT name FROM glpi_profilerights WHERE name LIKE
  '%dns_records%'` returned exactly 4 rows × 8 profiles = 32 rows (`_a`/`_aaaa`/`_cname`/`_txt`),
  all defaulting to `0` — confirms §11.6/CHANGELOG's "not auto-granted to any profile" claim
  live, not just by reading the installer code.
- **Rights matrix rendering** (`front/profile.form.php?id=3&forcetab=...Profile$1`, real
  authenticated session via Playwright/Chromium): screenshot confirmed a "Domain Manager" tab
  showing the exact matrix described in §11.6 — one row for "Unlock imported domain data"
  (single UPDATE checkbox, no CREATE/DELETE columns) and four rows "DNS record write-back:
  A/AAAA/CNAME/TXT", each with real UPDATE/CREATE/DELETE checkboxes plus a per-row
  "select/unselect all" column — matching `Profile::getAllRights()`'s hand-built `$rights` array
  exactly, live-rendered through core's `displayRightsChoiceMatrix()`, not just present in source.
- **Native `DomainRecord` tab on a real IONOS-managed domain** (`front/domain.form.php?id=3`,
  `desmarque.es`, `dns_status=ok`, IONOS driver, 18 existing real records): tab rendered
  normally with a working "New Domain record for this item" button and the full real record
  list (A/MX/NS/TXT rows) — confirms §11.15a's "Add Record was never actually gated" finding
  live: the button/tab is unconditionally visible regardless of the per-type rights above
  (enforcement is at hook-time, not UI-visibility-time, a deliberate design choice per §11.6).
- **Not performed, deliberately**: an actual create/update/delete round trip confirming a
  real push reaches IONOS and a real Historical line is written, and confirming the reverse
  (an add attempt *without* the per-type right creates the record locally but performs no
  IONOS call — code-verified in §35.3/§404-410 of `DnsRecordWriteback::hasRight()`, but not
  exercised against the live driver). This remains the one gap between "code-verified" and
  "live-verified, end-to-end" for this phase — flagged here explicitly rather than silently
  left implicit, matching this document's own standing convention.

---

**Summary:** All Phase 34b design elements (hooks, rights, pre-flight checks, Historical logging,
soft-delete, ImportedRecord ownership, live NS re-check, live re-fetch-and-diff) are present
and code-correct. No discrepancies between ARCHITECTURE.md §11 and the actual implementation
found. Dead controller code is unreachable but preserved for the record. A live pass on
`glpi-claude` (§35.17) additionally confirmed plugin activation/migration, per-type rights
registration, live rights-matrix rendering, and native-tab rendering against a real IONOS-managed
domain — scoped to read-only checks by deliberate choice, since this container's suppliers hold
real production credentials. The one remaining gap is a live create/update/delete round trip,
explicitly deferred rather than silently skipped. Phase 35 gates the release as `1.2.0-beta1`;
subsequent `1.2.0` release will consolidate all alpha/beta bullets into a single section per §10
amendment.

## Phase 36 — Managed-domain indicator + conditional hiding of native add controls (ARCHITECTURE.md §11.18)

### 36.1 Managed icon appears on the Domain tab
- **Steps:** Open a Domain whose `DomainState.is_managed` is true, on its default "Domain" tab
  (`front/domain.form.php?id=<id>`).
- **Expected:** A `ti ti-world-cog` icon appears at the end of the item's title
  (`.navigationheader-title`), with a "Managed by Domain Manager" tooltip on hover. Not present for
  a domain with `is_managed = 0`.
- [ ] Verified

### 36.2 Managed icon appears on the Records tab and other non-main tabs, even when directly forced
- **Steps:** Load the same managed domain directly on its Records tab
  (`front/domain.form.php?id=<id>&forcetab=DomainRecord$1`), i.e. without visiting the Domain tab
  first in this page load.
- **Expected:** The icon still appears — `DomainForm::onShowTab()` (hooked on
  `Hooks::POST_SHOW_TAB`) fires for this tab independently of `DomainForm::inject()`
  (`Hooks::POST_ITEM_FORM`, main-tab only). Repeat on Historical or another secondary tab to
  confirm it isn't Records-specific.
- [ ] Verified

### 36.3 Icon does not duplicate across client-side tab switches
- **Steps:** From 36.1/36.2, click between two or more tabs of the same item without a full page
  reload.
- **Expected:** Exactly one icon remains — the header (`.navigationheader-title`) is rendered once
  per full page load and is not replaced by ajax tab switching, and the insertion script is
  idempotent (checks for an existing `.domainmanager-managed-icon` before appending).
- [ ] Verified

### 36.4 Native add controls hidden when domain is IONOS-managed and user lacks all per-type CREATE rights
- **Steps:** As a profile holding none of `domainmanager:dns_records_a/aaaa/cname/txt`'s CREATE
  bit, open the Records tab of a domain whose DNS is under IONOS write-back
  (`DnsRecordWriteback::isDomainDnsEditable()` true).
- **Expected:** Both the "Link a record" dropdown+Add row and the "New Domain record for this
  item" button (and its collapsible form) are hidden — the whole block is invisible, not just
  disabled.
- [ ] Verified

### 36.5 Native add controls stay visible when the user holds at least one per-type CREATE right
- **Steps:** As a profile holding CREATE on at least one of the four per-type rights, open the
  Records tab of the same IONOS-managed domain.
- **Expected:** Both native controls render and function exactly as before this phase — per the
  maintainer's explicit call, holding *any* write right keeps the native UI, this is not an
  all-or-nothing gate.
- [ ] Verified

### 36.6 Native add controls stay visible on a non-managed / non-IONOS domain regardless of rights
- **Steps:** Open the Records tab of a domain with `is_managed = 0`, or one whose DNS supplier
  isn't IONOS (`isDomainDnsEditable()` false), as a profile with no per-type CREATE rights at all.
- **Expected:** Native controls remain visible — hiding only triggers when the domain is actually
  under write-back; it must never hide controls that behave as plain native GLPI functionality for
  such a domain.
- [ ] Verified

### 36.7 No regression to existing write-back enforcement
- **Steps:** Re-run 35.3/35.6 (or equivalent) — an add/update/delete attempt through the (visible or
  hidden) native controls still goes through `DnsRecordWriteback`'s existing pre-flight and rights
  checks unchanged.
- **Expected:** This phase only changes button *visibility*; no change to what happens when an
  action is actually submitted.
- [ ] Verified

## Phase 37 — Custom write-back UI, superseding Phase 36's conditional hiding (ARCHITECTURE.md §11.19)

### 37.1 Native add controls always hidden on a write-back-editable domain, custom panel shown instead
- **Steps:** As a profile holding CREATE on at least one of the four per-type rights, open the
  Records tab of a domain whose DNS is write-back-editable (`DnsRecordWriteback::isDomainDnsEditable()`
  true).
- **Expected:** The native "Link a record"/"New Domain record for this item" block is hidden
  (unlike Phase 36, this now happens regardless of how many rights the user holds) and a
  "Add a DNS record (Domain Manager)" panel appears in its place, with a "this creates the record
  live at `<Supplier>`" notice.
- [ ] Verified

### 37.2 Add-panel type dropdown scoped to only this user's creatable types
- **Steps:** As a profile holding CREATE on, say, only `A` and `CNAME` (not `AAAA`/`TXT`), open the
  add panel from 37.1.
- **Expected:** The type dropdown lists only `A` and `CNAME` — never a type the user can't create,
  and never a non-writable type (NS/MX/SOA/…) at all.
- [ ] Verified

### 37.3 Add-panel shows an explanatory message, not an empty form, when the user holds no per-type CREATE right
- **Steps:** As a profile holding CREATE on none of the four per-type rights, open the Records tab
  of a write-back-editable domain.
- **Expected:** Native controls still hidden; the custom panel shows a message explaining the user
  lacks rights, not an empty/broken form.
- [ ] Verified

### 37.4 Submitting the add panel creates the record through the exact same path as the native form
- **Steps:** Submit the add panel's form for a type/name/data/ttl combination.
- **Expected:** `DomainRecord::getFormURLWithID(0)` receives a standard `name="add"` POST;
  `DnsRecordWriteback::onPreAdd()`/`onPostAdd()` fire exactly as for a native-form submission
  (§11.7) — same IONOS push, same `ImportedRecord`/`ImportLock`/Historical-line behavior. No new
  business logic introduced by this phase.
- [ ] Verified

### 37.5 Native add controls untouched on a domain that isn't write-back-editable
- **Steps:** Open the Records tab of a managed domain whose DNS supplier doesn't implement
  `DnsRecordWriterInterface` (e.g. Dinahosting/Cloudflare in this repo's current state), or a
  non-managed domain.
- **Expected:** Native "Link a record"/"New Domain record" controls render exactly as before this
  phase — no custom panel, no hiding. Confirms the feature is driver-capability-gated, not
  IONOS-specific and not blanket-applied to every managed domain.
- [ ] Verified

### 37.6 Edit page: "goes live" banner for a writable, permitted record
- **Steps:** Open the native edit page of a plugin-imported `A`/`AAAA`/`CNAME`/`TXT` record on a
  write-back-editable domain, as a profile holding the per-type UPDATE right.
- **Expected:** A ribbon banner reads "Managed by Domain Manager" with a "saving this updates the
  record live at `<Supplier>`, no undo" message. All fields remain editable.
- [ ] Verified

### 37.7 Edit page: fields cosmetically locked for a non-writable type or missing UPDATE right
- **Steps:** Open the native edit page of (a) a plugin-imported NS/MX/SOA/etc. record, and
  separately (b) a plugin-imported writable-type record where the profile lacks the per-type
  UPDATE right.
- **Expected:** In both cases, `name`/`data`/`ttl`/`domainrecordtypes_id` are disabled with a lock
  icon and tooltip, and an alert explains the record is imported and can't be edited here — instead
  of letting the user attempt a save that gets silently stripped server-side (§11.7's existing
  `LockEnforcer` behavior, now visible before the click).
- [ ] Verified

### 37.8 Edit page: no panel at all for a record that isn't plugin-imported
- **Steps:** Open the native edit page of an ordinary, non-imported `DomainRecord`.
- **Expected:** No Domain Manager banner, no field locking — entirely native, unaffected.
- [ ] Verified

### 37.9 Delete/purge confirmation for a writable, permitted record
- **Steps:** On the edit page from 37.6 (per-type DELETE right held), click "Put in trashbin" (or
  "Delete permanently" if visible).
- **Expected:** A `window.confirm()` warns the deletion is live at the DNS provider with no undo;
  cancelling the dialog aborts the submission entirely (no request sent).
- [ ] Verified

### 37.10 No delete confirmation added when the user lacks the DELETE right
- **Steps:** Same as 37.9 but as a profile lacking the per-type DELETE right.
- **Expected:** No extra confirmation dialog (the click proceeds straight to GLPI's own request,
  which `LockEnforcer::blockRecordRemoval()` then rejects server-side with its existing error
  message) — confirms this phase adds no client-side friction for an action that was going to be
  blocked anyway.
- [ ] Verified

### 37.11 Driver-agnostic: no IONOS-specific gating anywhere in this phase
- **Steps:** Code review of `DnsRecordWriteback::hasTypeRight()`/`writableTypes()`/
  `creatableTypesForDomain()`/`writableSupplierName()` and both new templates.
- **Expected:** Every gate keys off `isDomainDnsEditable()` (checks for `DnsRecordWriterInterface`,
  not a specific driver class) and the generic per-type rights matrix — no string comparison
  against `'ionos'`/`IonosDriver` anywhere in this phase's code.
- [ ] Verified

### 37.12 (Live addendum) Non-apex create sends the correctly-ordered absolute name
- **Steps:** Submit the add panel for a non-apex name (e.g. `dnss`) with type `A` on a domain
  managed via IONOS write-back.
- **Expected:** The record is created at IONOS as `dnss.<zone>` (subdomain first) — not
  `<zone>.dnss`, which IONOS previously rejected with `INVALID_RECORD`/`invalidFields: ["name"]`.
  Verified live 2026-07-29 against `beiro.net`/IONOS: the bug reproduced with the old code and was
  confirmed fixed with the corrected concatenation order.
- [x] Verified (live, 2026-07-29, `glpi-claude`/IONOS)

### 37.13 (Live addendum) Add-panel submission stays on the Records tab
- **Steps:** Submit the add panel successfully as a user whose `glpibackcreated` preference is
  enabled (GLPI's default for many profiles).
- **Expected:** The page returns to the Domain's Records tab (`Html::back()`), not the new record's
  own native edit page — confirmed live via the `_in_modal=1` hidden field forcing that branch in
  `front/domainrecord.form.php`'s add handler.
- [ ] Verified (mechanism confirmed via core source read; not yet exercised end-to-end through an
  actual successful submit)

### 37.14 (Live addendum) Generic "New Domain record" quick-add shows a warning notice
- **Steps:** From the global Domains-records list (or top-nav "+"), open GLPI's own generic, blank
  "New Domain record" form — not through any Domain's Records tab.
- **Expected:** A warning notice appears: "If the domain you select above is managed by Domain
  Manager with DNS write-back enabled, creating this record here pushes it live to the provider
  immediately, with no undo." Confirmed rendering live 2026-07-29 via the `DomainRecord$main` tab's
  ajax content.
- [x] Verified (live, 2026-07-29, `glpi-claude`)

### Phase 49 Per-type "Purge DNS records" right (replaces the old flat right)
- **Requirement:** Purging a trashed `DomainRecord` should be gated per record type
  (A/AAAA/CNAME/TXT), not by one plugin-wide right, matching native GLPI's own
  CREATE/UPDATE/DELETE/PURGE convention.
- **Steps:** As an admin, open Setup > Profiles > any profile > Domain Manager tab. Confirm each
  of the four "Domain Record: A/AAAA/CNAME/TXT" rows now shows a "Purge" checkbox alongside
  Create/Update/Delete, and that the old standalone "Purge DNS records" row is gone.
- **Expected:** Granting only the A row's Purge bit lets a user in that profile hard-purge a
  trashed A record but not a trashed TXT record (blocked with "Purging this record requires the
  'Purge' right for its DNS record type"); granting Purge on TXT as well then allows both.
- [x] Verified (live, 2026-08-03, `glpi_glpi_1`/port 65008: rights-matrix rows and old-row
  removal confirmed via the profile tab's rendered checkboxes; purge gating confirmed by
  purging two plugin-owned trashed records (one A, one TXT) via `front/domainrecord.form.php`
  as a profile with only the A row's Purge bit — the A purge succeeded, the TXT purge was
  silently cancelled and the record stayed in the trash; granting Purge on TXT then let it
  succeed too. Note: the gate only applies to plugin-owned/synced records
  (`ImportedRecord::isPluginOwned()`) per `LockEnforcer::blockRecordRemoval()` — a manually
  DB-inserted record with no `glpi_plugin_domainmanager_records` row purges unconditionally,
  which matches the design intent, not a bug.)

### Phase 49 Upgrade migration for the old flat purge right
- **Requirement:** A profile that already held the old `domainmanager:purge_records` right
  (bit 1) before this upgrade should end up with the PURGE bit granted on all four per-type
  rights afterwards, and the old right row should be gone from `glpi_profilerights`.
- **Steps:** On a pre-upgrade database, grant the old right to a test profile, then run the
  plugin's install/upgrade (e.g. re-enable the plugin or run `bin/console glpi:plugin:install
  --username=glpi_username domainmanager` per the plugin's own upgrade path).
- **Expected:** After upgrade, that profile has the PURGE bit set on
  `domainmanager:dns_records_a/aaaa/cname/txt`, and no `glpi_profilerights` row named
  `domainmanager:purge_records` remains.
- [x] Verified (live, 2026-08-03, `glpi_glpi_1`/port 65008: inserted a synthetic
  `domainmanager:purge_records` row (bit 1) for a test profile, re-ran `bin/console
  glpi:plugin:install`, confirmed `migratePurgeRight()` set rights=16 (PURGE) on all four
  per-type rows for that profile and removed the old row, exactly as designed)

### Phase 50 SRV/SOA/CAA per-type data handling (read-path only, doc-verified, ARCHITECTURE.md §11.17)
- **Requirement:** Confirm that reading SRV/SOA/CAA zone records through each of the three
  drivers produces a correctly-formatted, fully-serialized display string, since `ZoneRecord`
  has no typed sub-fields for these types.
- **Note:** This phase was explicitly scoped to documentation-only research (no live provider
  account access), per request. The items below are marked accordingly — this is a
  doc-verified conclusion, not a live-tested guarantee, and should be re-verified against a
  real account if one becomes available.
- **IONOS:** re-checked `IonosDriver::extractContent()`'s existing 2026-07-29 conclusion
  (flat `content`, no split sub-fields for ALIAS/PTR/SOA/SRV/CAA) against IONOS's current
  DNS API documentation.
  - [ ] Not yet verified live (doc-only re-check performed 2026-08-03; no change from the
    existing verified conclusion)
- **Cloudflare:** checked `CloudflareDriver::fetchZoneRecords()`'s SRV/CAA/SOA handling
  against Cloudflare's current DNS Records API reference. Found SRV/CAA responses carry both
  a structured `data` object and a pre-serialized `content` string; SOA is not a Cloudflare
  DNS record type at all (zone-level, not exposed by the records endpoint).
  - [ ] Not yet verified live (doc-verified 2026-08-03: existing flat pass-through in
    `fetchZoneRecords()` is correct as-is for SRV/CAA; no SOA row can ever occur for this
    driver)
- **Dinahosting:** checked `DinahostingDriver::extractContent()`'s generic fallback against
  Dinahosting's own API docs and the `libdns/dinahosting` reference client. Found no
  documented structured sub-fields for SRV/SOA/CAA; the reference client treats SRV as an
  opaque flat value and doesn't model SOA/CAA at all.
  - [ ] Not yet verified live (doc-verified 2026-08-03: existing generic `default` fallback
    in `extractContent()` is the correct best-effort handling; no structured shape found to
    parse)
- **Expected (all three):** no code changes to `WRITABLE_TYPES` or any create/update path —
  SRV/SOA/CAA remain write-disabled by design (§11.4/§11.8); this phase only touched the
  read/display path.

### Phase 51 Credential-leak audit and `PluginLogger` scrubber (ARCHITECTURE.md §15.2)
- **Requirement:** No `PluginLogger::activity()`/`error()` call site, and no
  `DriverException`/`GuzzleException` message that reaches one, should be able to write a
  decrypted credential, `Authorization` header, or full request body to either log file.
- **Steps:** Grepped all 55 `PluginLogger::activity()`/`error()` call sites across the three
  drivers, `Cron.php`, the controllers and `DnsRecordWriteback`/`SyncLogger`; checked each
  driver's Guzzle client construction and every `DriverException`-raising branch.
- **Finding (doc-verified, no live account access needed):** no leak found. All three drivers
  authenticate via a Guzzle `headers`/`auth` client option (`Authorization: Bearer` for
  Cloudflare, `X-Api-Key` for IONOS, HTTP Basic Auth for Dinahosting) — never a request URI or
  body param — and all three set `http_errors => false`, handling non-2xx responses manually
  rather than via a thrown `GuzzleException` whose message could embed request detail.
  `GuzzleException::getMessage()` (only reachable for genuine connection failures, e.g.
  `ConnectException`) is, by Guzzle's own `RequestException::create()`, built solely from the
  user-info-redacted request URI, the HTTP method, and a truncated response-body summary —
  never the request headers or body. `PluginLogger::redact()` (§3.6) was widened regardless, as
  the residual guard: now also matches `auth_code`/`credential(s)` keys and quoted JSON-style
  values (`"password":"x"`).
- **Expected:** No behavior change to any driver or controller; `PluginLogger::redact()`'s
  regex is broader; the finding above is documented in `PluginLogger::redact()`'s own docblock.
- [x] Verified (doc/code audit, 2026-08-03 — grep-based, no live provider account access
  needed; this phase's deliverable is the audit finding, not a live test)

### Phase 52 Audit of the per-type write-right helpers (ARCHITECTURE.md §15.2)
- **Requirement:** every field lookup, type resolution and right check in
  `DnsRecordWriteback::hasTypeRight()`, `hasPurgeRight()`, `writableTypes()`,
  `creatableTypesForDomain()` and `writableSupplierName()` reads `domainrecordtypes_id` (never a
  `type` field) and is entity-aware against the target `Domain`, per §11.6/§8.
- **Findings:**
  - `writableTypes()` returns the `WRITABLE_TYPES` constant directly — no field lookup, no right
    check, nothing to fix.
  - `writableSupplierName()` only reads `DomainState`/`SupplierConfig`/`Supplier` for display
    purposes; it never checks a per-type right and was never in scope for the field-name bug
    (§11.6 addendum's `type` vs `domainrecordtypes_id` confusion is specific to
    `hasPurgeRight()`), so no change.
  - **Bug found:** `hasTypeRight()`, `hasPurgeRight()` and `creatableTypesForDomain()` all route
    through the private `hasRight()`, which checked only `Session::haveRight($rights[$type],
    $bit)` — a bare profile-bit check with no notion of which `Domain` the caller is asking
    about. A profile granted a per-type DNS write-back right (e.g. `domainmanager:dns_records_txt`
    CREATE) for one entity held it over every entity's zones, contradicting §11.6's "every entry
    point checks rights server-side, entity-aware" and the `Domain::can()` convention used
    everywhere else in the plugin (§8). This is the silent-privilege-escalation counterpart the
    `hasPurgeRight()` field-name bug (fixed in `1.4.2`) failed *closed* on — this one failed
    *open*.
- **Fix:** `hasRight()` now takes the target `$domains_id`, fetches the `Domain`, and additionally
  requires `Session::haveAccessToEntity($domain->fields['entities_id'],
  $domain->fields['is_recursive'])` — the same primitive `CommonDBTM::canViewItem()`/
  `canUpdateItem()`/etc. use internally, mirrored here because these per-type rights aren't
  itemtype-scoped GLPI rights that `Domain::can()` itself resolves. Threaded `$domains_id` through
  every call site: `onPreAdd()`/`onPreUpdate()`/`onPreDelete()`/`onPreRestore()` (already had it
  in scope), `hasPurgeRight(DomainRecord $item)` (from `$item->fields['domains_id']`),
  `hasTypeRight()` and `creatableTypesForDomain()` (new required parameter, updated at both
  `DomainForm.php` call sites).
- **Expected:** no behavior change for a single-entity install (every domain and every profile
  assignment share one entity, so `Session::haveAccessToEntity()` is always true there); a
  multi-entity install now correctly refuses a per-type write-back right granted only for a
  different entity than the target domain's.
- [ ] Not yet verified live (code fix + doc/code audit, 2026-08-03; requires a multi-entity
  install with a profile scoped to one entity to confirm cross-entity denial in practice)

### Phase 53 Global write kill switch / read-only mode (ARCHITECTURE.md §15.3)
- **Requirement:** one `config`-gated boolean hard-disables every outbound DNS record mutation
  across every driver, independent of per-type rights, enforced at a single point in
  `DnsRecordWriteback` and asserted again in each driver's writer methods; surfaced in the UI
  wherever a write control appears, with an actionable message naming the setting.
- **Implementation:** `Config::isReadOnlyMode()`/`setReadOnlyMode()`, new `read_only_mode` key on
  the existing `plugin:domainmanager` config context (stored as explicit `1`/`0`, §0.10), editable
  from the Domain Manager Setup tab (`config` UPDATE right, existing convention) via a slider
  field. `DnsRecordWriteback::readOnlyModeError()` is checked in `onPreAdd()`/`onPreUpdate()`/
  `onPreDelete()`/`onPreRestore()` right after the `isDnsEditable()` gate and before the per-type
  right check, so it's never bypassed by a user's own rights — `abort()` surfaces the existing
  session-message convention naming the setting and where to find it.
  `Config::assertWritesAllowed()` (throws `DriverException`) is asserted again at the top of every
  driver's `createRecord()`/`updateRecord()`/`deleteRecord()` (Cloudflare, IONOS, Dinahosting) and
  `setProxied()`/`pushComment()` (Cloudflare only). `DomainForm::injectDomainRecord()`'s
  Save/Delete/proxy-toggle gating and `renderRecordWritePanel()`'s add-form both check
  `Config::isReadOnlyMode()` too, each showing a distinct message naming read-only mode instead of
  their normal "no rights"/"synchronization-locked" copy when that's the actual reason a control is
  hidden.
- **Verified by code inspection, 2026-08-03:**
  - Confirmed `RecordReconciler::reconcileComment()` calls `CloudflareDriver::pushComment()`
    directly during a cron sync, entirely outside `DnsRecordWriteback`'s call path — this is the
    concrete case the driver-level assertion (not just the `DnsRecordWriteback` choke point) is
    needed for; it's caught there and logged as a best-effort failure, same as any other
    `pushComment()` error.
  - Confirmed every one of the three drivers' `createRecord()`/`updateRecord()`/`deleteRecord()`
    now calls `Config::assertWritesAllowed()` as its first statement, and Cloudflare's
    `setProxied()`/`pushComment()` do too.
  - Confirmed a fresh install defaults to `read_only_mode = 0` (writes allowed) via
    `Config::getDefaults()`, so upgrading or installing never silently goes read-only.
- [ ] Not yet verified live (code audit only, 2026-08-03; requires enabling the setting against a
  live-configured domain and confirming a create/update/delete/restore is refused with the
  expected message, then confirming it resumes once turned back off)

### Phase 55 Sync safety guard on reconciliation (ARCHITECTURE.md §15.3)
- **Requirement:** abort a reconciliation run and set a distinct `DomainState` status when it would
  trash more than N records or more than X% of a domain's owned records, whichever is hit first;
  requires explicit operator action to proceed.
- **Implementation:** `RecordReconciler::doReconcile()` counts, after its existing match/claim pass
  but before the trash loop, how many currently-owned (non-deleted) records this run would newly
  trash, against `Config::getSyncSafetyMaxCount()`/`getSyncSafetyMaxPercent()` (new
  `sync_safety_max_count`/`sync_safety_max_percent` keys on the existing `plugin:domainmanager`
  config context, editable on the Setup tab, defaults 20/50). Crossing either throws
  `Exception\SyncSafetyExceededException` before any trash-bin mutation runs.
  `SyncEngine::syncDnsLeg()` catches it distinctly from `DriverException`/`Throwable` and sets the
  new `DomainState::STATUS_SYNC_SAFETY_GUARD` (its own label/badge class in
  `DomainStatusResolver`). `reconcile()`/`sync()` gained a `$force` parameter (default `false`);
  `POST /plugins/domainmanager/sync/{id}` accepts a `force` field, and the domain panel's "Update
  Now" button, on receiving `STATUS_SYNC_SAFETY_GUARD`, shows a `window.confirm()` naming the exact
  counts and re-issues the request with `force=1` only if the operator confirms.
- **Verified by code inspection, 2026-08-03:**
  - Confirmed the count is computed strictly before the trash loop, so a run that trips the guard
    performs zero `DomainRecord::delete()` calls this pass (though any `createRecord()`/`update()`
    calls from the earlier match/claim pass — for records the provider *did* still report — have
    already applied; only the trash side is gated, matching the phase's own scope).
  - Confirmed every existing caller of `reconcile()`/`sync()` (`Cron`, `MassiveActionHandler`,
    `DomainImportController`, `RdapGapChecker`) leaves the new parameter at its `false` default, so
    behavior for all of them is unchanged unless the guard actually trips.
  - Confirmed the threshold comparison is an OR (either count or percent alone trips it), matching
    "whichever is hit first."
- [ ] Not yet verified live (code audit only, 2026-08-03; requires a live-configured domain, a
  synthetic near-empty upstream snapshot, and confirming the sync is refused with the expected
  message/status, then confirming the "force" override actually reconciles when confirmed)

### DomainSync fair rotation (ARCHITECTURE.md §16, Part 2)
- **Requirement:** every domain's `last_sync_date` must advance across enough consecutive cron
  ticks to exceed one full rotation cycle — not merely the first batch — and a domain whose sync
  fails must not monopolize the queue (§16.5/§16.9).
- **Regression case — full-cycle rotation.** Create 10 active, non-deleted, non-template domains
  with no `DomainState` row (never synced). Set the `DomainSync` automatic action's `param` to 3.
  Run `Cron::cronDomainSync()` 4 times in sequence (a full CLI/console-triggered run each time, not
  a single call). Expected: after run 1, domains 1–3 (lowest `id`, per the new secondary `ORDER BY
  glpi_domains.id ASC`, §16.4) have a fresh `last_sync_date`; after run 2, domains 4–6 do; after
  run 3, domains 7–9; after run 4, domain 10 plus a re-sync of domain 1 (the batch wraps once every
  domain has been touched once, since domain 1 is now the oldest again). Assert every one of the
  10 domains' `last_sync_date` is non-null and has changed at least once by the end of run 4 — not
  just the first 3.
  - [ ] Not yet verified live (requires a throwaway console-command/cron-trigger harness against
    `~/containers/testing`; code review + `php -l`/`phpcs`/`php-cs-fixer` only so far)
- **Regression case — a permanently failing domain does not starve the queue.** Among the 10
  domains above, configure one (e.g. domain #2) so its registrar/DNS sync always throws (an
  invalid/unreachable driver config is enough — no test-only code path needed, since
  `SyncEngine::sync()` already catches every leg-internal exception and still stamps
  `last_sync_date`, §16.5). Run `cronDomainSync()` across enough ticks to complete two full
  rotation cycles. Expected: domain #2's `last_sync_date` advances on the same schedule as every
  other domain (i.e. once per cycle) rather than being reselected on every single tick; the other 9
  domains still each get exactly one sync per cycle.
  - [ ] Not yet verified live (same harness as above; also confirms §16.5's "no starvation from a
    driver/API failure" finding rather than just asserting it from a code read)
- **Verified by code inspection, 2026-08-07:**
  - Confirmed the query's `LEFT JOIN` (never-synced domains have no state row at all, §16.3) and
    `ORDER BY last_sync_date ASC, glpi_domains.id ASC` (new secondary tie-break, §16.4) in
    `src/Cron.php`.
  - Confirmed `SyncEngine::sync()`'s step-5 state upsert writes `last_sync_date = $now`
    unconditionally on every reachable outcome (`src/Service/SyncEngine.php:268-278`), and that
    `Cron::cronDomainSync()`'s own outer `catch (Throwable $e)` now also stamps it via
    `self::upsertState()`, closing the one narrow gap identified in §16.5/§16.9 (a `sync()` call
    throwing before reaching its own upsert).
  - Confirmed `Installer::registerCronTasks()`'s new defaults (10 min / `param = 3` / `hourmin = 0`
    / `hourmax = 24`) only apply to a fresh install, and `Installer::upgradeDomainSyncContinuousDefaults()`
    only rewrites an existing `DomainSync` `glpi_crontasks` row when it still holds exactly the
    *previous* shipped default (`DAY_TIMESTAMP` / `param = 20` / `hourmin = 23` / `hourmax = 24`) —
    confirmed against the live `~/containers/testing` instance's actual stored row (`frequency =
    900`, `param = 2`, ARCHITECTURE.md §16.6 point 10), which does **not** match that tuple, so the
    upgrade guard correctly leaves it untouched.
- [ ] Not yet verified live end-to-end (the two regression cases above and a real upgrade run
  against `~/containers/testing`'s existing diverged `DomainSync` row, confirming it is left
  untouched rather than overwritten)

### Phase 69 — dedicated `domainmanager:dns_record_proxy` right (implemented then reverted, ARCHITECTURE.md §17.14b)

Implemented 2026-08-07, then reverted the same day per owner decision: proxy toggling is folded back
into the existing per-type write-back UPDATE right rather than gated by a separate right. No new
right exists, so no dedicated test cases apply — proxy-toggle behavior is covered by the existing
per-type UPDATE right's own test coverage (Phase 37/49 above). See ARCHITECTURE.md §17.14b for what
was reverted and why.

### Phase 71 — lazy per-row public IP lookup for proxied records (ARCHITECTURE.md §17.16) — SUPERSEDED

**Superseded by the phase below** (persisted `proxy_addresses`/`is_ttl_auto`, no more on-click
lookup, `RecordPublicIpController` removed). Kept here for history; do not test against current
code — the "Show public IP" button and its endpoint no longer exist.

### Phase — persist proxy addresses + TTL-auto flag, then relocate the display

- **Cloudflare domain, mix of proxied and non-proxied records.** Sync a Cloudflare-managed domain
  with at least one proxied A/AAAA record and at least one non-proxied record. Expected: the
  proxied record's row shows the cloud icon next to Name (unchanged) *and* a grey second line
  under the Target cell, itself prefixed with its own small cloud icon, listing the resolved
  anycast address(es); the non-proxied record's row shows neither.
  `glpi_plugin_domainmanager_records.proxy_addresses` is a JSON array for the proxied record's
  `ImportedRecord` row, `NULL` for the non-proxied one.
  - [ ] Not yet verified live
- **Cloudflare domain, TTL "Automatic".** A Cloudflare record whose upstream `ttl` is `1`.
  Expected: the TTL cell shows "Automatic" (translated), and hovering it shows the raw value `1`
  as a tooltip. `is_ttl_auto = 1` on that record's `ImportedRecord` row.
  - [ ] Not yet verified live
- **Cloudflare domain, none proxied.** Sync a Cloudflare-managed domain with zero proxied
  records. Expected: no cloud icon, no address line, no "Automatic" TTL anywhere on the tab (unless
  a non-proxied record still legitimately has `ttl == 1`, in which case only the TTL rendering
  applies — proxying and TTL-auto are independent per-record flags).
  - [ ] Not yet verified live
- **Non-Cloudflare domain (IONOS or Dinahosting).** Sync a domain managed by a driver with no
  proxy/TTL-sentinel concept. Expected: `is_proxied`, `proxy_addresses`, and `is_ttl_auto` all stay
  `NULL` for every record; the Records tab renders with no overlay at all, byte-identical to
  before this phase.
  - [ ] Not yet verified live
- **Bounded lookups per sync.** A domain with more proxied records than
  `RecordReconciler::PROXY_IP_LOOKUP_LIMIT` (20). Expected: the first 20 (iteration order) get a
  resolved `proxy_addresses` value (or `NULL` if the live lookup itself found nothing); the rest
  keep whatever `proxy_addresses` value they already had (not forcibly cleared) until a later sync
  reaches them.
  - [ ] Not yet verified live (needs a zone with >20 proxied records, or a lowered constant for the
        test)
- **Migration: existing rows survive with `NULL`, populate on next sync.** On an instance
  upgrading from before this phase (`glpi_plugin_domainmanager_records` rows with no
  `proxy_addresses`/`is_ttl_auto` columns yet), run `install()`/plugin update. Expected: both
  columns exist, every pre-existing row reads `NULL` for both (no backfill, matching `is_proxied`'s
  own upgrade behavior) — confirm via direct DB query, not just the UI (a `NULL` row renders
  identically to "nothing to show", so the DB check is the only way to distinguish "column added,
  still unpopulated" from "column never added"). Then trigger a sync for one such domain and
  confirm both columns populate for its proxied/TTL-auto records.
  - [ ] Not yet verified live
- **"Show public IP" removed, no dead endpoint.** Confirm `src/Controller/RecordPublicIpController.php`
  no longer exists and `GET /plugins/domainmanager/recordip/{id}` 404s (route no longer registered).
  Confirm the address is never shown in two places (neither a leftover button near the Name cell
  nor any other duplicate).
  - [ ] Not yet verified live

### Cloudflare 403 error messages name the real restriction, not just "missing permission"

Found live 2026-08-08: a Cloudflare token restricted by "Client IP Address Filtering" (dashboard
setting on the token itself) returns HTTP 403 with error `code: 9109` and a `message` naming the
blocked IP — every 403 branch in `CloudflareDriver` previously discarded that and always showed
"lacks DNS:Read/Edit permission for this zone", which sent troubleshooting toward the wrong cause
(token scopes) instead of the right one (the token's IP allowlist).

- **Check Connection surfaces the real reason for an IP-restricted token.** Configure a Cloudflare
  API token with "Client IP Address Filtering" excluding the GLPI server's actual egress IP. Run
  "Check Connection" on the Supplier's DNS leg. Expected: the failure message names the IP
  restriction and the blocked address (Cloudflare's own wording), not the generic
  "lacks DNS:Read permission" text. (The underlying root cause — `code: 9109`,
  `"Cannot use the access token from location: 213.177.194.73"` — was confirmed live in
  `domainmanager-errors.log` before this fix; the fix itself still needs a live re-check.)
  - [ ] Not yet verified live (re-check after this fix, against the same IP-restricted token)
- **"Update Now" / sync surfaces the same real reason.** Trigger "Update Now" on a Domain whose
  Cloudflare token is IP-restricted the same way. Expected: `domainmanager-errors.log`'s
  "DNS leg failed" line, and any surfaced UI message, both name the IP restriction — not the
  generic missing-permission text.
  - [ ] Not yet verified live
- **An actual missing-scope 403 (no restriction code) still falls back to the generic message.**
  A token with `DNS:Read`/`DNS:Edit` genuinely absent from its scopes (not IP-restricted) returns a
  403 with no `9109`/`9208` error code. Expected: the pre-existing generic "lacks DNS:Read/Edit
  permission" message still shows — `describeForbidden()`'s fallback path.
  - [ ] Not yet verified live (needs a second token with a genuine scope gap, not an IP
        restriction, to test against)

### TTL-automatic reminder on the DNS record add/edit forms

- **Edit form, Cloudflare-managed writable record, user holds UPDATE.** Open an existing,
  plugin-imported, writable-type (A/AAAA/CNAME/TXT) record's native edit page on a Cloudflare
  write-back-managed domain. Expected: the existing "Managed by Domain Manager" banner gains a
  second line — `Setting TTL to 1 means "Automatic".` — and the TTL field itself remains editable.
  - [ ] Not yet verified live
- **Edit form, record not manageable (wrong type, no right, or non-Cloudflare driver).** Open a
  record's edit page where `can_update` is false (NS/MX record, missing UPDATE right, IONOS/
  Dinahosting-managed domain, or the domain isn't write-back editable at all). Expected: no TTL
  note shown (the "Managed by Domain Manager" banner itself doesn't render), and the TTL field is
  cosmetically locked with the existing lock icon — unchanged pre-existing behavior, not new to
  this change.
  - [ ] Not yet verified live
- **Add panel, Cloudflare write-back-managed domain.** Open the Records tab of a Cloudflare
  write-back-managed domain and use the Domain-Manager-branded "Add a DNS record" panel. Expected:
  an info icon next to the TTL label shows the "Setting TTL to 1 means Automatic" text on hover.
  - [ ] Not yet verified live
- **Add panel, IONOS/Dinahosting write-back-managed domain.** Same panel on a non-Cloudflare
  write-back-managed domain. Expected: no info icon next to the TTL label (the driver doesn't
  implement `DnsRecordTtlAutoInterface`).
  - [ ] Not yet verified live
- **Generic blank "New Domain record" form (top-nav "+" / global Domains-records list).** Open
  this form without a domain preselected. Expected: a small hedged note ("If this domain uses
  Cloudflare DNS, setting TTL to 1 means 'Automatic'.") is shown at the top of the form, alongside
  (but independent of) the existing write-back warning banner, regardless of which domain ends up
  selected.
  - [ ] Not yet verified live

### Phase 72: per-domain logging on cronDomainSync

- **Trigger `cronDomainSync` manually against a batch of test domains** (Setup > Automatic
  actions > "domainmanager - domainsync" > Execute, or CLI). Expected: the run's Logs entry shows
  one line per domain processed (`<entity>: domain #<id> (<name>) — synced`, or `— error (...)`
  on failure), in addition to the existing run summary and per-entity/per-registrar breakdown
  lines.
  - [ ] Not yet verified live
- **Force one domain in the batch to fail** (e.g. temporarily break its Supplier credentials).
  Expected: that domain's per-domain log line shows an `error (...)` outcome distinct from the
  synced ones, and the run summary's error count still matches.
  - [ ] Not yet verified live

### Phase 74: no duplicate history entry on write-back create/update

- **On a write-back-managed domain (Cloudflare/IONOS/Dinahosting driver configured and
  write-eligible), add a new writable-type record (A/AAAA/CNAME/TXT) via the native "New Domain
  record" form.** Expected: the Domain's Historical tab shows exactly one entry for the add (the
  native "Domain record added" / subitem line) — no second "[Domain Manager] Create ... at
  \<provider\>: succeeded" line alongside it.
  - [ ] Not yet verified live
- **Edit that record's data or TTL.** Expected: exactly one Historical entry per changed field
  (e.g. "Data updated: ... → ..."), still no separate "[Domain Manager] Update ... succeeded"
  line.
  - [ ] Not yet verified live
- **Force a create or update failure** (e.g. temporarily break the driver's credentials or
  trigger a validation error at the provider). Expected: still see the plugin's own
  "[Domain Manager] Create/Update ... at \<provider\>: failed: ..." Historical-tab line — the
  failure-path logging is unchanged.
  - [ ] Not yet verified live
- **Trash a write-back-managed record, then restore it.** Expected: unchanged from before this
  phase — a "[Domain Manager] Delete ... succeeded"/"Restore ... succeeded" line for each, since
  native soft-delete/restore logging doesn't fire for non-dynamic items.
  - [ ] Not yet verified live
- **Toggle a Cloudflare record's proxy status.** Expected: unchanged — a
  "[Domain Manager] Proxy toggle ... succeeded" line still appears (this path never had a native
  duplicate).
  - [ ] Not yet verified live

### Known upstream GLPI 11 bug: cron task "Logs" detail view never shows per-item lines

Not a Domain Manager bug — confirmed as a genuine GLPI 11 core regression, still present on
`main` (unreleased next major) as of this check. Affects every plugin's/core's cron task the
same way, including this plugin's `cronDomainSync`/`cronRdapEnrichment` (Phase 72).

**Symptom:** Setup > Automatic actions > [any task] > Logs lists one row per run (e.g. "Action
completed, fully processed"). Clicking that row's date to drill into per-item detail reloads the
tab with the exact same single row — no per-item lines ever appear, even though they exist.

**Root cause (confirmed by diffing `src/CronTask.php` across branches):** `showHistory()`
builds each run's date link. On GLPI 10.0/bugfixes it correctly links using
`$data['crontasklogs_id']` (the shared group key every child log row's own `crontasklogs_id`
column points at). Somewhere in GLPI 11's Twig rewrite of this method, that became
`(int) $data['id']` — the STOP row's *own* primary key, not the shared group key. Since
`showHistoryDetail($logid)` queries `WHERE id=$logid OR crontasklogs_id=$logid`, and every
per-item/summary child row's `crontasklogs_id` points at the run's *start* row (a different id
than the stop row you clicked), the detail query can never find them. Confirmed still present
on GLPI 11.0/bugfixes (the pinned target branch) and on `main` (next major, unreleased) as of
2026-08-08; not present on 10.0/bugfixes. No matching GitHub issue found in
`glpi-project/glpi` as of this check — worth filing upstream if it still isn't fixed by the
time this is revisited.

**How to verify this plugin's own cron logging is actually correct despite the broken UI:**
query `glpi_crontasklogs` directly —
```sql
SELECT id, crontasks_id, crontasklogs_id, date, state, volume, content
FROM glpi_crontasklogs WHERE crontasks_id = <id> ORDER BY id DESC LIMIT 30;
```
Every run's rows sharing the same `crontasklogs_id` (the START row's id) are the full picture;
don't rely on the "click date" UI in this GLPI version.

### Phase 73: batched supplier import no longer syncs inline

- **Import a batch of several new domains from a supplier's discovery modal.** Expected: the
  request returns quickly (no long wait proportional to batch size), the summary message reports
  "N domains imported" with no "could not be synced yet" line, and every new `Domain` shows as
  never-synced (state "Never"/no registrar or DNS info yet) immediately after the redirect.
  - [ ] Not yet verified live
- **Trigger `cronDomainSync` manually right after that import** (Setup > Automatic actions >
  "domainmanager - domainsync" > Execute, or CLI). Expected: the just-imported domains are
  processed first (or among the first, if the batch exceeds the cron's per-run limit), since they
  have no `last_sync_date` and sort ahead of every previously-synced domain; each one ends up with
  real registrar/DNS state afterward, and `is_glpi_created` reads as "not Native" for them.
  - [ ] Not yet verified live
- **Re-import a previously-trashed domain (restore path).** Expected: unaffected by this phase —
  the restored domain already carries its old state row, no new bare state row is created for it,
  and it simply gets picked up again by the normal oldest-first sync ordering.
  - [ ] Not yet verified live

### Phase 75: consistent warning styling

- **Open the generic blank "New Domain record" form on a write-back-managed domain** (top-nav
  "+" or global Domains-records list, then pick a Cloudflare/IONOS/Dinahosting-managed domain).
  Expected: the write-back warning banner shows the `ti-world-cog` icon inline with its text (no
  visible layout change from before), in both light and dark theme.
  - [ ] Not yet verified live
- **Open the DNS record edit panel while Domain Manager is in read-only mode**, and separately
  **on an imported/locked record with the read-only mode off**. Expected: each shows its
  respective banner (`ti-lock` / `ti-cloud-lock`) with no layout regression, in both themes.
  - [ ] Not yet verified live
- **Open a domain form whose DNS provider is unsupported or unidentified.** Expected: the
  `ti-alert-triangle` provider warning (with its "Help us support this provider" link) still
  renders correctly in both themes.
  - [ ] Not yet verified live
- **Open a Supplier's config tab with a Cloudflare configuration missing an Account ID.**
  Expected: the warning banner renders correctly in both themes.
  - [ ] Not yet verified live
- **Screen-reader/accessibility spot check**: confirm all four banners above are announced as
  alerts (`role="alert"` now present on every one, including the two that previously lacked it —
  `domain_panel.html.twig`'s provider warning and `supplier_tab.html.twig`'s Cloudflare notice).
  - [ ] Not yet verified live

### Phase 76: DNS Provider hyperlink survives "Update Now"

- **Load a domain with a live, resolvable Supplier as its DNS provider.** Expected: the DNS
  Provider field renders as a real hyperlink to that Supplier's own page.
  - [ ] Not yet verified live
- **Delete that Supplier, then reload the domain form.** Expected: the DNS Provider field now
  renders as plain text (no link) — a deleted Supplier genuinely isn't linkable, this is the
  correct baseline, not a regression.
  - [ ] Not yet verified live
- **On a domain with a live Supplier, click "Update Now" and watch the DNS Provider field without
  reloading the page.** Expected: the field stays (or becomes) a working hyperlink immediately
  after the sync completes — it should not collapse to plain text and then only become a link
  again after a manual page reload.
  - [ ] Not yet verified live

### Phase 77: reconciler sync no longer aborts on a genuine upstream TXT duplicate

- **Create two TXT records with identical name and content at the provider** (Cloudflare/IONOS/
  Dinahosting) for a write-back-managed domain, then run "Update Now" twice in a row. Expected:
  the first sync mirrors one of them in locally as usual; the second sync completes successfully
  (no user-facing abort message) instead of stalling on the duplicate, and `domainmanager.log`
  shows a "Skipped mirroring duplicate upstream TXT record ..." activity line.
  - [ ] Not yet verified live
- **Confirm a genuine user-initiated duplicate TXT add is still rejected.** Manually add a DNS
  record via the UI with the same type+name+content as an existing TXT record on the same
  domain (not via sync). Expected: still hard-aborts with the original "already exists for this
  domain" error message — this phase only changes reconciler-driven (sync) adds.
  - [ ] Not yet verified live
- **Confirm A/AAAA/CNAME duplicate protection is unchanged.** Attempt to create a second A record
  at the same name (manually, or by letting a reconciler sync mirror one in if reproducible).
  Expected: still hard-aborts exactly as before this phase — the skip-with-log behavior is scoped
  to sync-driven TXT duplicates only.
  - [ ] Not yet verified live

### Phase 78: deleted proxied record no longer shows stale proxy state

- **Proxy a record, sync, delete it upstream, sync again, then view the Records tab's native
  trash bin.** Set up a Cloudflare-proxied A record, run "Update Now" so the cloud icon and
  proxied-IP line appear; delete that record at Cloudflare; run "Update Now" again so the local
  row moves to the trash bin. Toggle the Records tab's native "show deleted" view. Expected: the
  trashed row appears with no cloud icon and no leftover proxied-IP line — just its last-known
  plain data.
  - [ ] Not yet verified live
- **Confirm live (non-deleted) proxy indicators are unaffected.** With the trash-bin view back
  off, confirm every still-live proxied record still shows its cloud icon and proxied-IP line as
  before this phase.
  - [ ] Not yet verified live

### Phase 79: three more warning notices normalized (missed by Phase 75)

- **Open an existing DNS record's edit form on a write-back-managed domain.** Expected: the
  "Managed by Domain Manager" ribbon-card's "Saving this form updates the record live at ...
  There is no undo." line now renders as an `alert-warning` banner (icon + visible border/
  background, `role="alert"`), not plain muted text — matching the identical message already
  shown that way on the generic "New Domain record" form. Check both light and dark theme.
  - [ ] Not yet verified live
- **Open that same domain's Records tab and open its own quick-add panel** (not the generic
  top-nav "+" form). Expected: its "This creates the record live at <Supplier>. There is no
  undo." notice now also renders as an `alert-warning` banner, in both themes.
  - [ ] Not yet verified live
- **Open a Supplier's Domain Manager tab and select each of Cloudflare, IONOS, and Dinahosting
  as the API driver in turn.** Expected: each driver's credential-requirement hint ("Requires an
  Account API Token…" / "Requires an API Key and Secret…" / "Requires the super-admin account's
  username and password…") now renders as an `alert-warning` banner instead of a plain muted
  hint, with its "Setup instructions" link still present and working. Check both themes.
  - [ ] Not yet verified live

### Phase 85 follow-up 4: "Managed records" label, widened proxied-records picker, registrar-status duplicate bucket

- **Open the Domain Manager dashboard and check the records-count bigNumber card's on-widget
  label.** Expected: reads "Managed records" (was "Records").
  - [x] Pass — verified live 2026-08-09 against `testing_glpi_1` (GLPI 11.0.8): rendered HTML's
    `<div class="label">` reads "Managed records".
- **Edit the "Proxied records" card and open its chart-type picker.** Expected: offers Pie,
  Donut, Number(s), Bar, and Horizontal bar — same set as every other breakdown card, not just
  Donut.
  - [x] Pass — verified live 2026-08-09: re-rendered the card with `widgettype=bar` and it
    returned a valid bar-chart card (previously only `donut` was an allowed option at all).
- **Open the "Registrar sync status" card and count the "Never synchronized" slice/row.**
  Expected: exactly one "Never synchronized" entry, not two.
  - [x] Pass — verified live 2026-08-09: direct SQL reproduction showed the query splitting 4
    domains into two "never" rows (3 + 1); after the `GROUPBY` fix the same query returns a single
    row of 4, and the live-rendered card shows one "Never synchronized" label.

### Phase 88: hide the Domain Manager panel entirely for a domain with nothing to manage (ARCHITECTURE.md §20.8)

- **Open a domain that has never been picked up by sync (no `glpi_plugin_domainmanager_states`
  row for it at all) and check the main tab.** Expected: no Domain Manager section renders at
  all — not even the "Not managed by Domain Manager" message.
  - [x] Pass — verified live 2026-08-09 against `testing_glpi_1` (GLPI 11.0.8): created a
    domain with no state row, fetched its main tab via `ajax/common.tabs.php?_glpi_tab=Domain$main`
    directly (authenticated session), 0 occurrences of `domainmanager-panel` in the response.
- **Open a manually-created domain with a state row but no registrar/DNS supplier linked at
  all (`ticgal.internal`, id 20 — `registrar_suppliers_id = 0`, `dns_suppliers_id = 0`,
  `is_managed = 0`).** Expected: no Domain Manager section at all, same as the no-state-row
  case — amended after first finding it still showed the "not managed" message.
  - [x] Pass — verified live 2026-08-09 in the browser: no Domain Manager section on the
    domain's form.
- **Open a domain linked to a supplier with no Domain Manager driver configured at all
  (id 26 "Fake domain from Upcloud" — Infocom Supplier = Upcloud, `api_driver = NULL`,
  `is_managed = 0`).** Expected: no Domain Manager section either — that supplier can never
  resolve to managed (`DomainState::resolvesToActiveDriver()` requires an active supplier with
  a real `api_driver` and non-empty credentials), so it's the same "nothing to manage yet" case
  as no link at all — amended after first finding it still showed the "not managed" message.
  - [x] Pass — verified live 2026-08-09 in the browser: no Domain Manager section on the
    domain's form.
- **Open a domain linked to a supplier that *does* resolve to an active driver (e.g. Dinahosting/
  Cloudflare/IONOS) but still ended up `is_managed = 0`** (a real, actionable failure — e.g. sync
  ran and hit an error). Expected: existing "Not managed by Domain Manager" message still
  renders, unchanged.
  - [ ] Pass — not yet re-verified live after the second amendment; reasoned from code
    (`resolves_to_driver` becomes `true` for any of these suppliers, so the early return no
    longer fires and the template's own `is_managed` branch renders the message as before).
- **Open a domain with `is_managed = 1` (id 2).** Expected: full panel renders normally, no
  "not managed" message.
  - [x] Pass — verified live 2026-08-09: `domainmanager-panel` present, "Not managed by Domain
    Manager" text absent.
- **Check `onShowTab()`'s indicator on other tabs (Records, Historical, …) for a domain in any
  of the hidden-panel states above.** Expected: no "managed" indicator shown there either —
  confirmed no code change was needed since `renderManagedIndicator()` already no-ops when
  `is_managed` is false.
  - [x] Pass — reasoned from code (`onShowTab()` computes `is_managed` straight from
    `$state->fields['is_managed']`, independent of `injectDomain()`'s new `resolves_to_driver`
    gate, and `renderManagedIndicator()` already no-ops when it's `false`), not separately
    re-verified live per-tab.

## Nullable registrar/RDAP tri-state boolean fields persist `false`, not just `true`

- **Trigger an RDAP-only DNSSEC lookup for a domain with `registrar_dnssec_enabled` still NULL,
  where RDAP reports `secureDNS.delegationSigned: false`** (domain #14, scavogados.com).
  Expected: `registrar_dnssec_enabled` becomes `0` in the DB, not left at NULL.
  - [x] Pass — verified live 2026-08-09 against `testing_glpi_1`: called
    `GlpiPlugin\Domainmanager\Service\SyncEngine::sync()` directly (Kernel-booted CLI script),
    confirmed the computed value was `int(0)` both before and after `DomainState::forceUpdate()`,
    and confirmed via direct DB query the stored value is now `0` (previously reverted to NULL
    after the very next registrar sync, before this fix).
- **Re-run a full registrar sync afterward and confirm the `0` isn't wiped back to NULL.**
  Expected: `registrar_dnssec_enabled` stays `0` across subsequent sync cron ticks.
  - [x] Pass — verified live: re-ran `SyncEngine::sync()` a second time for the same domain,
    value remained `0`.
- **Aggregate check across all domains for `registrar_privacy_enabled`, `registrar_domain_lock`,
  `registrar_dnssec_enabled`, `pending_delete`, `pending_transfer`.** Expected (pre-fix, root
  cause confirmation): every one of these columns had never once stored `0` for any of the 24
  domains in `testing_glpi_1` — only ever NULL or `1`. Not independently re-verified post-fix
  across all 24 (would require live data where a driver/RDAP actually reports `false` for each
  field; DNSSEC case above is the one confirmed live end-to-end).
  - [x] Pass (root cause) — confirmed via aggregate SQL query pre-fix.

## RDAP transfer date falls back to the registrar's own ("thick") RDAP server

- **Look up a domain whose registry RDAP server is "thin" (no `transfer` event at all) but
  whose registrar's own RDAP server reports one** (scavogados.com — Verisign `.com` registry
  via `rdap.org` has no transfer event; the registry response's own `related` link,
  `rdap.ionos.com/domain/SCAVOGADOS.COM`, reports `transfer: 2018-11-07T05:16:32Z`). Expected:
  `RdapClient::lookup()` returns that date as `transferDate`, not null.
  - [x] Pass — verified live 2026-08-09 against `testing_glpi_1`: called
    `RdapClient::lookup('scavogados.com')` directly (Kernel-booted script), got
    `transferDate = 2018-11-07 05:16:32`.
- **Run the RDAP gap-fill cron logic for that domain and confirm the date persists to the DB.**
  Expected: `glpi_plugin_domainmanager_states.transfer_date` becomes `2018-11-07 05:16:32`.
  - [x] Pass — verified live: invoked `Cron::processRdapEnrichment()` directly for domain #14,
    logged "filled transfer_date, ...", confirmed via DB query.
- **Confirm a related-link fetch failure doesn't break the primary lookup.** Expected: any
  error fetching/parsing the registrar's RDAP response is swallowed; the primary (registry)
  result is still returned with `transferDate = null`, not an exception.
  - [ ] Pass — reasoned from code (`fetchTransferDateFromRelated()` wraps its own request in a
    `try`/`catch (Throwable)` returning `null`), not separately exercised live against a
    deliberately-broken related URL.

## Phase 90: Domain delete/purge must never cascade into a driver push or a bogus block

- **Direct record delete (must be unchanged):** soft-delete one write-back-managed
  `DomainRecord` directly. Expected: upstream provider delete call still fires as before.
  - [x] Pass — verified live against `testing_glpi_1`.
- **Domain delete must not touch the driver:** soft-delete the parent `Domain` with that
  record still attached. Expected: `deleteRecord()` is not invoked; the record is soft-deleted
  locally only.
  - [x] Pass — verified live against `testing_glpi_1`.
- **Domain purge, no bogus message, no orphan:** as a user holding Domain PURGE but lacking the
  per-type DNS record PURGE right, purge that Domain. Expected: no ERROR message, the domain is
  purged, and `SELECT * FROM glpi_domainrecords WHERE domains_id = <id>` returns no rows.
  - [x] Pass — verified live against `testing_glpi_1`.
- **Direct record purge (must be unchanged):** purge a `DomainRecord` directly (not via a Domain
  purge) as a user lacking the per-type PURGE right. Expected: existing ERROR still appears and
  the record is not purged.
  - [x] Pass — verified live against `testing_glpi_1`.

## Phase 91: Drop the Transfer/EPP auth code feature entirely

- **Upgrade drops the stored column:** run `glpi:plugin:install domainmanager` on an install
  that already had `registrar_auth_info` populated. Expected: `DESCRIBE
  glpi_plugin_domainmanager_states` no longer lists `registrar_auth_info` at all — not just
  nulled, actually dropped.
  - [x] Pass — verified live against `testing_glpi_1`: had real stored codes for several
    domains (e.g. `qn7!q$sv` for domain #14) before the migration; `DESCRIBE` shows the column
    gone after `glpi:plugin:install`.
- **No leftover references:** `grep -rn "registrar_auth_info|authInfo|GetAuthcode"` across
  `*.php`/`*.twig` returns only the migration's own `dropField()` call and explanatory comments.
  - [x] Pass.
- **Domain panel no longer shows the field:** open a synced domain's form. Expected: the
  registrar-metadata table has no "Transfer / EPP auth code" column at all; the remaining
  columns (WHOIS privacy, Transfer lock, Domain lock, Auto-renew, DNSSEC, Pending delete,
  Pending transfer) still render correctly with matching `<th>`/`<td>` counts.
  - [x] Pass — reviewed the diffed `domain_panel.html.twig` structure directly (7 `<th>` / 7
    `<td>` after removal); not separately exercised in a browser this session (couldn't
    authenticate against `testing_glpi_1`'s web UI with known credentials).
- **Search option removed, no orphaned saved searches:** any saved search referencing the old
  id 9419 (`PLUGIN_DOMAINMANAGER_SO_DOMAIN_AUTH_CODE`) is pruned by
  `pruneStaleSearchOptionCriteria()` on install, same as the two other previously-dropped IDs.
  - [x] Pass — reasoned from code (9419 added to the existing `Domain => [9402, 9403, 9419]`
    stale-ID list); no pre-existing saved search using it was present to exercise live.
- **Static analysis clean:** `phpcs`/`php -l` on every touched file.
  - [x] Pass — verified live: `tools/codesniffer.sh` reports no violations; `php -l` clean on
    all 8 touched PHP files.

## Phase 101: drivers no longer misreport "API is unreachable" on slow DNS/connect latency

- **Reproduce the bug: on a network with elevated DNS resolution latency, Check Connection
  succeeds (fast enough this once) but a heavier call (e.g. "Import Domains") intermittently
  fails with "\<Provider\> API is unreachable".** Expected root cause: `cURL error 28:
  Resolving timed out after 5000 milliseconds` in `domainmanager-errors.log`, well under the
  driver's own configured `REQUEST_TIMEOUT`/`TEST_TIMEOUT` (15s/9s).
  - [x] Pass — reproduced live against `glpi-65108-web` (GLPI 11.0.8) with a real Dinahosting
    account configured (Check Connection reporting `success` in
    `glpi_plugin_domainmanager_supplierconfigs`): `domainmanager-errors.log` showed exactly this
    — `Dinahosting HTTP failure on Services_GetDomains — cURL error 28: Resolving timed out
    after 5000 milliseconds`/`Connection timed out after 5008 milliseconds`, confirming
    `connect_timeout` (Guzzle's separate DNS/TCP/TLS-establishment bound) was silently capped at
    `Toolbox::getGuzzleClient()`'s 5s default the whole time, independent of the driver's own
    15s/9s request-level timeouts. Direct `curl` to the same host from inside the same container
    intermittently timed out on one attempt and succeeded on the next, confirming the network
    itself (not the plugin) has the underlying latency — the bug is the too-tight default
    swallowing that latency instead of tolerating it.
- **After the fix, the same environment should tolerate that latency: raise `connect_timeout`
  from the fetch and confirm it clears.** Expected: `AbstractDriver::getClient()` and
  `IonosDriver::getDomainsClient()` now pass `connect_timeout => 10` explicitly.
  - [x] Pass — confirmed via code review (`git log -S"connect_timeout"` shows this was never set
    anywhere in this codebase, including each driver's pre-`AbstractDriver` `getClient()` — not
    a regression from Phase 98's extraction) and via `php -l`/`vendor/bin/phpunit` (54 tests, 114
    assertions, all green) after the change. Not independently re-confirmed by forcing the exact
    same intermittent live network condition to recur and pass end-to-end (the underlying latency
    is itself intermittent/environmental, not reproducible on demand).
- **Confirm this is a fixed default, not a new user-facing setting** (per explicit user
  direction — a network-latency edge case doesn't need per-provider config surface).
  - [x] Pass — no new plugin setting/config field added; `connect_timeout => 10` is a plain
    array literal in both call sites.
