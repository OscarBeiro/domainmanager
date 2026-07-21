# Domain Manager — Architecture (GLPI 11)

Plugin key: `domainmanager` · Namespace: `GlpiPlugin\Domainmanager\` · Target: **GLPI 11.0.x only** (verified against `glpi-project/glpi` branch `11.0/bugfixes`, commit `9c59c56c`).

Read-only inventory of domain lifecycles (Registrar pipeline) and DNS zone records (DNS Provider pipeline), with hardcoded drivers: `none`, `cloudflare`, `ionos`, `dinahosting`.

---

## 0. Verified GLPI 11 internals & deviations from the brief

Everything below was checked on `11.0/bugfixes` source. **Four assumptions in the brief do not hold on GLPI 11 and require the adjustments described — please approve them explicitly.**

### 0.1 `glpi_domains` has no `suppliers_id` column → "Registrar" mirrors Infocom's native Supplier field
The native Domain table (verified in `install/mysql/glpi-empty.sql`) contains no supplier link at all (only `users_id`, `users_id_tech`). There is nothing to relabel.
**Adjustment (revised 2026-07-21, see below):** the plugin keeps its own `registrar_suppliers_id` column in the plugin state table (§2) — it's what the registrar pipeline actually resolves credentials from, and what the Supplier tab's Domains list (§9 Phase 5.5) and cron/`SyncController` already query by — but it is **not** an independently user-editable field. It's a **read-only mirror of `Infocom::suppliers_id`** for that Domain (the native "Supplier" field on Domain's own Infocom/"Financial and administrative information" tab, which every asset already has). `HookHandler::infocomSaved()` (registered on `Hooks::ITEM_ADD`/`ITEM_UPDATE` for `Infocom::class`, filtered to `itemtype === Domain::class`) keeps the mirror in sync every time that native field changes; the Domain Manager panel just displays it read-only (`fields.readOnlyField()`, §6.2) with a link to the Infocom tab. This replaces an earlier design (a plugin-injected, independently editable "Registrar" `<select>` persisted via `_domainmanager_registrar` on the Domain form itself) — dropped because it duplicated a field GLPI already has, risking the two silently disagreeing about who the registrar actually is.

### 0.2 Registration date maps to `date_domaincreation`, not `date_creation`
`glpi_domains.date_creation` is GLPI's standard *row-creation audit timestamp* (present on every table). The native field for the **domain registration date** is **`glpi_domains.date_domaincreation`** (dedicated column + index, own search option in `Domain::rawSearchOptions()`).
**Adjustment:** Registration Date → `date_domaincreation`. Writing the audit column would corrupt GLPI metadata.

### 0.3 Native `Lockedfield` cannot protect Domain/DomainRecord → plugin lock layer
Verified in `src/Lockedfield.php` + `src/CommonDBTM.php` (`cleanLockeds()`, `manageLocks()`, `getLockedFields()`):
1. `Lockedfield::isHandled()` requires `$item->isDynamic()`, i.e. an `is_dynamic` column. **Neither `glpi_domains` nor `glpi_domainrecords` has one** — core never reads/writes locks for these itemtypes.
2. Even where it applies, native semantics are the *inverse* of the requirement: a lock is created when a **user manually edits** a dynamic item, and it shields that field **from automation** (`cleanLockeds()` strips locked fields from updates that carry `is_dynamic = 1`). It never blocks manual edits. Using it as specified would block *our own sync*, not users.

**Adjustment (closest supported protection):** a plugin-owned lock layer that mirrors the native table shape and enforces the intended direction:
- Table `glpi_plugin_domainmanager_locks` (same shape as `glpi_lockedfields`: `itemtype`, `items_id`, `field`, `value`, unicity on the triple). Refreshed after every successful sync for all fields written.
- **Server-side enforcement (authoritative):** `pre_item_update` hook on `Domain` strips locked fields from the input (with a session warning) unless the user holds `domainmanager:unlock_imported`; `pre_item_update` / `pre_item_purge` / `pre_item_delete` hooks on `DomainRecord` block changes to plugin-imported records the same way.
- **UI enforcement (cosmetic):** JS injected via `post_item_form` disables the locked inputs and shows a lock icon for users without the right (the generic form's native `locked_fields` mechanism can't be fed by plugins for non-dynamic itemtypes).
- Holders of the right edit freely; the next successful sync re-imports API values and re-locks (documented behaviour).

### 0.4 Native gate on DomainRecord writes (`managed_domainrecordtypes`)
`DomainRecord::prepareInput()` (verified) blocks add/update when the session profile's "manageable record types" doesn't include the record's type — **unless `Session::isCron()`**. Cron sync is therefore unaffected, but web-triggered **"Update Now"** performs record writes under the calling user's session.
**Consequence (documented, not worked around):** the profile using "Update Now" must have *Manageable domain record types* = "All" (`[-1]`) or include A/AAAA/NS/TXT/MX/CNAME. The sync engine detects the gap and reports a clear per-pipeline error instead of half-importing.

### 0.5 Other verified internals (no deviation)
| Concern | Verified mechanism (`11.0/bugfixes`) |
|---|---|
| Outbound HTTP | `Toolbox::getGuzzleClient(array $extra_options): Client` (`src/Toolbox.php:1358`) — applies GLPI proxy config; drivers set their own timeouts on top. |
| Credential crypto | `GLPIKey::encrypt(string, ?string): string` / `decrypt(?string, ?string): ?string`. |
| Form hooks | `Hooks::POST_ITEM_FORM` fires inside every generic form (`templates/components/form/buttons.html.twig`), `PRE_ITEM_FORM` in `header.html.twig`; Domain uses the generic form (no custom `showForm`). Hook receives `{item, options}`. |
| CSRF for controllers | `Glpi\Kernel\Listener\ControllerListener\CheckCsrfListener` checks every non-stateless POST automatically; AJAX must send `X-Glpi-Csrf-Token` (token exposed in `<meta property="glpi:csrf_token">`). No manual CSRF code needed. |
| Plugin routes | `Glpi\Routing\PluginRoutesLoader` auto-loads `#[Route]` attributes from `src/Controller/`; path is plugin-relative (`/sync/{id}` → `/plugins/domainmanager/sync/{id}`), route name gets prefix `@domainmanager:`. |
| Plugin autoload | Core autoloader resolves `GlpiPlugin\Domainmanager\X` from `src/X.php` (`src/autoload/legacy-autoloader.php`, `NS_PLUG`). Composer PSR-4 added for tooling only. |
| Cron | `CronTask::register($itemtype, $name, $frequency, $options)` with `hourmin`/`hourmax`/`state` options; dispatch is `"{itemtype}::cron{Name}"(CronTask $task)` + `{itemtype}::cronInfo($name)`; namespaced plugin itemtypes supported (`isPluginItemType()` matches `GlpiPlugin\`). Cron runs with `Session::isCron() === true`. Uninstall via `CronTask::unregister('domainmanager')`. |
| Rights plumbing | `Migration::addRight($name, $rights, $requiredrights)`, `ProfileRight::addProfileRights()` / `deleteProfileRights()`; `Domain::$rightname === 'domain'` (and `DomainRecord` also uses `'domain'`). |
| Record types | `DomainRecordType` ships defaults incl. A(1), AAAA(2), CNAME(4), MX, NS, TXT — resolved **by name** at sync time, created idempotently if an admin deleted them. |
| History | `Log::history($items_id, $itemtype, $changes, $itemtype_link = '', $linked_action = 0)`. |
| DomainRecord data | `data` text + optional `data_obj`; core clears `data_obj` itself when `data` changes (`pre_updateInDB`). Plugin writes `data` only. |

### 0.6 One deliberate exception to "no raw SQL"
`Migration` has **no CREATE TABLE builder** (verified: only `addField`/`dropTable`/… ALTER-level helpers). Initial table creation uses `$DB->doQuery('CREATE TABLE …')` with `DBConnection::getDefaultCharset()/getDefaultCollation()/getDefaultPrimaryKeySignOption()` — the GLPI-idiomatic pattern used by core-adjacent plugins and this repo's template. Everything else (reads, writes, ALTERs, drops) goes through the query builder / `Migration`.

### 0.7 Template reconciliation
The repo contains TICGAL's plugin scaffold (placeholder `0GLPIxx` names, classic `inc/` style, `tools/rename_plugin.sh`). It is a *scaffold to be specialised*, not an existing plugin, and the brief mandates the modern layout, so: **keep** the tooling/CI/i18n/license infrastructure (`tools/`, `.github/`, `.php-cs-fixer.php`, `phpstan.neon`, `.phpcs.xml`, `locales/`, `LICENSE`, `CHANGELOG.md`); **replace** `inc/`, `front/`, `ajax/`, `setup_template.php` with `src/` + Symfony controller equivalents (the Profile-tab rights matrix pattern from `inc/profile.class.php` is retained, re-homed to `src/Profile.php`). Template CI metadata pinned to GLPI 10.0–11.0 will be bumped to 11.0-only.

### 0.8 Connection-diagnostics prerequisites verified on `11.0/bugfixes` (Phase 3.5)
- **Native JS toast API exists as assumed:** `glpi_toast_success(message, caption?, options?)` / `glpi_toast_error(...)` / `glpi_toast_warning(...)` / `glpi_toast_info(...)` are global functions defined in `js/glpi_dialog.js` (used bare, no import, elsewhere in core e.g. `js/common.js`). They build and show a Bootstrap `toast` client-side, independent of any page load. This is what the Check Connection button uses — the older `templates/components/messages_after_redirect_toasts.html.twig` mechanism (`pull_messages()` + `Session::addMessageAfterRedirect`) is for **full-page-load flash messages** and is not used by this AJAX flow.
- **`front/logs.php`'s Setup → Logs viewer enumerates `GLPI_LOG_DIR` generically, no allow-list:** it delegates to `Glpi\System\Log\LogViewer` → `Glpi\System\Log\LogParser::getLogsFilesList()`, which does `scandir($this->directory)` filtered by the regex `/^(.+)\.log$/` — any file ending in `.log` in `GLPI_LOG_DIR` is picked up automatically. `Toolbox::logInFile($name, $text)` writes to `GLPI_LOG_DIR/{$name}.log` (it appends `.log` itself). Consequence: `Toolbox::logInFile('domainmanager', …)` / `Toolbox::logInFile('domainmanager-errors', …)` produce `domainmanager.log` / `domainmanager-errors.log`, which show up in Setup → Logs with **no plugin-side registration or hook needed** — the brief's caution about a possible allow-list does not hold on this branch.

### 0.9 `Toolbox::logInFile()` is a silent no-op unless forced (found during live testing 2026-07-19)
Verified in `src/Toolbox.php` on `11.0/bugfixes`: `logInFile($name, $text, $force = false, $output = true)` only actually writes when `$CFG_GLPI['use_log_in_files']` is truthy **or** `$force` is passed as `true`. `use_log_in_files` is not present anywhere in `install/mysql/glpi-empty.sql` or `src/Config.php`'s defaults — on a stock install it's unset, so every call without `$force = true` silently does nothing (no error, no exception, `domainmanager.log`/`domainmanager-errors.log` simply never get created). **Adjustment:** `PluginLogger::activity()`/`PluginLogger::error()` (§3.6) now always pass `true` as the third argument — a plugin's own diagnostic log files must not depend on an obscure, off-by-default core toggle the admin isn't expected to know about or enable.

---

## 1. File tree (all files NEW unless marked otherwise)

```
domainmanager/
├── ARCHITECTURE.md                      NEW  (this document)
├── setup.php                            NEW  version/requirements (min 11.0.0, max 11.0.99), hook & tab
│                                             registration, PLUGIN_DOMAINMANAGER_REPOSITORY_URL constant
├── hook.php                             NEW (replaces template stub) — thin: delegates to Installer,
│                                             maps item hooks to src/ services
├── composer.json                        KEPT+EDITED  adds PSR-4 "GlpiPlugin\\Domainmanager\\": "src/"
├── README.md / CHANGELOG.md / LICENSE   KEPT (README/CHANGELOG updated per phase)
├── .github/, tools/, locales/,          KEPT  (CI matrix bumped to GLPI 11; phpstan/csfixer configs as-is)
│   .php-cs-fixer.php, .phpcs.xml,
│   phpstan.neon, .twig_cs.dist.php
├── setup_template.php, inc/, front/,    REMOVED  (template placeholders superseded by src/;
│   ajax/                                          removal justified in §0.7)
│
├── resources/
│   └── ns-providers.json                NEW  versioned NS→provider registry (§4)
│
├── src/
│   ├── Installer.php                    NEW  install/upgrade/uninstall via Migration (§2, §7)
│   ├── Profile.php                      NEW  Profile tab + rights matrix for the plugin right (§8)
│   ├── SupplierConfig.php               NEW  CommonDBTM ⇒ glpi_plugin_domainmanager_supplierconfigs
│   ├── DomainState.php                  NEW  CommonDBTM ⇒ glpi_plugin_domainmanager_states
│   ├── ImportedRecord.php               NEW  CommonDBTM ⇒ glpi_plugin_domainmanager_records (record
│   │                                         ownership map for idempotent reconciliation + record locks)
│   ├── ImportLock.php                   NEW  CommonDBTM ⇒ glpi_plugin_domainmanager_locks (§0.3)
│   ├── DriverRegistry.php               NEW  static getAvailableDrivers(): ['none','cloudflare','ionos','dinahosting']
│   ├── NsProviderRegistry.php           NEW  loads/validates resources/ns-providers.json, wildcard matcher
│   ├── DriverFactory.php                NEW  driver string → concrete instance (fed by SupplierConfig creds)
│   ├── SupplierTab.php                  NEW  "Domain Manager" tab on Supplier (credentials form)
│   ├── DomainForm.php                   NEW  post_item_form renderer (registrar dropdown, DNS provider
│   │                                         display, status card, Update Now button, lock JS)
│   ├── LockEnforcer.php                 NEW  pre_item_update/-purge/-delete guards (§0.3)
│   ├── HookHandler.php                  NEW  item_add/item_update (persist injected Registrar field),
│   │                                         item_purge cascade cleanup (Domain, Supplier)
│   ├── Cron.php                         NEW  cronInfo() + cronDomainSync(CronTask): batching loop
│   ├── Contract/
│   │   ├── RegistrarDriverInterface.php NEW  fetchLifecycle(string $domain): DomainLifecycle
│   │   ├── DnsPipelineInterface.php     NEW  fetchZoneRecords(string $domain): ZoneRecord[]
│   │   └── ConnectionTestableInterface.php NEW  testConnection(array $credentials): array (§3.5)
│   ├── Dto/
│   │   ├── DomainLifecycle.php          NEW  value object: creation date, expiration date, status enum
│   │   ├── ZoneRecord.php               NEW  value object: type, name, data, ttl, remote id (sanitised)
│   │   ├── ConnectionTestResult.php     NEW  immutable connection-test outcome (§3.5)
│   │   └── ConnectionTestStatus.php     NEW  backed enum (§3.5)
│   ├── Driver/
│   │   ├── CloudflareDriver.php         NEW  full implementation (both interfaces)
│   │   ├── IonosDriver.php              NEW  DNS real, registrar not implemented (§3.9)
│   │   └── DinahostingDriver.php        NEW  full implementation (both interfaces, §3.8)
│   ├── Service/
│   │   ├── SyncEngine.php               NEW  orchestrates the split pipeline for one domain (§5)
│   │   ├── NsResolver.php               NEW  dns_get_record(DNS_NS) wrapper (testable seam)
│   │   ├── RecordReconciler.php         NEW  idempotent create/update/flag-removed into glpi_domainrecords
│   │   ├── SyncLogger.php               NEW  Log::history milestones + PluginLogger (§3.6)
│   │   └── PluginLogger.php             NEW  domainmanager.log / domainmanager-errors.log (§3.6)
│   └── Controller/
│       ├── SyncController.php           NEW  POST /plugins/domainmanager/sync/{domains_id} (§6)
│       └── ConnectionTestController.php NEW  POST /plugins/domainmanager/connectiontest/{suppliers_id} (§3.5, §6.1)
│
└── templates/
    ├── domain_panel.html.twig           NEW  status card + DNS provider row + Update Now + unsupported/
    │                                         unknown warning block (contribution link) + lock JS
    ├── supplier_tab.html.twig           NEW  two-column: driver/credentials form (left) + connection
    │                                         diagnostics panel include (right), JS toggle + Check Connection
    └── connection_test_panel.html.twig  NEW  per-capability status badge/message/http-code/date panel (§3.5)
```

---

## 2. Database schema (all via `Installer` + `Migration`; GLPI FK convention = named fkey column + index, no SQL FOREIGN KEY constraints)

### `glpi_plugin_domainmanager_supplierconfigs`
| Column | Type | Notes |
|---|---|---|
| `id` | int unsigned AUTO_INCREMENT | PK |
| `suppliers_id` | int unsigned NOT NULL DEFAULT 0 | FK → glpi_suppliers, **UNIQUE** |
| `api_driver` | varchar(50) NOT NULL DEFAULT 'none' | one of DriverRegistry values |
| `api_credentials` | text | `GLPIKey`-encrypted JSON (per-driver shape, §6.1) |
| `registrar_test_status` | varchar(255) NULL | `ConnectionTestStatus` value, NULL = never tested (§3.5) |
| `registrar_test_message` | text NULL | last `ConnectionTestResult::$userMessage` (never `rawDetail`) |
| `registrar_test_http_code` | int NULL | last HTTP status code, if any |
| `registrar_test_date` | timestamp NULL | last `ConnectionTestResult::$checkedAt` |
| `dns_test_status` | varchar(255) NULL | idem, DNS capability |
| `dns_test_message` | text NULL | idem |
| `dns_test_http_code` | int NULL | idem |
| `dns_test_date` | timestamp NULL | idem |
| `date_mod` / `date_creation` | timestamp NULL | GLPI convention |

The 8 `*_test_*` columns are added post-creation via `Migration::addField()` (idempotent) in `Installer::addConnectionTestColumns()` — the §0.6 raw-`CREATE TABLE` exception applies only to *initial* table creation, not to this kind of incremental schema change. `*_test_status`/`*_test_message` use `Migration`'s `string`/`text` shorthands (nullable, no default); `*_test_http_code` uses the literal type string `'INT NULL DEFAULT NULL'` since `Migration::addField()`'s `integer` shorthand always hardcodes `NOT NULL` (no way to get a nullable int through the shorthand); `*_test_date` uses the `datetime` shorthand, which — like `date_mod`/`date_creation` elsewhere in this table — actually creates a `TIMESTAMP NULL DEFAULT NULL` column (GLPI's `Migration::fieldFormat()` maps `datetime`/`timestamp` to the same `TIMESTAMP` SQL type).

Indexes: PK, `UNIQUE suppliers_id`, `KEY api_driver`, `KEY date_mod`, `KEY date_creation`.

### `glpi_plugin_domainmanager_states`
| Column | Type | Notes |
|---|---|---|
| `id` | int unsigned AUTO_INCREMENT | PK |
| `domains_id` | int unsigned NOT NULL DEFAULT 0 | FK → glpi_domains, **UNIQUE** |
| `registrar_suppliers_id` | int unsigned NOT NULL DEFAULT 0 | FK → glpi_suppliers (the injected "Registrar" field, §0.1) |
| `dns_suppliers_id` | int unsigned NOT NULL DEFAULT 0 | FK → glpi_suppliers, resolved DNS provider (0 = none resolved) |
| `detected_provider` | varchar(255) NOT NULL DEFAULT '' | registry display name; `''` = never synced, `Unknown` = unmatched |
| `last_sync_date` | timestamp NULL | |
| `registrar_status` | varchar(50) NOT NULL DEFAULT 'never' | `never` \| `ok` \| `error` \| `unconfigured` \| `supplier_inactive` |
| `registrar_message` | text | last human-readable outcome (sanitised, no secrets) |
| `dns_status` | varchar(50) NOT NULL DEFAULT 'never' | `never` \| `ok` \| `error` \| `unsupported` \| `unknown` \| `unconfigured` \| `supplier_inactive` |
| `dns_message` | text | idem |
| `date_mod` / `date_creation` | timestamp NULL | |

Indexes: PK, `UNIQUE domains_id`, `KEY registrar_suppliers_id`, `KEY dns_suppliers_id`, `KEY last_sync_date`, `KEY date_mod`, `KEY date_creation`.

### `glpi_plugin_domainmanager_records` (import ownership map — required for idempotent reconciliation and record-level locks)
| Column | Type | Notes |
|---|---|---|
| `id` | int unsigned AUTO_INCREMENT | PK |
| `domainrecords_id` | int unsigned NOT NULL DEFAULT 0 | FK → glpi_domainrecords, **UNIQUE** |
| `domains_id` | int unsigned NOT NULL DEFAULT 0 | FK → glpi_domains (fast per-domain scan) |
| `remote_id` | varchar(255) NOT NULL DEFAULT '' | provider record id when available |
| `record_hash` | varchar(64) NOT NULL DEFAULT '' | sha256 of type\|name\|data\|ttl (identity when no remote id) |
| `last_seen` | timestamp NULL | last sync that confirmed the record upstream |
| `is_stale` | tinyint NOT NULL DEFAULT 0 | flagged-removed upstream (record kept, marked; see §5.4) |
| `date_mod` / `date_creation` | timestamp NULL | |

Indexes: PK, `UNIQUE domainrecords_id`, `KEY domains_id`, `KEY remote_id`, `KEY record_hash`, `KEY is_stale`.

### `glpi_plugin_domainmanager_locks` (§0.3)
| Column | Type | Notes |
|---|---|---|
| `id` | int unsigned AUTO_INCREMENT | PK |
| `itemtype` | varchar(100) NOT NULL | `Domain` (record locks derive from the records table) |
| `items_id` | int unsigned NOT NULL DEFAULT 0 | |
| `field` | varchar(50) NOT NULL | e.g. `name`, `date_domaincreation`, `date_expiration`, `is_active` |
| `value` | varchar(255) | last value written by sync (audit/debug) |
| `date_mod` / `date_creation` | timestamp NULL | |

Indexes: PK, `UNIQUE unicity(itemtype, items_id, field)`, `KEY items_id`.

**Install also seeds:** Domain Type "Internet Domain" (`glpi_domaintypes`, by-name idempotent check) and ensures `DomainRecordType` rows named A/AAAA/NS/TXT/MX/CNAME exist (they ship with GLPI; re-created only if missing).

---

## 3. Class diagram — drivers & pipeline (text form)

```
┌──────────────────────────────┐        ┌─────────────────────────────────┐
│ «interface»                  │        │ «interface»                     │
│ RegistrarDriverInterface     │        │ DnsPipelineInterface            │
│ + fetchLifecycle(domain)     │        │ + fetchZoneRecords(domain)      │
│     : DomainLifecycle        │        │     : ZoneRecord[]              │
└──────────────△──────────────┘        └───────────────△────────────────┘
               │  implements                            │  implements
     ┌─────────┴──────────────┬──────────────────┬─────┴────────┐
     │ CloudflareDriver (full)│ IonosDriver      │ Dinahosting  │
     │  - token               │ (full: dns +     │ Driver(full) │
     │  - Toolbox::getGuzzle… │  registrar)      │  - user+pass │
     │                        │  - key + secret  │  - Basic Auth│
     └────────────────────────┴──────────────────┴──────────────┘
               △ constructed by
┌──────────────┴───────────────┐     ┌──────────────────────────────┐
│ DriverFactory                │────▶│ DriverRegistry               │
│ + forSupplier(SupplierConfig)│     │ + getAvailableDrivers():     │
│   : object (throws if 'none')│     │   ['none','cloudflare',      │
│ (decrypts creds via GLPIKey) │     │    'ionos','dinahosting']    │
└──────────────────────────────┘     └──────────────────────────────┘

SyncEngine ──uses──▶ NsResolver, NsProviderRegistry, DriverFactory,
                     RecordReconciler, ImportLock, DomainState, SyncLogger
Drivers throw DriverException (message safe to persist; payload detail → logInFile only).
DomainLifecycle / ZoneRecord are immutable DTOs; all API values validated/sanitised at construction.
```

`CloudflareDriver`, `DinahostingDriver` (§3.8) and now `IonosDriver` (§3.9, real registrar/lifecycle implementation added 2026-07-21) all implement **both** interfaces for real (Registrar API + DNS records API) — no driver has an unimplemented pipeline anymore. Credential validation (the old, now-removed flat `testConnection(): void` on each pipeline interface) is superseded by `ConnectionTestableInterface` (§3.5) — a separate, on-demand diagnostic concern, not part of the sync pipeline contracts.

---

## 3.5 Connection diagnostics (Phase 3.5)

On-demand, synchronous verification of a supplier's credentials — independent of the daily sync cron and of the "Update Now" per-domain sync — so a user who just typed in an API token can get an immediate answer instead of waiting for the next scheduled sync (or a domain-form failure) to find out it was wrong.

### 3.5.1 `ConnectionTestableInterface`
```php
interface ConnectionTestableInterface {
    /** @return array<string, ConnectionTestResult> keyed by capability ('registrar'|'dns') */
    public function testConnection(array $credentials): array;
}
```
**Shape chosen:** a single flat method returning one `ConnectionTestResult` per capability, rather than one class/method per capability. Every concrete driver in this plugin is already **one class implementing multiple pipeline interfaces** (`CloudflareDriver` implements both `RegistrarDriverInterface` and `DnsPipelineInterface`; the stubs do too) — a per-capability method on the existing class fits that shape directly, no new per-capability driver classes needed. `$credentials` is passed explicitly (not read from the constructor-set property) so the same driver object can be constructed once by `DriverFactory::createDriver()` and tested against either the persisted, decrypted credentials or **unsaved live form values** — the latter is exactly what `ConnectionTestController` needs (§6.1).

**Per-driver reported capabilities** (`DriverRegistry::getTestableCapabilities()`):
- **`CloudflareDriver` → `['dns']` only.** Cloudflare's registrar API needs a specific domain name (`accounts/{id}/registrar/domains/{domain}`), which isn't known at credential-test time — there is no domain-independent registrar endpoint to probe. The DNS/zone capability, however, is verifiable independently via `user/tokens/verify` (the same call previously used by the old flat test), so only `'dns'` is reported even though the class also implements `RegistrarDriverInterface` for the real sync pipeline.
- **`IonosDriver` → `['registrar', 'dns']`**: `'dns'` is a real check against `GET /zones` (§3.9); `'registrar'`'s *connection-test* probe still returns `unknown_error`/"Not yet implemented for this driver" (`ConnectionTestResult::notImplemented()`) even though `fetchLifecycle()` — the actual sync pipeline — is now a real implementation too (§3.9); adding a real registrar connection-test probe was out of scope for that change, kept visible in the UI rather than hidden either way, so an admin can see the gap instead of just not finding a button for it.
- **`DinahostingDriver` → `['registrar', 'dns']`**, both built from a single real, account-wide auth probe (§3.8) — Dinahosting's auth isn't capability-scoped, so one lightweight API call classifies both.
- **`none` → `[]`** (nothing to test; the Check Connection button isn't rendered).

All capabilities a driver reports are still tested and persisted every time (nothing above changed) — but the Check Connection **UI** only ever shows one combined badge/toast, chosen by `DriverRegistry::getPrimaryTestableCapability()` (§6.1): a display simplification added after real-world testing showed a per-capability breakdown was confusing rather than informative (IONOS's permanent `registrar` "not implemented" stub sitting next to a real, successful `dns` result read as a mixed/ambiguous outcome instead of "this login works").

### 3.5.2 `ConnectionTestResult` DTO (`src/Dto/ConnectionTestResult.php`)
Immutable: `status: ConnectionTestStatus`, `capability: string`, `httpStatusCode: ?int`, `userMessage: string` (safe to persist/display), `rawDetail: string` (log-only, **never** serialized — `toArray()` deliberately omits it), `checkedAt: DateTimeImmutable`. Built via static factories `fromHttpResponse()` (maps 2xx/401/403/404/429/5xx/other to the matching `ConnectionTestStatus`), `fromException()` (classifies network/timeout/unknown from the exception), and `notImplemented()` (stub drivers).

`ConnectionTestStatus` (`src/Dto/ConnectionTestStatus.php`, backed string enum): `Success`, `AuthFailed`, `Forbidden`, `NotFound`, `RateLimited`, `UpstreamError`, `NetworkError`, `Timeout`, `UnknownError`.

### 3.5.3 Persistence & the unsaved-credentials edge case
`SupplierConfig::recordConnectionTestResults(array $resultsByCapability)` persists `status`/`message`/`http_code`/`date` into the 8 columns added in §2 — but only when `$this->getID() > 0`, i.e. **only for an already-saved supplier configuration**. When a user is testing credentials for a brand-new, not-yet-saved supplier config (`SupplierConfig::getForSupplier()` returns `null`), `ConnectionTestController` still runs the test and returns the results to the UI (toast + detail panel), but **does not create a row** just to store a test result — persistence requires an explicit Save first. `SupplierConfig::getConnectionTestSummary()` is the read side, defaulting both capabilities to `status = 'never'` when the row doesn't exist or a capability was never tested.

**This `update()` call was, until 2026-07-21, a critical bug: it silently wiped the stored credentials.** `recordConnectionTestResults()`'s `$input` only ever contains `id` + the `*_test_*` columns — never `api_driver`. `SupplierConfig::prepareInputForUpdate()`, a `CommonDBTM` hook that fires on **every** `update()` call for this itemtype regardless of caller, used to unconditionally re-derive driver/credentials from `$input['api_driver'] ?? ''`; with the key absent, that evaluated to `''`, never matched the real stored driver, and the method read the situation as "switched to no driver," encrypting and storing an empty credentials payload as a side effect of the record-results call. Net effect: the *first* Check Connection (or sync) after a save succeeded correctly against the live API — but persisting that very result destroyed the credentials, so the *next* attempt failed with "API key/secret are not configured." Fixed by having `prepareInputForUpdate()` return `$input` completely untouched whenever `api_driver` isn't a key in it at all (`array_key_exists()`, not `??`) — only a real credentials-form submission (which always includes `api_driver`, per `supplier_tab.html.twig`'s `<select>`) triggers the driver/credential-diffing logic. Verified live: credentials now survive any number of repeated Check Connection calls and syncs; empty-submit-same-driver saves still correctly keep the stored secret, and switching to a genuinely different driver still correctly clears the old one's now-irrelevant credentials.

**Separately, `glpi_plugin_domainmanager_supplierconfigs.api_credentials` is now registered via `Hooks::SECURED_FIELDS`** (`setup.php`, `GLPIKey::getFields()`) — found while investigating the bug above, a narrower but related gap: without this, running GLPI's own `security:change_key` console command (a core-wide encryption-key rotation) would re-encrypt every *registered* secured field with the new key but silently skip this plugin's, permanently orphaning every stored credential under the old, now-discarded key. `HookManager::registerSecureFields()` exists in core but isn't used by any core plugin found on `11.0/bugfixes` — registered the raw `$PLUGIN_HOOKS[Hooks::SECURED_FIELDS]['domainmanager'] = ['table.field', ...]` array directly instead, matching this plugin's own convention for every other hook.

---

## 3.6 Logging — two consolidated log files (replaces the single `plugin_domainmanager` channel)
Every plugin log call (sync engine, cron, NS registry, drivers, connection tests) goes through `src/Service/PluginLogger.php`, which writes to exactly two files via `Toolbox::logInFile()`:
- **`domainmanager.log`** (`PluginLogger::activity()`) — full activity trail: one line per connection-test attempt (success or failure, with capability/status/HTTP code/duration) and per sync milestone (mirrors `SyncLogger::milestone()`'s `Log::history()` entry).
- **`domainmanager-errors.log`** (`PluginLogger::error()`) — errors only: any non-`success` connection-test result, sync-leg failures (`SyncLogger::detail()`), driver HTTP/parsing failures, and NS-registry misconfiguration — each with the full technical detail (`rawDetail`/exception message), defensively redacted of anything that looks like a bearer token or `token=`/`secret=`/`password=`/`key=` fragment before being written (belt-and-suspenders; the primary control is that callers never pass raw secrets into these methods to begin with).

Both files are automatically visible in **Setup → Logs** with no plugin-side registration step — verified fact, §0.8. Both writes are **forced** (`Toolbox::logInFile(..., true)`) so they aren't silently dropped on installs where `use_log_in_files` is unset — verified fact, §0.9.

### 3.6.1 Install-step requirement: the files must exist before an admin can see them
`LogParser::getLogsFilesList()` (§0.8) only lists files that **already exist on disk** — it does a plain `scandir()`, it does not project expected-but-not-yet-created filenames. A freshly installed/activated plugin that has never had a connection test run, and whose daily sync cron has never fired, has **no `domainmanager*.log` files at all** — not "empty," genuinely absent — leaving an admin unable to tell working-but-idle apart from broken. **Fix:** `hook.php` defines `plugin_domainmanager_activate()` (called by GLPI core automatically by naming convention, right before `Plugin::activate()` flips the plugin's state — no registration needed, same mechanism as `plugin_domainmanager_install()`/`_uninstall()`), which writes one deterministic line to `domainmanager.log` ("Domain Manager activated, logging initialized") via `PluginLogger::activity()` and touches an empty `domainmanager-errors.log` via `PluginLogger::ensureErrorLogExists()` (empty is the established GLPI convention for "no errors yet" — e.g. core's own `sql-errors.log`/`mail-errors.log` ship as 0-byte files). Because GLPI auto-deactivates a plugin on every version bump until an admin reactivates it (`src/Plugin.php`'s version-mismatch check), this hook reliably re-fires on every release, not just the very first install.

---

## 3.7 Audit trail via native History (`glpi_logs`)

Beyond the two plugin log files (§3.6, technical/file-based), user-facing configuration actions are also recorded through GLPI's native History mechanism (`Log::history()`, backing `glpi_logs` and the standard "Historical" CommonDBTM tab every admin already knows how to read) — added after live testing showed nothing was landing there.

### 3.7.1 Making the Historical tab's "field" column read "Domain Manager"

Verified against `11.0/bugfixes` (`src/Log.php::getHistoryData()`): when `Log::history()` is called with `linked_action = 0` (the default, used everywhere in this plugin), the rendered "field" column comes from looking up `$changes[0]` (`id_search_option`) in `SearchOption::getOptionsForItemtype($itemtype)` and copying that option's `'name'`. There is **no** "log-label-only, hidden from Search" flag in this GLPI version (checked: only `massiveaction => false` exists, which doesn't hide an option from the Search/sort UI) — so the only native way to get a custom field label is to register a **real** search option, which also makes it a normal, selectable column/filter in that itemtype's Search page. This trade-off was surfaced to and accepted by the plugin owner.

`setup.php` defines `plugin_domainmanager_getAddSearchOptionsNew($itemtype)` (the modern variant of `Plugin::getAddSearchOptions()`, per `src/Plugin.php`), registering one option per itemtype we log against:
- `Supplier` → id `PLUGIN_DOMAINMANAGER_SO_SUPPLIER` (`9401`)
- `Domain` → id `PLUGIN_DOMAINMANAGER_SO_DOMAIN` (`9402`)

Both entries: `'name' => __('Domain Manager', 'domainmanager')`, `'table'` = the itemtype's own table, `'field' => 'name'` (bound to the itemtype's real, already-string `name` column purely so the Search UI never hits a SQL error if someone actually tries to filter/sort by it — it is not meant to be practically useful as a search field), `'massiveaction' => false`. These IDs are **not verified collision-free against any specific live instance** — run `php tools/getsearchoptions.php --type=Supplier` / `--type=Domain` (already in this scaffold, requires DB access) before go-live to confirm no other installed plugin already claims `9401`/`9402` for that itemtype; pick different constants in `setup.php` if it does.

`search-options-registry.json` (repo root) is the TICGAL-wide ledger of every search-option ID any TICGAL plugin registers, keyed by plugin then itemtype — it exists to keep IDs collision-free *between TICGAL's own plugins* (mechanically checkable) and records `verified_collision_free: false` for `9401`/`9402` until the live-instance check above has actually been run. Update it in the same change whenever a new search option is added here or in any sibling TICGAL plugin.

Every `Log::history()` call in the plugin passes the matching constant as `$changes[0]` and leaves `$changes[1]` (old value) empty, putting a short descriptive phrase in `$changes[2]` (new value) — e.g. `"API Token set"`, `"API driver changed from None to Cloudflare"`, `"Registrar supplier cleared"`. Core's generic renderer (`Log.php`, non-`text` datatype branch) then formats this as **`"Change  to <phrase>"`** in the Historical tab, with the "field" column reading **"Domain Manager"** — the plugin's own messages never re-state the plugin name as a text prefix (that would be redundant with the field column).

### 3.7.2 What gets logged

- **Supplier credential/driver changes** (`src/SupplierConfig.php`): `SupplierConfig` has no visible list/tab of its own (it's only ever shown inline on the Supplier's "Domain Manager" tab), so entries are attributed to the **owning `Supplier`** (`Log::history($suppliers_id, Supplier::class, [PLUGIN_DOMAINMANAGER_SO_SUPPLIER, '', $line])`) — visible on that Supplier's own native Historical tab, the same pattern `SyncLogger` already uses to attribute sync outcomes to `Domain` rather than the internal state table. `prepareDriverAndCredentials()` diffs the old vs. new driver + decrypted credentials and queues one line per change (`"API driver set to X"` / `"API driver changed from X to Y"` / `"<Field label> set"` / `"<Field label> updated"` / `"<Field label> cleared"`); lines are flushed in `post_addItem()`/`post_updateItem()`. **Never logs a credential value, only the field's label** — the diff is computed from decrypted values in memory purely to detect *whether* something changed. `post_purgeItem()` logs a single "API configuration removed (was X)" line.
- **Domain registrar-supplier assignment** (`src/HookHandler.php::persistRegistrar()`): logs to `Domain`'s own Historical tab (`Log::history($domains_id, Domain::class, [PLUGIN_DOMAINMANAGER_SO_DOMAIN, '', $message])`) only when the resolved `registrar_suppliers_id` actually changes — `"Registrar supplier set to %s"` / `"...changed from %s to %s"` / `"...cleared"`, using `Dropdown::getDropdownName(Supplier::getTable(), $id)` for the display name.
- **Sync milestones** (`src/Service/SyncLogger.php::milestone()`): every successful registrar/DNS sync leg also logs to `Domain`'s Historical tab through the same `PLUGIN_DOMAINMANAGER_SO_DOMAIN` option, so all Domain-side Domain Manager activity (registrar assignment *and* sync outcomes) is consistently labeled "Domain Manager" in one place.
- Deliberately **not** logged here (scope stayed to user-initiated configuration and sync outcomes, not per-record bookkeeping): per-sync `DomainState` upserts beyond the milestone line already covered above, and `ImportedRecord`/`ImportLock` internal rows (no user-visible list/tab exists for either, so a native History entry there would never be seen).

---

## 3.8 Dinahosting driver: real implementation (was a stub)

`DinahostingDriver` (`src/Driver/DinahostingDriver.php`) was originally shipped in Phase 3 as a pure stub (every method threw `NotImplementedException`) alongside the still-stubbed `IonosDriver`. Investigating a "Test credentials" bug report (toast showing a generic message, `domainmanager-errors.log` apparently staying empty) found no defect in the toast/logging pipeline itself — verified two independent ways (direct controller invocation, and a full real HTTP request through login+CSRF+routing on a disposable test instance) — the pipeline correctly surfaced and logged the stub's fixed "Not yet implemented for this driver" result. The actual gap was that the driver never attempted a real API call to have a real result to report. This section documents its real implementation.

**API**: `https://dinahosting.com/special/api.php`, GET requests, `responseType=json`. The official docs (`en.dinahosting.com/api/documentation`) are a command index without inline example bodies; the exact JSON envelope and field names below were cross-verified against the maintained third-party client `github.com/libdns/dinahosting` (`provider.go`), which is the only source found with byte-accurate wire format.

- **Envelope**: `{trId, responseCode, message, data, errors, command}`. Success = `message === "Success."` OR `responseCode === 1000`. Failure responses put a human message + code in `errors[]` (`{code, message, parameter}`), **always over HTTP 200** — Dinahosting never varies the HTTP status for business-logic outcomes, only for real transport failures.
- **Auth**: the docs list two equivalent options — `AUTH_USER`/`AUTH_PWD` query parameters (used in their own example URLs), or a `Authorization: Basic` header. This driver deliberately uses the **Basic Auth header** (via Guzzle's `auth` client option) rather than query parameters, so credentials never end up in a request URI that could be captured by a proxy/CDN access log — confirmed working against the live API (a deliberately-wrong username/password round-tripped a real `responseCode=2200` "Authentication error." from Dinahosting's own server, correctly classified as `AuthFailed`).
- **`testConnection()`**: Dinahosting authentication is a single account-wide username/password, not scoped per capability, so one lightweight, side-effect-free probe (`System_GetRequestTypes` — confirmed domain-independent from the docs' own example request, which omits a `domain` parameter) classifies both `'registrar'` and `'dns'` from the same outcome. `responseCode` → `ConnectionTestStatus`: `2200` (`AUTH_ERROR_USER`) → `AuthFailed`, `2201` (`AUTH_ERROR_OBJECT`) → `Forbidden`, `2501` (`COMMAND_TIMEOUT`) → `Timeout`, anything else non-success → `UnknownError`. `httpStatusCode` is left `null` for these envelope-classified outcomes (always ~200 regardless of the real result — showing "HTTP 200" next to an "auth failed" badge would be misleading); it's only populated for genuine transport-level 5xx.
- **`fetchLifecycle()`**: `Domain_GetExpirationDate` / `Domain_GetRegistrationDate` (each confirmed: single `domain` parameter, returns a string). **Known gap, flagged rather than guessed**: no documented response shape for a registrar-hold/suspended status command (`Domain_Status_Get`) was found anywhere searched, so `LifecycleStatus` here only distinguishes `Ok`/`Expired` (via the expiration date) — never `Suspended`. Revisit if Dinahosting's docs (or a support ticket) ever surface that command's real shape.
  - **`Domain_GetRegistrationDate`'s exact semantics are unconfirmed** (checked directly against the live doc page, not from memory: the entire description is "Returns registration date of domain." — no elaboration). Two readings are possible: (a) the domain's real/original registry creation date (a WHOIS/RDAP-style "Creation Date", invariant across registrar transfers per ICANN's Transfer Policy), or (b) the date the domain was added to/transferred into this Dinahosting account specifically. **Assumed to be (a)**, based on the command taking only `domain` (no account-scoping hint) and Dinahosting modeling inbound transfers as their own separate command family (`Domain_CheckForTransfer`, `Domain_Transfer_GetStatus`, `Billing_Transfer_Domain`) rather than folding "transfer date" into this general domain-info command — but this is inference from API shape and EPP/registry convention, not a confirmed fact. **Only verifiable against a domain known to have been transferred in from another registrar** (compare this command's returned date to that domain's actual WHOIS/RDAP creation date) — genuinely hard to test opportunistically since transferring a domain isn't a routine, frequent event; revisit whenever such a domain becomes available to check.
- **`fetchZoneRecords()`**: `Domain_Zone_GetAll`. Field names for **A/AAAA (`ip`), CNAME (`destinationHostname`), TXT (`text`)** are confirmed against the reference client. **Known gap**: that client only ever *writes* A/AAAA/TXT/CNAME, so it never modeled MX/NS fields specifically — `extractContent()` falls back to the same generic field set the reference client uses for record types it doesn't recognize either. MX priority ordering in particular is unconfirmed; verify against a real account with real MX/NS records before relying on it.
- Both pipeline methods route API errors through a shared `request()` helper mirroring `CloudflareDriver::request()`'s shape (`DriverException` with a safe message, technical detail to `PluginLogger::error()` — including the object-not-found code `2303` mapped to a clear "domain not managed by this account" message).
- `PluginLogger::redact()` (§3.6) was widened to also catch Dinahosting's non-standard `AUTH_PWD`/`pwd=` credential naming (the existing pattern only matched the literal word "password") — defense in depth, since this driver's own code never logs a raw request URI in the first place.

---

## 3.9 IONOS driver: both DNS and registrar/lifecycle real (registrar added 2026-07-21)

Requested as the same treatment as §3.8. `IonosDriver` implements two entirely separate IONOS Hosting/Developer products (`developer.hosting.ionos.com`) — **not** to be confused with IONOS Cloud's unrelated IAM federation-domains API (`iam.ionos.com`), which only verifies domain ownership for Cloud-account SSO and has nothing to do with this plugin.

**DNS (`fetchZoneRecords()`, `testConnection()`'s `'dns'` capability) — fully real**, base `https://api.hosting.ionos.com/dns/v1/`. IONOS's own docs portal (`developer.hosting.ionos.com/docs/dns`) is a JS-rendered Angular SPA with no inline example bodies and no scrapable spec, but the wire format was cross-verified against the maintained community client `github.com/libdns/ionos` (`GET /zones` → a plain JSON array of `{name, id, type}`; `GET /zones/{id}` → `{id, name, type, records: [{id, name, rootName, type, content, changeDate, ttl, prio, disabled}]}`; TXT `content` is returned double-quoted and must be unquoted; MX priority is `prio`), **and** by directly probing the live API with no/bad credentials, which confirmed:
- Auth header: `X-API-Key: <key_prefix>.<key_secret>` (matches `DriverRegistry`'s existing `key`/`secret` credential fields).
- Error shape is a simple `{"message": "..."}` with real, varying HTTP status codes (400 "Invalid API key format.", 401 "Missing or invalid API key."/"Missing or invalid credentials.") — unlike Dinahosting, IONOS behaves like a normal REST API here, so `ConnectionTestResult::fromHttpResponse()` is reused directly (same as `CloudflareDriver`), no custom envelope classification needed.
- The API has no filter-by-name query parameter for zones (confirmed: the reference client also fetches the full zone list and matches client-side), so `findZoneId()` does the same, case-insensitively.

**Registrar/lifecycle (`fetchLifecycle()`) — now a real implementation**, base `https://api.hosting.ionos.com/domains/v1/`. The docs portal page (`/docs/domains`) is the same JS-only shell as before with nothing scrapable — but this time the portal's own actual OpenAPI 3.0.2 spec, a static asset it loads client-side, was located (via the Wayback Machine's CDX index enumerating `developer.hosting.ionos.com`'s known asset paths) and fetched directly: `https://developer.hosting.ionos.com/assets/kms-swagger-specs/domains.yaml`, **spec version 1.0.4**, confirmed live and readable on 2026-07-21 (unlike the smaller `assets/swagger-specs/domains.yaml`, which 200s but is just the Angular shell). Confirmed facts, read directly from that spec plus live probing:
- Base URL `https://api.hosting.ionos.com/domains/v1` (spec `servers.url` + its `/v1/domainitems...` paths), same auth gateway/header as DNS (`X-Api-Key`; live-probed with no/bad credentials — identical `{"message":"Missing or invalid credentials."}"`/`{"message":"Missing or invalid API key."}"` responses as the DNS host).
- The spec's prose intro also says "every endpoint uses the `X-Tenant-Id` header", but that header is **not** part of the spec's formal `securitySchemes`/`security` section, and this plugin's credential shape (`key`/`secret`) has no field to source one from — sent without it. Flagged as unconfirmed rather than guessed; if a real reseller/multi-tenant account hits an auth error this can't otherwise explain, a `tenant_id` credential field may need adding.
- `GET /v1/domainitems?name={substring}&limit&offset` lists domains (`name` is a *substring* filter, minimum 3 characters — no exact-match-by-full-name filter exists), returning the `domainSmall` shape; `findDomainId()` fetches candidate pages and matches `name` exactly, case-insensitively (same pattern as `findZoneId()` on the DNS side), paginated up to `DOMAINS_MAX_PAGES` × `DOMAINS_PAGE_SIZE` (10 × 100) as a sane upper bound.
- `GET /v1/domainitems/{domainId}?includeDomainStatus=true` returns the `domainLarge` shape: `expirationDate` (ISO 8601) and, when `includeDomainStatus` is set, a `status` object (`itemStatus`: `provisioningStatus.type` ∈ `REGISTRATION_IN_PROGRESS`/`ACTIVE`/`EXPIRING`, optional `complianceStatus` for holds like `NOMINET_LOCKED`/`EMAIL_VERIFICATION_RUNNING`).
- **No registration/creation date field exists anywhere in the spec** — confirmed absent, not merely undocumented (grepped the full 3730-line spec for `registrationDate`/`creationDate`/`createdAt`/equivalents: zero matches). `fetchLifecycle()` always returns a null `registrationDate` for this driver; `DomainLifecycle` already supports that.
- Error envelope is genuinely two-shaped: a gateway-level auth rejection (malformed/absent key, request never reaches the Domains backend) returns the same single `{"message": "..."}` object as DNS; an application-level error from the Domains backend itself (confirmed via the spec's own `error` schema/examples) returns a JSON **array** of `{"code": "...", "message": "..."}` objects instead. `describeDomainsApiError()` handles both; `requestDomainsApi()` is otherwise the Domains-API twin of `request()` (DNS), sharing credential/header construction via `buildClient()`.
- `LifecycleStatus` gained a new `Pending` case (`REGISTRATION_IN_PROGRESS` → mid registration/transfer, not yet live) — no existing case (`Ok`/`Suspended`/`Expired`) fit without a lossy guess; `mapLifecycleStatus()` documents the full mapping including how `complianceStatus` (any hold) maps to `Suspended` and `EXPIRING` maps to `Expired`. This only affects the human-readable sync message text — `SyncEngine`'s `is_active` derivation is `status === Ok ? 1 : 0`, so every non-Ok case (including the new one) already collapses to "inactive" correctly with no code changes needed there.

`testConnection()`'s `'registrar'` capability still reports `unknown_error`/"Not yet implemented for this driver" — adding a real registrar connection-test probe was explicitly out of scope for this change (only the two sync-pipeline data-fetching methods were requested), not blocked by missing documentation anymore. Revisit as a separate, small follow-up if wanted: a real probe would just need a lightweight account-level call (e.g. `GET /v1/domainitems?limit=1`), the same pattern `probeZonesList()`/`probeTokenVerify()` already use for the other drivers.

`NotImplementedException` (`src/Exception/NotImplementedException.php`) was deleted: it existed solely for this now-resolved stub and had no other caller in the codebase.

---

## 4. NS provider registry — `resources/ns-providers.json`

Versioned in-repo, contributor-maintained via PRs (no DB table, not user-editable). Loaded, validated and cached by `NsProviderRegistry`. Format:

```json
{
  "$schema-note": "Matching: case-insensitive fnmatch-style wildcards against each NS host (trailing dot stripped). First matching entry wins; entries are checked in file order.",
  "providers": [
    {
      "name": "Cloudflare",
      "patterns": ["*.ns.cloudflare.com"],
      "driver": "cloudflare",
      "source": "https://developers.cloudflare.com/dns/zone-setups/full-setup/setup/"
    },
    {
      "name": "IONOS",
      "patterns": ["*.ui-dns.de", "*.ui-dns.com", "*.ui-dns.org", "*.ui-dns.biz"],
      "driver": "ionos",
      "source": "…"
    },
    {
      "name": "Dinahosting",
      "patterns": ["ns1.dinahosting.com", "ns2.dinahosting.com", "*.dinahosting.com"],
      "driver": "dinahosting",
      "source": "…"
    },
    {
      "name": "AWS Route 53",
      "patterns": ["*.awsdns-*.org", "*.awsdns-*.com", "*.awsdns-*.net", "*.awsdns-*.co.uk"],
      "source": "…  (no driver ⇒ detected-but-unsupported)"
    }
  ]
}
```

- `driver` optional; present only for `cloudflare` / `ionos` / `dinahosting`. Entries without it render the *"API integration for [Name] is not currently supported."* warning + contribution link.
- No pattern match at all ⇒ provider **"Unknown"** + same contribution link (`PLUGIN_DOMAINMANAGER_REPOSITORY_URL` constant from `setup.php`).
- Real-world patterns for the three supported providers are researched with sources before hardcoding (Phase 2); the popular-provider sweep (Route 53, Google Cloud DNS, Azure DNS, GoDaddy, OVHcloud, Gandi, Namecheap, DigitalOcean, Hetzner, …) is Phase 5.

**Resolution chain at sync time:** live `dns_get_record($fqdn, DNS_NS)` → match hosts against registry (first match) → if entry has a `driver`, find supplier whose `supplierconfigs.api_driver` equals it (deterministic: lowest `suppliers_id` wins if several; state records the resolved one) → run DNS pipeline with that supplier's credentials.

---

## 5. Sync sequence (text diagram)

```
trigger (cron batch loop | SyncController "Update Now")
   │
   ▼
SyncEngine::sync(Domain $domain)
   │ 1. NsResolver: dns_get_record(name, DNS_NS) ──── failure ⇒ dns_status per §5.3, continue registrar leg
   │ 2. NsProviderRegistry::match(ns_hosts)
   │       ├─ entry+driver  → find supplier w/ that api_driver → dns pipeline candidate
   │       ├─ entry, no driver → detected_provider = name, dns_status = 'unsupported'
   │       └─ no entry        → detected_provider = 'Unknown', dns_status = 'unknown'
   │
   │ 3. REGISTRAR LEG (isolated try/catch — never aborts the DNS leg)
   │       registrar supplier = live Infocom.suppliers_id (§0.1)
   │       ├─ resolved supplier's Supplier.is_active = 0 → registrar_status = 'supplier_inactive',
   │       │    SupplierConfig::isSupplierActive() short-circuits BEFORE any driver/credentials
   │       │    use — no network call, no decrypt → SyncLogger::skip() (activity log only, §addendum)
   │       ├─ none/driver 'none'/no creds → registrar_status = 'unconfigured'
   │       └─ DriverFactory→RegistrarDriverInterface::fetchLifecycle()
   │             ├─ ok  → map DTO → Domain->update([date_domaincreation, date_expiration,
   │             │        is_active(1=OK / 0=Suspended|Expired)])  [+ name confirmation]
   │             │        → refresh ImportLock rows (name, date_domaincreation,
   │             │          date_expiration, is_active) → Log::history changes
   │             │        → registrar_status = 'ok'
   │             └─ DriverException → registrar_status = 'error', message persisted,
   │                                  payload → PluginLogger::error() (domainmanager-errors.log, §3.6)
   │
   │ 4. DNS LEG (isolated try/catch — never aborts registrar results)
   │       resolved dns supplier = the SupplierConfig matched to the detected driver
   │       ├─ resolved supplier's Supplier.is_active = 0 → dns_status = 'supplier_inactive',
   │       │    same short-circuit/skip-log as the registrar leg above — no API call attempted
   │       └─ DnsPipelineInterface::fetchZoneRecords() (A, AAAA, NS, TXT, MX, CNAME only)
   │             ├─ ok → RecordReconciler::reconcile(domain, ZoneRecord[]):
   │             │         match by remote_id, else record_hash
   │             │         • new upstream        → DomainRecord->add() + ownership row
   │             │         • changed upstream    → DomainRecord->update() + refresh hash
   │             │         • gone upstream       → is_stale = 1 (comment annotated; §5.4)
   │             │         • reappeared          → is_stale = 0
   │             │       never duplicates on re-sync; only plugin-owned rows touched;
   │             │       manual records untouched → dns_status = 'ok'
   │             └─ DriverException → dns_status = 'error' (+ message/logfile)
   │
   │ 5. DomainState upsert: detected_provider, dns_suppliers_id, statuses, messages,
   │                        last_sync_date = now
   ▼
result object {registrar_status, dns_status, messages} → controller JSON / cron counters
```

- **§5.3 NS lookup failure:** `dns_status = 'error'` with message "NS lookup failed"; registrar leg still runs (it depends only on the registrar supplier, not on NS detection).
- **§5.4 flag-removed:** plugin never hard-deletes native records; upstream-vanished records are flagged `is_stale` and shown struck/annotated (soft `is_deleted` is not used to avoid trash-bin noise; can be revisited).
- **§5.5 inactive supplier (addendum "Skip Inactive Suppliers"):** `SupplierConfig::isSupplierActive(int $suppliers_id)` — one shared helper, checked at the top of both `syncRegistrarLeg()`/`syncDnsLeg()`, in `ConnectionTestController` before running a manual test, and implicitly covered in the mass-sync massive action and cron (both just call `SyncEngine::sync()`). An inactive supplier's credentials are never decrypted or sent over the network for this reason — the leg returns `STATUS_SUPPLIER_INACTIVE` immediately, distinct from `unconfigured` (no supplier resolved at all) and `error` (a call was attempted and failed). Logged via `SyncLogger::skip()` — `domainmanager.log` only, never `-errors.log` (not a failure) and never `Log::history()` (a pure no-op skip repeating on every cron run isn't a "milestone" worth the domain's Historical tab, unlike a real sync outcome). UI: the Supplier's own Check Connection button is disabled with a tooltip and the connection diagnostics panel shows an inline notice instead of stale/blank badges (`SupplierTab`/`supplier_tab.html.twig`/`connection_test_panel.html.twig`); the Domain form's Registrar/DNS sync badges show "Supplier inactive" (`DomainForm`'s status maps) instead of a generic error or "Not configured"; the "Sync now" massive action reports `Skipped %s — resolved supplier is inactive` per item rather than counting it as a KO sync error (`MassiveActionHandler`).
- All API payloads validated before persisting: type whitelist, hostname/content length caps, TTL int-range, UTF-8 scrub; unknown record types skipped (read-only scope: A, AAAA, NS, TXT, MX, CNAME).
- Cron loops **active, non-deleted, non-template** domains in batches (default 20/run, `CronTask` param-tunable) with per-domain try/catch isolation; each processed domain increments the task counter.

---

## 6. Endpoints, hooks & cron registration map

### 6.1 Supplier tab (credentials)
`SupplierTab` registered via `Plugin::registerClass(SupplierTab::class, ['addtabon' => Supplier::class])`. Tab "Domain Manager" renders `supplier_tab.html.twig`: driver `<select>` from `DriverRegistry::getAvailableDrivers()` + per-driver fields toggled by small inline JS:
- cloudflare → API Token
- ionos → API Key + Secret
- dinahosting → Username + Password

Save posts to the standard tab form path handled by `SupplierConfig` (CommonDBTM add/update); payload assembled to JSON and encrypted with `GLPIKey::encrypt()`. Existing secrets are never echoed back (placeholder "●●● saved"); empty submit keeps the stored secret. Access gated by supplier rights: tab visible with READ on the supplier, save with entity-aware UPDATE on it (§8).

**Driver exclusivity.** Each driver may only ever be assigned to one supplier at a time — the ambiguity of "which supplier's credentials should the sync engine use for this driver" is prevented structurally, not just discouraged. `SupplierConfig::getDriversClaimedByOtherSuppliers(int $suppliers_id)` queries `glpi_plugin_domainmanager_supplierconfigs` for any row with a real (non-`none`) `api_driver` whose `suppliers_id` differs from the one passed in; `SupplierTab::getDriverOptions()` uses it to exclude those drivers from the rendered `<select>` while always keeping `none` and the supplier's own current driver available. The same check (`SupplierConfig::isDriverClaimedByOtherSupplier()`) is re-run server-side inside `prepareDriverAndCredentials()` (both add and update paths) so a direct POST bypassing the dropdown is rejected with a clear error, not just silently trusted. The dropdown is sorted alphabetically by display label with `none` pinned first (`getDriverOptions()`), rather than `DriverRegistry`'s fixed declaration order. There is currently no bulk-edit/massive-action path for this field — `SupplierConfig` has no search/list page, and the existing "Sync now" massive action (§9 Phase 5.5) only syncs domains — so there is nothing else to guard today; a future bulk path for this field must reuse the same two `SupplierConfig` methods and skip colliding items individually rather than failing or overwriting.

The tab is laid out in two columns: the credentials form (left) and an always-rendered **connection diagnostics panel** (right, `connection_test_panel.html.twig`, §3.5) showing a single color-coded status badge, message, HTTP code and checked-at timestamp — defaulting to a gray "Not tested yet" badge. A **"Check Connection"** button (shown whenever the selected driver isn't `none`, gated on the same entity-aware supplier UPDATE) POSTs the **current live form values** (driver + credential inputs, not necessarily saved) to `/plugins/domainmanager/connectiontest/{suppliers_id}` (`ConnectionTestController`, supplier UPDATE + core CSRF) — this lets a user verify a token before ever hitting Save. Empty secret fields fall back to the already-stored value for the same driver, mirroring the save form's "empty submit keeps the stored secret" semantics. The controller runs `DriverFactory::createDriver()` → `ConnectionTestableInterface::testConnection()`, which still returns one `ConnectionTestResult` per capability the driver supports and persists all of them to the supplier's `supplierconfigs` row as before (only if it already exists, §3.5.3) — but the UI itself only ever surfaces **one** combined badge/toast, from `DriverRegistry::getPrimaryTestableCapability()` (§3.5): 'dns' when testable (a real, meaningful login probe for every current driver), else the first remaining capability. This is a display simplification, not a pipeline change — from a user's point of view "Check Connection" is fundamentally one login test, so a per-capability breakdown (e.g. IONOS's `registrar` slot, which is permanently a "not implemented" stub) added confusing noise rather than information. This supersedes the earlier flat, stored-credentials-only "Test credentials" button/`SupplierConfigTestController` design, which never shipped.

**Inactive supplier (§5.5).** `SupplierTab` passes `supplier_active` (the Supplier's own native `is_active` field) into both templates. When false: the Check Connection button renders `disabled` with a tooltip explaining why (`supplier_tab.html.twig`), and `connection_test_panel.html.twig` replaces its entire body with a single inline notice instead of the normal badge/message/timestamp — never stale or blank diagnostics. `ConnectionTestController` re-checks `$supplier->fields['is_active']` itself (409 response) so a direct POST bypassing the disabled button is still rejected before any credentials are decrypted or any request sent. The credentials form itself stays editable either way — an admin can still prepare/update credentials on a currently-inactive supplier before reactivating it; only the outbound test call is gated.

### 6.2 Domain form injection
| Hook | Itemtype | Handler | Purpose |
|---|---|---|---|
| `Hooks::POST_ITEM_FORM` | `Domain` | `DomainForm::inject()` | Renders `domain_panel.html.twig` inside the form: a ribbon-banner header (§6.5) with **Update Now** in it, then a one-row native `<table>` — columns Registrar, DNS/NS Provider, Registrar sync, DNS sync, Last sync (a one-row view of the same table shape as the Supplier tab's "Domains" list, §6.5). Registrar/DNS Provider are hyperlinked to the resolved Supplier's own Domain Manager tab when one exists (else plain text/muted fallback, with a link to the Infocom tab for Registrar, §0.1). Below the table: per-leg detail messages and the unsupported/unknown warning, plus the lock-disabling JS. Rendered only with `domain` READ. |
| `Hooks::ITEM_ADD` / `ITEM_UPDATE` | `Infocom` | `HookHandler::infocomSaved()` | Mirror `suppliers_id` into `states.registrar_suppliers_id` whenever the Infocom row belongs to a `Domain` (§0.1) — the single source of truth is Infocom's own native field. |
| `Hooks::PRE_ITEM_UPDATE` | `Domain` | `LockEnforcer` | Strip locked fields w/o unlock right (§0.3). |
| `Hooks::PRE_ITEM_UPDATE`, `PRE_ITEM_DELETE`, `PRE_ITEM_PURGE` | `DomainRecord` | `LockEnforcer` | Block edits/removal of plugin-owned records w/o unlock right. |
| `Hooks::ITEM_PURGE` | `Domain` | `HookHandler` | Cascade-delete state row, ownership rows, locks. |
| `Hooks::ITEM_PURGE` | `Supplier` | `HookHandler` | Delete its `supplierconfigs` row; null out matching `registrar_suppliers_id`/`dns_suppliers_id`. |
| `Hooks::ITEM_PURGE` | `DomainRecord` | `HookHandler` | Delete its ownership row (when purged by a right-holder). |

### 6.3 Update Now endpoint
`src/Controller/SyncController.php` — `#[Route('/sync/{domains_id}', name: 'domainmanager_sync', methods: ['POST'], requirements: ['domains_id' => '\d+'])]` ⇒ URL `/plugins/domainmanager/sync/{id}`, route `@domainmanager:domainmanager_sync`.
- Rights: `Session::haveRight('domain', UPDATE)` + `$domain->can($id, UPDATE)` (entity-aware); 403 JSON otherwise.
- CSRF: automatic via core `CheckCsrfListener`; the button JS sends `X-Glpi-Csrf-Token` from the page meta tag (`X-Requested-With: XMLHttpRequest`).
- Runs `SyncEngine::sync()` synchronously, returns `{registrar_status, dns_status, messages, last_sync_date}` JSON; the panel refreshes badges in place.

### 6.4 Cron
`Installer` registers: `CronTask::register(GlpiPlugin\Domainmanager\Cron::class, 'DomainSync', DAY_TIMESTAMP, ['state' => CronTask::STATE_WAITING, 'hourmin' => 23, 'hourmax' => 24, 'param' => 20, 'logs_lifetime' => 30, 'comment' => …])` — visible/tunable in *Setup → Automatic actions* (frequency, window, batch size all admin-changeable). Dispatch: `Cron::cronDomainSync(CronTask $task)` + `Cron::cronInfo()`. Uninstall: `CronTask::unregister('domainmanager')`.

---

## 6.5 UI Design Conventions

Every plugin-injected UI surface (both Supplier tab panels, the Domain form panel) follows a pattern found by grepping GLPI core directly — not approximated from a screenshot — so this plugin inherits any future core styling change automatically instead of maintaining a parallel hand-copied style. This section is the single source of truth for "how a Domain Manager panel should look"; before this section existed, each panel drifted independently (a boxed/disabled-input look here, a plain card there) because there was nowhere this was written down.

### Ribbon-banner panel header — mandatory container for every injected panel
Confirmed directly in GLPI core's own `templates/components/form/inventory_info.html.twig` and `templates/components/form/header_content.html.twig` (grepped for the literal `ribbon` class, not guessed from a rendered screenshot):
```html
<div class="card m-n2 border-0 shadow-none">
    <div class="card-header">
        <div class="ribbon ribbon-bookmark ribbon-top ribbon-start bg-blue s-1">
            <i class="ti <icon> fa-2x"></i>
        </div>
        <h4 class="card-title ps-5">{{ __('Panel title') }}</h4>
        {# an optional header-level action button goes here, e.g. class="btn btn-sm btn-primary ms-auto" #}
    </div>
    <div class="card-body ...">...</div>
</div>
```
`ribbon`/`ribbon-bookmark`/`ribbon-top`/`ribbon-start`/`bg-blue`/`s-1` are **Tabler** CSS classes (GLPI 11's UI framework, `public/lib/tabler.css`, already loaded on every page) — zero custom CSS needed, and this is why reusing them means the plugin inherits any future Tabler/GLPI theme update automatically. Every panel this plugin injects uses this exact header, unmodified: `supplier_domains_list.html.twig`, the credentials-form card and `connection_test_panel.html.twig` (both in `supplier_tab.html.twig`), and `domain_panel.html.twig`.

### Body content: native table vs. native field/value grid vs. a real form
Core has no single template combining ribbon header + table — pick the body shape based on what the content actually is:
- **Multi-row data** (this plugin's "Domains" list, §9 Phase 5.5): a real `<table class="table table-sm mb-0">` inside `<div class="card-body p-0">`, one `<th>` per column — the same convention GLPI uses for any itemtype listing.
- **Single-record read-only summary**: core's own field/value grid, from `inventory_info.html.twig`'s body — `<div class="card-body row"><div class="mb-3 col-12 col-sm-4"><label class="form-label">Label</label><span>Value</span></div>...</div>`. The Domain form's "Domain Manager" panel deliberately does **not** use this grid — it's rendered as a one-row **table** instead, matching the "Domains" list's exact column shape (Registrar, DNS/NS Provider, Registrar sync, DNS sync, Last sync) rather than the generic single-record grid, because conceptually it *is* a one-row view of that same table. A deliberate, explicit exception — not a contradiction of the rule above.
- **A real, editable `<form>`** (the Supplier tab's credentials form: driver select + credential inputs + Save/Check Connection): keeps its own field-row markup (`<div class="mb-3 row"><label class="col-4 col-form-label">...</label><div class="col-8">...</div></div>`). GLPI's ribbon convention only prescribes the *header*; it doesn't mandate a body layout for editable forms, and no core ribbon-panel example was found wrapping an editable form — only the header changed here.
- **A single combined status indicator** ("Connection diagnostics", §3.5): stays exactly that — one badge/message, not a table or grid. Deliberately asymmetric with the Domain form panel: a Supplier's own connection test is fundamentally one login probe (one thing to report), while a Domain's Registrar and DNS provider are independently-varying, genuinely-different things (different suppliers, different status vocabularies) — collapsing those would lose real information, collapsing the connection test wouldn't.

### Shared badge/pill component
One status-badge rendering convention, reused by every panel rather than redefined per panel — a `status_classes`/`status_labels` map of `{status_key: 'text-bg-<color>'}` / `{status_key: 'Human label'}`, rendered as:
```twig
<span class="badge {{ status_classes[status]|default('text-bg-secondary') }}">{{ status_labels[status]|default(status) }}</span>
```
Every panel that shows a status — Connection diagnostics' combined indicator, the Domains list's Registrar/DNS columns, the Domain form panel's Registrar sync/DNS sync columns — builds its badge this exact way. Two independent status *vocabularies* exist underneath (`ConnectionTestStatus`, §3.5.2, for live API probes; `DomainState`'s status strings, §2, for sync outcomes) — each panel picks whichever one it actually reflects — but the *rendering* convention above is shared by both; never invent a third color/label scheme for a new status indicator.

### Twig/rendering rules (consolidated here from scattered mentions elsewhere in this doc/session)
- Templates live under `templates/`, rendered via `Glpi\Application\View\TemplateRenderer::getInstance()->display('@domainmanager/name.html.twig', [...])` — never `echo`'d raw HTML from a hook callback.
- **`{{ path('@domainmanager:route_name') }}` cannot resolve route names inside a Twig template** — confirmed directly against `Glpi\Application\View\Extension\RoutingExtension::path()` on `11.0/bugfixes`: it only takes its router-first branch when a router was injected, and every Twig environment reachable from a plugin template (`TemplateRenderer::getInstance()`, and `Glpi\Controller\AbstractController::render()` which delegates to the same extension) constructs it with none. The fix is `{{ path('/plugins/domainmanager/literal/path') }}` — a **literal absolute path** — which still correctly gets `$CFG_GLPI['root_doc']` prepended via `Html::getPrefixedUrl()` (so it works on subdirectory installs too); it just never resolves a route *name*. This is exactly the bug behind the "Check Connection"/"Update Now" 404 (CHANGELOG, 2026-07-21) — the original fix used a raw string concatenation with no `path()` call at all, which happened to produce the right URL only because this dev environment is installed at the site root; `supplier_tab.html.twig` and `domain_panel.html.twig` now both correctly wrap the literal path in `path()`.
- Twig auto-escapes by default; only reach for `htmlescape()`/`jsescape()` when emitting HTML/JS outside Twig entirely. Wrap every user-facing string in `__('...', 'domainmanager')`.
- Deep-linking to a specific tab on another item (e.g. a Supplier's own Domain Manager tab, linked from the Domain form) uses GLPI's `forcetab=<Itemtype>$<tab_index>` query convention appended to `getLinkURL()`/`getFormURLWithID()` — verified empirically against this plugin's actual tab identifiers (`Infocom$1`, `GlpiPlugin\Domainmanager\SupplierTab$1`) by inspecting real rendered tab-list HTML, not assumed from a naming pattern.

**Rule for future work:** any new Domain Manager UI surface reuses the ribbon header, the shared badge component, and the table-vs-grid-vs-form choice above — don't introduce a new card/box style. If GLPI core's own convention for a given shape isn't yet confirmed, grep core first (`templates/components/form/`, `css/includes/components/`) before approximating from a screenshot.

---

## 7. Install / uninstall (`src/Installer.php`, driven from `hook.php`)

**Install (idempotent, upgrade-aware via `Migration(PLUGIN_DOMAINMANAGER_VERSION)`):**
1. Create the four tables (§2) if missing; `Migration` field/key helpers for future upgrades.
2. Seed Domain Type "Internet Domain" (by-name check).
3. Ensure the six `DomainRecordType` names exist.
4. `Migration::addRight('domainmanager:unlock_imported', UNLOCK_RIGHT, ['config' => UPDATE])` — granted by default to profiles holding config UPDATE.
5. Register the cron task (§6.4).
6. `$migration->executeMigration()`.

**Uninstall (zero residue):**
1. `Migration::dropTable()` × 4 plugin tables.
2. `CronTask::unregister('domainmanager')` (+ its `CronTaskLog` rows go with it).
3. `ProfileRight::deleteProfileRights(['domainmanager:unlock_imported'])`.
4. Delete plugin `DisplayPreference` rows for plugin itemtypes (defensive even though none are registered by default).
5. Native data is **left intact by design**: domains, domain records, the seeded Domain Type and record types remain (they are the user's inventory). Plugin lock rows live in plugin tables, so dropping them removes every locking artifact. *(The brief's "locked-field entries created by the plugin" are exactly these rows — nothing is ever written to `glpi_lockedfields`, per §0.3.)*

---

## 8. Rights model

| Action | Required right | Enforced at |
|---|---|---|
| See plugin panel/status card on a Domain | native `domain` READ | `DomainForm::inject()` |
| Trigger "Update Now" | native `domain` UPDATE (entity-aware `can()`) | `SyncController` |
| Set the Registrar field on a Domain | native Infocom UPDATE on that Domain (core's own Infocom tab gating) — no plugin-specific check, since the plugin no longer owns this field (§0.1) | `HookHandler::infocomSaved()` mirrors whatever core already let through |
| See the "Domains" list on a Supplier's tab | native supplier READ (tab-level, unchanged) **and** native `domain` READ | `SupplierTab::showForSupplier()` returns an empty list rather than rendering if `domain` READ is missing (§9 Phase 5.5) |
| Configure supplier credentials (tab visible with supplier READ, save with supplier UPDATE) | native supplier rights (`contact_enterprise`), entity-aware `can()` on the target supplier | `SupplierTab` + `SupplierConfig::can*`/`can*Item` (changed 2026-07-19 from `config` READ/UPDATE — the profile UI exposes no usable config READ, and supplier API access is part of managing the supplier) |
| Test supplier credentials ("Check Connection" button) | native supplier UPDATE (entity-aware `can()`) | `ConnectionTestController` |
| Edit/unlock sync-locked Domain fields | **`domainmanager:unlock_imported`** (single bit, value 1) | `LockEnforcer` (server-side, every entry point incl. massive actions & API since hooks fire on model update) |
| Edit/delete/purge plugin-imported DomainRecords | **`domainmanager:unlock_imported`** (+ native `managed_domainrecordtypes` gate still applies, §0.4) | `LockEnforcer` |
| Grant the plugin right | native `profile` UPDATE | `src/Profile.php` tab (`displayRightsChoiceMatrix` + `ProfileRight`) |

Right registered per-profile via `Migration::addRight` at install and manageable afterwards in a "Domain Manager" section of the Profile form (dedicated Profile tab). Right name is the literal string `domainmanager:unlock_imported` (a `glpi_profilerights.name` value; GLPI accepts arbitrary strings — verified `varchar(255)` + `Session::haveRight` bitmask check).

---

## 9. Phase plan (unchanged from the brief)

1. **Phase 1** — `setup.php`, `hook.php`, `Installer`, `Profile` right, cron shell: installs/uninstalls cleanly (UI + `bin/console glpi:plugin:install/uninstall`).
2. **Phase 2** — itemtypes (`SupplierConfig`, `DomainState`, `ImportedRecord`, `ImportLock`), Supplier tab + encryption, `ns-providers.json` (3 supported providers, sourced) + `NsProviderRegistry`.
3. **Phase 3** — contracts, DTOs, `DriverFactory`, `CloudflareDriver` (full), IONOS/Dinahosting stubs, `SyncEngine`, `RecordReconciler`, `LockEnforcer`, history/logging.
3.5. **Phase 3.5** — on-demand connection diagnostics: `ConnectionTestableInterface`/`ConnectionTestResult`, `ConnectionTestController` ("Check Connection" against live form values), supplier-tab detail panel + native toasts, two-file consolidated logging (`domainmanager.log`/`domainmanager-errors.log`, §3.6).
3.8. **Phase 3.8** — real `DinahostingDriver` implementation (registrar lifecycle + DNS zone records + account-wide connection test against the live API), replacing the Phase 3 stub; see §3.8.
3.9. **Phase 3.9** — real `IonosDriver` DNS implementation (zone records + connection test against the live API); registrar/lifecycle originally deliberately left unimplemented (no verifiable public API documentation had been found for it at the time). **Later implemented for real** (`fetchLifecycle()` against IONOS's separate Domains API, located and read directly from its live OpenAPI spec) — see §3.9's current text.
4. **Phase 4** — `DomainForm` injections, status card, `SyncController` + Update Now JS, cron batching loop.
5. **Phase 5** — registry sweep of popular DNS providers (researched patterns + sources documented in the JSON).
5.5. **Phase 5.5** — supplier-scoped "Domains" list. Pulled forward from the original Phase 6 sketch (below) because it needed no new schema and no discovery/entity-assignment design work, unlike bulk-import.
   - **Read-only "Domains" listing on the Supplier's Domain Manager tab** (`templates/supplier_domains_list.html.twig`, `DomainState::getDomainsForSupplier()`), so a supplier's involvement is visible without hunting through every `Domain` item individually. Each row: the domain name as a standard GLPI itemtype hyperlink (`Domain::getFormURLWithID()`), which role(s) this supplier plays for it (Registrar / DNS, each independently), and — when this supplier is the registrar but the domain's *detected* NS turned out to be a different provider — which one. Status badge reuses the sync engine's existing DNS classification verbatim (§5, no new logic), collapsed to three buckets: **plugin-managed** (`dns_suppliers_id` set), **known, unmanaged (yet)** (`dns_status` is `unsupported` or `unconfigured`), or **unknown** (`dns_status='unknown'`). Purely a read-only report — no add/edit/delete, no new rights.
     **Union of two independently-sourced halves, corrected 2026-07-21 after a real undercount bug** (reported as "the Domains panel shows 1 domain, Supplier's native Items tab shows 3 for the same supplier" — confirmed live, not assumed): the query originally `INNER JOIN`ed `glpi_plugin_domainmanager_states`, so a domain only appeared once a sync/detection had already produced a state row — invisible until then, even though its registrar link was already real and immediately knowable. Fixed to read the **registrar** half live and directly from `glpi_infocoms.suppliers_id` (`LEFT JOIN`, itemtype=Domain) — GLPI's own native, always-authoritative link, the same one behind the Supplier's native "Items" tab count, requiring no sync/state row to exist at all — while the **DNS/NS** half stays genuinely sync-dependent (`states.dns_suppliers_id`, which cannot be known without at least one real NS-detection run) and must not suppress a row the registrar half already justifies. `registrar_status` is only trusted from the state row when that row's *own* `registrar_suppliers_id` mirror actually agrees with the live Infocom value just queried — a domain can have a real state row (DNS side populated) while its Infocom assignment predates or otherwise missed the mirror below, in which case the row's `registrar_status` describes some other (often nonexistent) registrar, not this one; showing it next to this supplier's real name would be actively misleading, not merely stale, so it falls back to `STATUS_NEVER` ("not yet checked *for this supplier*") instead.
   - **Registrar field on the Domain Manager panel is now read-only**, mirroring Infocom's native "Supplier" field instead of being an independently-editable plugin dropdown — see §0.1 (revised) and §6.2. Rendered via GLPI's own `fields.readOnlyField()` macro (`components/form/fields_macros.html.twig`) for visual/structural parity with the rest of the native Domain form, with a `forcetab=Infocom$1` deep link to where it's actually set.
   - **`SyncEngine::sync()` now also resolves the registrar live from Infocom, corrected 2026-07-21** (same investigation as the undercount fix above, same root cause) — `syncRegistrarLeg()` used to read only the state row's own `registrar_suppliers_id` mirror, never Infocom directly, and the sync's own state upsert never wrote that field back either. So a domain whose Infocom registrar assignment predates/missed `HookHandler::infocomSaved()`'s mirror stayed permanently "unconfigured" no matter how many times it was synced — the "run a sync" call-to-action below would have been hollow for exactly the domains it targets. Now `sync()` reads Infocom live once at the top, uses that value for the registrar leg, and writes it into the state upsert's `registrar_suppliers_id` — self-correcting the mirror on every sync, in addition to `HookHandler`'s existing reactive correction on every Infocom save (defense in depth, not a replacement for it — the Domain form panel still reads the mirror directly, so keeping it fresh via both paths matters).
   - **A recommendation banner** at the top of the "Domains" panel when any registrar-linked domain hasn't actually been verified (`registrar_suppliers_id > 0` from the live Infocom read, but the mirror check above says unverified) — e.g. exactly the case a fresh install of this plugin on an existing GLPI instance with domains already assigned to suppliers would hit on every one of them, until each gets its first sync. Links to Domain's native search, pre-filtered to this supplier via a new **real, filterable search option** (`PLUGIN_DOMAINMANAGER_SO_DOMAIN_REGISTRAR = 9403`, `setup.php`) — core never exposes `Infocom::suppliers_id` as an add-on search option for any itemtype (verified against `src/Infocom.php::rawSearchOptionsToAdd()`, which adds `immo_number`/dates/etc. via the same join but not this field), so a 2-hop `dropdown` datatype option was added: `table`/`field` name the *final* dropdown target (`glpi_suppliers`/`name` — pointing them at `glpi_infocoms` instead, matching the other Infocom-add-on fields' shape, makes GLPI resolve values as `Infocom` records instead of `Supplier`s, silently breaking both display and filtering — confirmed live before finding the right shape), `linkfield` names the FK column, and `joinparams.beforejoin` describes the first hop to `glpi_infocoms`, mirroring how core's own `CartridgeItem`/`ConsumableItem` cases in the same core method reach the right `glpi_infocoms` row before resolving a field on it — extended one hop further here since the field itself lives on the table *after* that. Verified live end-to-end (correct dropdown value resolution, correct filtered results) before trusting it.
   - **A native GLPI Massive Action** ("Sync now (Domain Manager)") on `Domain`, registered via `Hooks::USE_MASSIVE_ACTION`/`plugin_domainmanager_MassiveActions()` (`hook.php`) → `MassiveActionHandler` — lets the banner's link land on a multi-select-ready native search instead of asking an admin to open each domain individually. Batches `SyncEngine::sync()`, `MassiveAction::itemDone(..., ACTION_OK|ACTION_KO|ACTION_NORIGHT)` per item so one domain's failure never aborts the rest (mirrors the per-leg isolation §5 already has *within* one domain's own sync, applied across domains here) — a domain counts as KO if a leg's own status is `STATUS_ERROR`, or (with its own distinct message, §5.5) `STATUS_SUPPLIER_INACTIVE`; `unconfigured`/`unsupported`/`unknown` outcomes are expected, not failures. **Two core-required static methods, both verified against `src/MassiveAction.php` directly rather than assumed:** `processMassiveActionsForOneItemtype(MassiveAction, CommonDBTM, array $ids)` (the actual batch processor) and `showMassiveActionsSubForm(MassiveAction): bool` (returning `false` falls back to core's own plain "Post" submit button, since this action needs no extra fields) — omitting the second one is a **fatal `UndefinedMethodError`, not a silent no-op** (confirmed live: `MassiveAction::showSubForm()` unconditionally calls it on whichever processor class the action dropdown resolves to).
   - **A tab count badge** ("Domain Manager 3", matching the native "Items 3" badge for the same supplier) via `createTabEntry()`'s `$nb` parameter, computed from the exact same `DomainState::getDomainsForSupplier()` call the panel itself renders from — deliberately not a separate/simpler count, so the two numbers can never drift apart. Gated behind `$_SESSION['glpishow_count_on_tabs']`, the same session preference core's own tab-count badges check (e.g. `Document_Item::getTabNameForItem()`), to avoid an unconditional extra query on every tab-list render for users who've turned tab counts off.
6. **Phase 6 (post-1.0)** — bulk-import domains from a connected registrar/DNS supplier's account. Today the plugin only ever enriches a GLPI `Domain` item that an admin already created by hand (`Cron.php`/`SyncController.php` both `getFromDB()` an existing row and skip/404 otherwise — see §5); a domain that exists at the provider but has no corresponding `Domain` item is invisible to it, with no discovery path. **Entity assignment decided:** newly-discovered domains are created under a single configurable default entity (a plugin-setup-level setting, not per-supplier), and the SysAdmin is expected to move them to their real entity afterward via GLPI's native entity-transfer feature — same pattern GLPI already uses for other auto-discovered assets, so no bespoke per-import entity picker needs building. Gate behind v1.0.
7. **Phase 7 (planned, not started)** — richer registrar domain metadata in the inventory. Implementing the real `IonosDriver` registrar pipeline (§3.9) surfaced that its Domains API response (`domainLarge`) carries several fields this plugin currently fetches but discards, since `DomainLifecycle` only models `registrationDate`/`expirationDate`/`status`: **`authInfo`** (EPP transfer/auth code), **`privacyEnabled`**, **`domainLock`**, **`transferLock`**, **`autoRenew`**, **`domainType`**, **`dnsSecEnabled`**. Candidates worth showing on the Domain form/inventory rather than discarding.
   - **Before adding any of them, compare what all three registrar drivers (`CloudflareDriver`, `DinahostingDriver`, `IonosDriver`) can actually supply**, the same way `fetchLifecycle()`'s registration-date gap was handled for IONOS (§3.9) — don't just wire up whatever IONOS happens to expose. Cloudflare's Registrar API and Dinahosting's command set need the same live-spec/live-probing verification IONOS just got (§3.9's methodology: locate the real API reference, don't guess field names), not an assumption that they mirror IONOS's exact field set or naming.
   - Goal: a **homogeneous** set of fields across drivers — add a field to `DomainLifecycle` (or a new DTO, if the shape grows large enough to warrant one) only once it's confirmed which of the 7 candidates above each driver can genuinely provide, `null`ing out per-driver gaps exactly like `registrationDate` already does for IONOS, rather than a field that only ever populates for one of the three drivers with no honest story for the other two.
   - Scope also includes: which of these belong in the Domain form's existing table (§6.2) vs. a new section/panel, whether any need their own status vocabulary vs. plain boolean/text display (§6.5's shared badge/pill convention still applies to any that do), and whether `RecordReconciler`/lock semantics (§0.3) need to extend to these new fields the way they already do for `date_domaincreation`/`date_expiration`/`is_active`.

Every phase leaves install → uninstall residue-free.

---

## 10. Versioning & changelog policy

`PLUGIN_DOMAINMANAGER_VERSION` in `setup.php` is the single source of truth for the plugin's version (no `composer.json` version field is used). **Every bump of that constant must land in the same commit as a matching `CHANGELOG.md` entry** — a new `## [x.y.z] - YYYY-MM-DD` section (Keep a Changelog format) containing whatever `### Added`/`### Changed`/`### Fixed` bullets accumulated under `[Unreleased]` since the previous version section, moved (not duplicated) out of `[Unreleased]` into the new version's section. A bump with no shipped content yet (e.g. a bare version-number increment immediately superseded by a later bump before anything else changed) still gets its own one-line section noting "version bump only — no functional changes", so the version history stays honest and every constant value that ever existed is traceable in the changelog. `[Unreleased]` itself is kept present but empty between releases, ready to accumulate the next round of bullets.

---

*Open items awaiting your approval: the four deviations in §0.1–§0.4 (Registrar as plugin field, `date_domaincreation` mapping, plugin-owned lock layer replacing native `Lockedfield`, documented `managed_domainrecordtypes` gate on web-triggered record writes) and the CREATE TABLE exception in §0.6.*
