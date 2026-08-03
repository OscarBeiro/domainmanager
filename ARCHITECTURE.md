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
- Table `glpi_plugin_domainmanager_locks` (same shape as `glpi_lockedfields`: `itemtype`, `items_id`, `field`, `value`, unicity on the triple), itemtype-agnostic (`ImportLock::getLockedFieldNames()`/`replaceLocks()` take `itemtype` as a parameter, used for both `Domain` and `DomainRecord`). Refreshed after every successful sync for all fields written.
- **Conditional per-field, not a static list (§9 Phase 14):** a field is only ever added to the lock set for an item when the most recent successful sync actually wrote a real value to it — `SyncEngine::syncRegistrarLeg()` only adds `date_domaincreation`/`date_expiration` to `Domain`'s lock set when the registrar's `DomainLifecycle` DTO reported a non-null `registrationDate`/`expirationDate` that sync; a driver that never reports registration date leaves `date_domaincreation` permanently unlocked and freely editable. `is_active` is always included because `LifecycleStatus` is a non-nullable DTO field — every driver always reports it, so there is no "not reported" case for it to skip. `name` (Domain) and `domains_id` (DomainRecord) are **not** conditional at all — they're always locked once the item is plugin-owned/synced, since they aren't something a driver "reports"; they're structural, changing them would break the record/domain relationship the plugin itself maintains. `RecordReconciler` mirrors the same conditional mechanism for `DomainRecord`'s `name`/`data`/`ttl`/`domainrecordtypes_id` (`ImportLock::replaceLocks(DomainRecord::class, ...)`, called from both the create and update paths) — today's `ZoneRecord` DTO has no nullable fields among these four, so in practice all four always lock, but a future DNS driver whose DTO gains a nullable field is handled correctly without further plugin changes, per the same principle applied to Domain.
- **`suppliers_id`/Registrar (§9 Phase 14):** unlike every other locked field, this isn't a native `Domain` column at all — it mirrors `Infocom::suppliers_id` (§0.1). `LockEnforcer::infocomPreUpdate()` (`Hooks::PRE_ITEM_UPDATE` on `Infocom::class`) strips a `suppliers_id` change once the Domain has a **confirmed working registrar match** — `DomainState::registrar_status === DomainState::STATUS_OK` (the most recent registrar sync actually succeeded against the currently-assigned supplier; `STATUS_ERROR`/`STATUS_REASSIGNED`/etc. don't count as confirmed, so a mid-flight or never-verified assignment stays freely editable). No separate `ImportLock` row is needed for this field — the check is direct against `DomainState`, since it only ever has one possible "locked" condition, unlike Domain's own fields which can each be locked/unlocked independently per sync.
- **Server-side enforcement (authoritative):** `pre_item_update` hook on `Domain` strips locked fields from the input (with a session warning) unless the user holds `domainmanager:unlock_imported`; `pre_item_update` hook on `Infocom` does the same for `suppliers_id`; `pre_item_update` / `pre_item_purge` / `pre_item_delete` hooks on `DomainRecord` block changes to plugin-imported records the same way.
- **UI enforcement (cosmetic):** JS injected via `post_item_form` disables the locked inputs and shows a lock icon for users without the right (the generic form's native `locked_fields` mechanism can't be fed by plugins for non-dynamic itemtypes).
- Holders of the right edit freely; the next successful sync re-imports API values and re-locks (documented behaviour). The `suppliers_id` lock can additionally be bypassed by the existing Reassign action (Supplier `UPDATE` right, §9 Phase 8) and the new Unlink action (Domain `UPDATE` right, §9 Phase 14) — both set the same `LockEnforcer::$sync_in_progress` runtime flag `SyncEngine` uses, rather than requiring `domainmanager:unlock_imported`, matching each action's own already-approved, narrower auth scope.

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

### 0.10 A raw PHP `true`/`false` in a `CommonDBTM::update()`/`add()` input is silently persisted as `NULL`, not `1`/`0` (found live 2026-07-21, §9 Phase 7a)
While wiring `DomainLifecycle`'s new nullable `?bool` metadata fields (`privacyEnabled`/`domainLock`/`transferLock`/`autoRenew`/`dnsSecEnabled`) into `SyncEngine`'s state upsert, a live end-to-end sync against the real IONOS API showed the in-memory `$result` array correctly holding `true` for `transferLock`/`autoRenew`, but the persisted `glpi_plugin_domainmanager_states` row showed `NULL` for those same columns after the exact same `update()` call — every string/int/explicit-`null` field in the same call persisted correctly; only the native PHP boolean values were affected. This matches why every *existing* tinyint column in this codebase already writes a literal `1`/`0` int, never a raw bool: `SyncEngine`'s own `is_active` derivation (`$lifecycle->status === LifecycleStatus::Ok ? 1 : 0`) and `Installer::migrateRecordManagedColumn()`'s `is_managed` (`['value' => 1]`) — this had just never been written down as a *rule*, only followed by accident/convention. **Fix**: `SyncEngine::toNullableInt()` explicitly casts every nullable-bool DTO field to `int`/`null` before it ever reaches an `update()`/`add()` input array. **Rule for future work**: never pass a native PHP `bool` into a `CommonDBTM` input array for a tinyint/bool column — always cast to `1`/`0`/`null` first. This directly affects Phase 7b's planned `is_proxied` tri-state column (§9) — implement it with the same explicit int cast from the start, not by rediscovering this the same way.

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
│       ├── ConnectionTestController.php NEW  POST /plugins/domainmanager/connectiontest/{suppliers_id} (§3.5, §6.1)
│       └── DomainRegistrarUnlinkController.php NEW  POST /plugins/domainmanager/domainunlink/{domains_id} (§9 Phase 14, §6.3.1)
│   (file tree above predates Phases 8/12 additions — Config/, ConfigController, DomainDiscoveryController,
│    DomainImportController, DomainRegistrarReassignController etc. — not fully re-synced here; see §9 for each)
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
| `registrar_auth_info` | varchar(255) NULL DEFAULT NULL | EPP transfer/auth code (§9 Phase 7) — persisted, **never rendered** in the Domain form (§6.2), only whether it's present |
| `registrar_privacy_enabled` | tinyint NULL DEFAULT NULL | WHOIS privacy on/off; `NULL` = driver doesn't report it |
| `registrar_domain_lock` | tinyint NULL DEFAULT NULL | idem, general registrar edit-lock |
| `registrar_transfer_lock` | tinyint NULL DEFAULT NULL | idem, transfer-specific lock |
| `registrar_auto_renew` | tinyint NULL DEFAULT NULL | idem, auto-renewal setting |
| `registrar_domain_type` | varchar(50) NULL DEFAULT NULL | raw driver-supplied classification (currently only IONOS's `DOMAIN`/`X_DOMAIN`/`GENERIC_DOMAIN`) |
| `registrar_dnssec_enabled` | tinyint NULL DEFAULT NULL | idem, DNSSEC on/off at the registrar |
| `is_managed` | tinyint NOT NULL DEFAULT 0 | Domain-level "Managed" (§9 Phase 14) — 1 iff either role currently resolves to a real, driver-backed, active supplier, independent of last sync success/failure |
| `date_mod` / `date_creation` | timestamp NULL | |

Indexes: PK, `UNIQUE domains_id`, `KEY registrar_suppliers_id`, `KEY dns_suppliers_id`, `KEY last_sync_date`, `KEY is_managed`, `KEY date_mod`, `KEY date_creation`.

`is_managed` (§9 Phase 14) is added post-creation via `Installer::addDomainManagedColumn()`, same idempotent `Migration::addField()`/`addKey()` pattern as the 7 columns above — but unlike those (and unlike `is_proxied` on the records table below), this one **does** backfill existing rows from their already-real `registrar_status`/`dns_status` history at migration time (one row-by-row `SELECT`+`UPDATE` pass), rather than leaving every pre-existing row `0` until its next sync — see the method's own docblock. Computed in two places: `SyncEngine::sync()`'s state upsert (from this sync's own `registrar_status`/`dns_status` outcome) and `HookHandler::infocomSaved()` (recomputed live via `DomainState::resolvesToActiveDriver()` the moment the registrar is reassigned/unlinked, without waiting for the next sync — consistent with `STATUS_REASSIGNED` already being set immediately today). Exposed as a real, filterable search option on `Domain` (`PLUGIN_DOMAINMANAGER_SO_DOMAIN_MANAGED`, `setup.php`), same single-hop `child`-join shape as the DomainRecord-level Managed option below — this table already has one row per Domain, so no new table was needed (unlike the DomainRecord-level field, which needed its own table because no per-Domain plugin table existed yet at the time).

The 7 `registrar_*` metadata columns (§9 Phase 7) are added post-creation via `Migration::addField()` in `Installer::addRegistrarMetadataColumns()`, same idempotent pattern as `addConnectionTestColumns()` above. They deliberately live on this plugin-owned table, not as new native `glpi_domains` columns — unlike `date_domaincreation`/`date_expiration`/`is_active` (already-existing native fields this plugin populates, §0.2), these 7 concepts have no native GLPI equivalent, so extending core's own table for them would be adding plugin-specific meaning to a shared schema for no reason. Consequently they also need **no `ImportLock`/`LockEnforcer` treatment** (§0.3, §9's own open question on this): nothing exposes them as an editable native Domain form field a user could otherwise touch and have a sync silently overwrite, so there's nothing for a lock to protect — the same reasoning that already applies to `detected_provider`/`registrar_status` on this same table. All 6 boolean-ish columns use `tinyint NULL DEFAULT NULL` (a real, meaningful third state — `NULL` means "this driver's API doesn't report this", not "false") rather than `Migration::addField()`'s `bool` shorthand, which always forces `NOT NULL` (same reason `registrar_test_http_code` above uses a raw type string instead of the `integer` shorthand).

### `glpi_plugin_domainmanager_records` (import ownership map — required for idempotent reconciliation and record-level locks)
| Column | Type | Notes |
|---|---|---|
| `id` | int unsigned AUTO_INCREMENT | PK |
| `domainrecords_id` | int unsigned NOT NULL DEFAULT 0 | FK → glpi_domainrecords, **UNIQUE** |
| `domains_id` | int unsigned NOT NULL DEFAULT 0 | FK → glpi_domains (fast per-domain scan) |
| `remote_id` | varchar(255) NOT NULL DEFAULT '' | provider record id when available |
| `record_hash` | varchar(64) NOT NULL DEFAULT '' | sha256 of type\|name\|data\|ttl (identity when no remote id) |
| `last_seen` | timestamp NULL | last sync that confirmed the record upstream |
| `is_managed` | tinyint NOT NULL DEFAULT 0 (1 on real rows) | backs the "Managed" search option on `DomainRecord` (§5.7); set once at creation, never changed afterward — replaces the removed `is_stale` column (§5.4), since "was this stale" is now read directly from `DomainRecord.is_deleted` instead of a second, plugin-owned flag that could drift out of sync with it |
| `date_mod` / `date_creation` | timestamp NULL | |

Indexes: PK, `UNIQUE domainrecords_id`, `KEY domains_id`, `KEY remote_id`, `KEY record_hash`, `KEY is_managed`.

`is_stale` existed here until §5.4/§5.7's revision (migrated away via `Installer::migrateRecordManagedColumn()` — `Migration::addField()` + `dropField()`, idempotent, existing rows backfilled to `is_managed = 1` since every pre-existing row already represented a plugin-tracked record).

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
- **`CloudflareDriver` → `['dns']` only.** Cloudflare's registrar API needs a specific domain name (`accounts/{id}/registrar/domains/{domain}`), which isn't known at credential-test time — there is no domain-independent registrar endpoint to probe. The DNS/zone capability, however, is verifiable independently via `accounts/{account_id}/tokens/verify` (§3.10 — **not** `user/tokens/verify`, which rejects account-owned tokens with a plain 401 regardless of validity), so only `'dns'` is reported even though the class also implements `RegistrarDriverInterface` for the real sync pipeline.
- **`IonosDriver` → `['registrar', 'dns']`**: `'dns'` is a real check against `GET /zones` (§3.9); `'registrar'`'s *connection-test* probe still returns `unknown_error`/"Not yet implemented for this driver" (`ConnectionTestResult::notImplemented()`) even though `fetchLifecycle()` — the actual sync pipeline — is now a real implementation too (§3.9); adding a real registrar connection-test probe was out of scope for that change, kept visible in the UI rather than hidden either way, so an admin can see the gap instead of just not finding a button for it.
- **`DinahostingDriver` → `['registrar', 'dns']`**, both built from a single real, account-wide auth probe (§3.8) — Dinahosting's auth isn't capability-scoped, so one lightweight API call classifies both.
- **`none` → `[]`** (nothing to test; the Check Connection button isn't rendered).

All capabilities a driver reports are still tested and persisted every time (nothing above changed) — but the Check Connection **UI** only ever shows one combined badge/toast, chosen by `DriverRegistry::getPrimaryTestableCapability()` (§6.1): a display simplification added after real-world testing showed a per-capability breakdown was confusing rather than informative (IONOS's permanent `registrar` "not implemented" stub sitting next to a real, successful `dns` result read as a mixed/ambiguous outcome instead of "this login works").

### 3.5.2 `ConnectionTestResult` DTO (`src/Dto/ConnectionTestResult.php`)
Immutable: `status: ConnectionTestStatus`, `capability: string`, `httpStatusCode: ?int`, `userMessage: string` (safe to persist/display), `rawDetail: string` (log-only, **never** serialized — `toArray()` deliberately omits it), `checkedAt: DateTimeImmutable`. Built via static factories `fromHttpResponse()` (maps 2xx/401/403/404/429/5xx/other to the matching `ConnectionTestStatus`), `fromException()` (classifies network/timeout/unknown from the exception), `notImplemented()` (stub drivers), and `notConfigured()` (required driver config missing — added for §3.10's Cloudflare Account ID, but not Cloudflare-specific: any driver/field needing a distinct "you haven't finished configuring this" state reuses it rather than collapsing into `UnknownError`/`AuthFailed`).

`ConnectionTestStatus` (`src/Dto/ConnectionTestStatus.php`, backed string enum): `Success`, `AuthFailed`, `Forbidden`, `NotFound`, `RateLimited`, `UpstreamError`, `NetworkError`, `Timeout`, `UnknownError`, `NotConfigured`.

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

### 3.7.1 The Historical tab's "field" column (corrected 2026-07-27, §9 Phase 14 addendum "Search UI cleanup")

Verified against `11.0/bugfixes` (`src/Log.php::getHistoryData()`): when `Log::history()` is called with `linked_action = 0` (the default, used everywhere in this plugin), the rendered "field" column comes from looking up `$changes[0]` (`id_search_option`) in `SearchOption::getOptionsForItemtype($itemtype)` and copying that option's `'name'`. There is **no** "log-label-only, hidden from Search" flag in this GLPI version (checked: only `massiveaction => false` exists, which doesn't hide an option from the Search/sort UI) — the only native way to get a custom field label is to register a **real** search option, which also makes it a normal, selectable column/filter in that itemtype's Search UI.

**This plugin originally did register two such dummy options** (`Supplier` id `9401`, `Domain` id `9402`, both bound to the itemtype's own `name` column purely so the Search UI wouldn't hit a SQL error if someone tried to filter/sort by them) — but live use surfaced that this trade-off wasn't worth it: both cluttered the Search UI's generic "Plugins" category with an entry offering no real, non-duplicate filtering value (it's just the itemtype's own Name field again, relabeled). **Dropped.** `Log::history()` call sites now pass `id_search_option = 0` (blank "field" column, the simpler and very common plugin convention noted in the glpi-plugin-builder skill's own guidance) and prefix the message text with `"[Domain Manager] "` instead — e.g. `"[Domain Manager] API Token set"`, `"[Domain Manager] Registrar supplier cleared"`. Core's generic renderer (`Log.php`, non-`text` datatype branch) formats this as **`"Change  to <phrase>"`** with a blank field, same as any other plugin using this fallback convention.

A third search option (`Domain`, id `9403`, `"Registrar (Financial information)"`) was also dropped for a different, more serious reason: it duplicated a **native** option. An earlier verification pass had concluded "core never exposes `Infocom::suppliers_id` as an add-on search option for any itemtype" — this was **wrong**, confirmed live by grepping the running `glpi-claude` container's actual `src/Infocom.php::rawSearchOptionsToAdd()`: native search option id **53** already exposes `glpi_suppliers.name` under "Financial and administrative information" for any Infocom-bearing itemtype, Domain included. `SupplierTab::getDomainsSearchUrl()` (§9 Phase 5.5's deep link) now points at native id `53` instead of maintaining a redundant copy.

The two remaining, real, non-duplicate options this plugin still registers — `PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_MANAGED`/`_PROXY` (§5.7/§9 Phase 7 addendum) and the new `PLUGIN_DOMAINMANAGER_SO_DOMAIN_MANAGED` (§9 Phase 14) — now each open their itemtype's option group with an explicit category-tab entry (`'id' => 'domainmanager', 'name' => __('Domain Manager', 'domainmanager')`, the same convention core's own `Infocom::rawSearchOptionsToAdd()` uses for its `'id' => 'financial'` header), so they group under a clearly-labeled "Domain Manager" section in the Search UI instead of the generic, unlabeled "Plugins" catch-all a plugin's options fall into without one.

`search-options-registry.json` (repo root) is the TICGAL-wide ledger of every search-option ID any TICGAL plugin registers, keyed by plugin then itemtype — it exists to keep IDs collision-free *between TICGAL's own plugins* (mechanically checkable). Its `removed` array now documents `9401`/`9402`/`9403`'s removal and why, alongside the still-active `9404`/`9405`/`9406`. Update it in the same change whenever a new search option is added here or in any sibling TICGAL plugin.

### 3.7.2 Phase 15: "Unknown column ...states_domains_id.is_managed" crash — real root cause was a missed version bump, not a wrong table/hook

Live testing of the Domains search after Phase 14 hit `Unknown column 'glpi_plugin_domainmanager_states_domains_id.is_managed'`. Investigated on the assumption the Domain-level "Managed" search option (§5.7 above, `PLUGIN_DOMAINMANAGER_SO_DOMAIN_MANAGED`) was wired to the wrong table/column, or that the search-option registration hook had the wrong name — **neither was true**: `plugin_domainmanager_getAddSearchOptionsNew()` (`setup.php`) is already the correct, real GLPI 11 hook (`Plugin::getAddSearchOptionsNew()`, verified against `11.0/bugfixes`), and `table`/`field` already correctly point at `DomainState::getTable()` (`glpi_plugin_domainmanager_states`) / `is_managed`, which does have that column in the schema (`Installer::createTables()`) and in the idempotent upgrade path (`Installer::addDomainManagedColumn()`).

The real cause: the Phase 14 commit added `Installer::addDomainManagedColumn()` to the migration chain and the search option in the same commit, but **never bumped `PLUGIN_DOMAINMANAGER_VERSION`** (left at `0.9.0`). GLPI only re-invokes a plugin's `Migration` (and thus `Installer::install()`) when the stored plugin version in `glpi_plugins` differs from the version constant in `setup.php` — on any instance already activated at 0.9.0, the new `is_managed` column's migration therefore silently never ran, and the new search option then referenced a column that genuinely didn't exist yet on that instance. Fixed in 0.10.0 by bumping the version constant (see CHANGELOG.md `[0.10.0]`), which makes GLPI's version-mismatch check re-run `install()` on next update/reactivation and create the pending column. No code change was needed to the search option, table, or hook themselves.

**A second, distinct bug surfaced immediately once the version bump made the migration actually run**: `Unknown column 'is_managed' in 'SET'` during `Installer::addDomainManagedColumn()`'s own backfill loop. `Migration::addField()`/`addKey()` only *queue* the `ALTER TABLE` — GLPI's `Migration` class buffers schema changes and flushes them via `executeMigration()`, which `Installer::install()` only calls once, at the very end, after every private migration-step method (including `addDomainManagedColumn()`) has already returned. But `addDomainManagedColumn()`'s backfill (`!$column_existed` branch) runs a raw, immediate `$DB->update($table, ['is_managed' => 1], ...)` — unbuffered, executed at once — against a column that, at that point in the call chain, is still only queued, not yet physically added. This only manifests on an **upgrade** of an existing install (the only case where `$column_existed` is `false` and the backfill branch runs at all); a fresh install never hits it because `Installer::createTables()`'s raw `CREATE TABLE` already includes `is_managed`, so `$column_existed` is `true` and the backfill is skipped. Fixed by calling `$migration->executeMigration()` immediately after `addField()`/`addKey()` inside `addDomainManagedColumn()`, flushing that queued `ALTER TABLE` before the backfill queries run (`executeMigration()` is safe to call more than once per migration; it just flushes whatever is currently queued, and `install()`'s own final call at the end is unaffected). No other migration-step method in `Installer.php` runs a manual backfill after `addField()`, so this was the only place with this pattern — audited and confirmed clean.

See `TESTING.md` §15 for both regression checks, and the versioning-requirement note in the `glpi-plugin-builder` skill this project uses — every version bump must land in the same commit as the migration/feature it gates, not a separate later commit.

**A third, related issue surfaced once the two fixes above let the upgrade actually complete**: `Attempted to use invalid search options from itemtype: "Domain" with IDs 9403` (`E_USER_WARNING`, `src/Glpi/Search/Input/QueryBuilder.php::validateFilters()`, debug/dev-mode only) on every render of the Domain list. Root cause: §3.7.1 above's Search UI cleanup dropped three placeholder/duplicate search-option IDs (Supplier `9401`; Domain `9402`/`9403`), but a saved search or bookmark (`glpi_savedsearches`) created **before** that drop still references one of them by number. GLPI's own validation already handles this gracefully at render time — it drops the dead criterion and shows the user a one-line "Some search criteria were removed because they are invalid" message — but nothing ever rewrites the saved search's stored `query` string, so the same warning fires again on every subsequent render of that same saved search, forever.

Fixed with `Installer::pruneStaleSearchOptionCriteria()` (new migration step, runs on every `install()`/upgrade, after `addDomainManagedColumn()`): for `Supplier` and `Domain`, it reads every `glpi_savedsearches` row's `query` column (a plain URL-encoded query string — `criteria[0][field]=9403&...` — not JSON; `parse_str()`/`http_build_query()` round-trip it losslessly), strips any `criteria[n]` entry whose `field` is one of the three retired IDs, and writes the row back only if something actually changed — every other criterion, sort, and display option in that saved search is left untouched. Idempotent, and a no-op for any row that never referenced these IDs.

**Known, accepted residual gap (superseded below):** a per-user, per-itemtype "last search" is also stored server-side in `$_SESSION` (not in `glpi_savedsearches`), and this migration cannot reach that.

**0.10.1: the residual gap above was the actual, persistent cause, not a one-time edge case.** Live testing kept reproducing the exact same warning after 0.10.0 shipped, even though `glpi_savedsearches`/`glpi_savedsearches_users` were directly confirmed empty on the affected instance (a full `mariadb-dump | grep 9403` across every table/column also came back empty) — proving the stale reference lived purely in `$_SESSION['glpisearch']['Domain']`. `QueryBuilder::manageParams()` (`src/Glpi/Search/Input/QueryBuilder.php`) writes whatever criteria a request ends up using back into that session key on *every* request, including the very first, default-generated one — so once a session picks up the stale ID once (from however it was originally seeded, before any of this session's investigation — not conclusively identified), it keeps re-supplying it on every subsequent page load indefinitely, since nothing was rewriting or clearing that in-session value. A one-time DB migration structurally cannot reach `$_SESSION` content.

Fixed with `HookHandler::scrubStaleSearchSessionCriteria()`, registered on `Hooks::POST_INIT` (`setup.php`) — fires on every page load, early enough (session already initialized, but before any `front/*.php` controller calls `QueryBuilder::manageParams()`) to strip a stale `criteria`/`sort` reference to `Supplier` `9401` or `Domain` `9402`/`9403` from `$_SESSION['glpisearch']` before it's read, on every request. This is the permanent, self-healing companion to 0.10.0's `Installer::pruneStaleSearchOptionCriteria()`: the migration cleans persisted saved searches once at upgrade time, the hook continuously cleans the in-session copy every request, covering both places this kind of stale reference can live. Both are intentionally kept (removing either would leave a gap the other doesn't cover), and both are cheap, defensive no-ops once no reference to these three retired IDs remains anywhere.

Every remaining `Log::history()` call in the plugin either passes `0` (Domain/Supplier prose-prefixed messages, above) or the matching real search-option constant (DomainRecord/Domain-level bool fields, which aren't used for `Log::history()` at all — only for filtering), leaving `$changes[1]` (old value) empty and putting a short descriptive phrase in `$changes[2]` (new value).

### 3.7.4 Phase 15 addendum: "Searchable fields" — exposing every tracked field as a real, filterable Domain search option

Goal: make every field this plugin tracks on `glpi_plugin_domainmanager_states` filterable/sortable from Domain's native search, not just "Managed" (§9 Phase 14). First slice: "NS Provider" (`detected_provider`, id `9407`), "Registrar sync status" (`registrar_status`, id `9408`), "DNS sync status" (`dns_status`, id `9409`), "Last sync" (`last_sync_date`, id `9410`) — all registered in `plugin_domainmanager_getAddSearchOptionsNew()`, same single-hop `'jointype' => 'child'` shape as `PLUGIN_DOMAINMANAGER_SO_DOMAIN_MANAGED` (one states row already exists per Domain).

**Dropdown filtering for the three enum-like fields, per the "use dropdown filtering whenever possible" requirement:** `registrar_status`/`dns_status` are drawn from `DomainState`'s fixed 8-value status enum (`STATUS_NEVER`/`STATUS_OK`/`STATUS_ERROR`/`STATUS_UNCONFIGURED`/`STATUS_UNSUPPORTED`/`STATUS_UNKNOWN`/`STATUS_SUPPLIER_INACTIVE`/`STATUS_REASSIGNED`), and `detected_provider` is drawn from `NsProviderRegistry::getProviders()`'s live, contributor-maintained but still fully enumerable list (plus blank/never-synced and `NsProviderRegistry::PROVIDER_UNKNOWN`) — real, bounded sets, not free text. All three use `'datatype' => 'specific'`, GLPI's own convention for exactly this (verified against `CommonITILObject::getSpecificValueToSelect()`'s handling of `Ticket`'s `status`/`priority`/etc., the closest live core precedent): the itemtype implements `getSpecificValueToDisplay()` (result formatting) and `getSpecificValueToSelect()` (the criteria value input, rendered via `Dropdown::showFromArray()`).

**Why these two methods live on `DomainState`, not `Domain`:** `Glpi\Search\Provider\SQLProvider`'s display code resolves the callback target as `$so['itemtype'] ?? getItemTypeForTable($table)` (`src/Glpi/Search/Provider/SQLProvider.php`, verified live) — i.e. from the search option's own `table`, not from the itemtype actually being searched. `Domain` is core and can't be reopened by a plugin to add these overrides even if we wanted them there; `DomainState` is this plugin's own class and the table these three fields actually live on, so it's both the correct dispatch target and the only one a plugin *can* implement. Because `DomainState::getTable()` is an explicit override (not derivable from the class name via GLPI's plain naming convention), each of these three search options also sets `'itemtype' => DomainState::class` explicitly rather than relying on `getItemTypeForTable()` to guess correctly.

`GlpiPlugin\Domainmanager\Service\DomainStatusResolver::getStatusLabels()`/`getStatusClasses()` (originally landed as static methods on `DomainState` itself, moved from `DomainForm`; extracted into this dedicated `Service` class in 0.11.5, completing `PHASE13_PLAN.md` step 2) are the single source of truth for the status enum's labels/badge colors, shared by the Domain form panel's status badge, the Supplier "Domains" list, and this search option's dropdown/display — so none of the three can ever drift out of sync with each other. `DomainState`'s two `getSpecificValueTo*()` methods below call `DomainStatusResolver` directly.

**`last_sync_date` deliberately has no dropdown**: its values aren't a bounded set, so `'datatype' => 'datetime'` (a plain native GLPI datatype, no custom code) is the correct, simpler choice — its built-in `equals`/`morethan`/`lessthan`/`empty` criteria already satisfy "sort and check validity" (e.g. filter for domains whose last sync predates a given date, or that show `empty` because they've never synced at all).

`search-options-registry.json`'s reserved block widened `9400-9409` → `9400-9429` to leave room for the remaining `glpi_plugin_domainmanager_states` fields (the 7 registrar administrative-metadata columns from §9 Phase 7, `is_managed` already covered) a later phase will expose the same way.

### 3.7.2 What gets logged

- **Supplier credential/driver changes** (`src/SupplierConfig.php`): `SupplierConfig` has no visible list/tab of its own (it's only ever shown inline on the Supplier's "Domain Manager" tab), so entries are attributed to the **owning `Supplier`** (`Log::history($suppliers_id, Supplier::class, [0, '', '[Domain Manager] ' . $line])`, id `0` + text prefix per §3.7.1's corrected convention) — visible on that Supplier's own native Historical tab, the same pattern `SyncLogger` already uses to attribute sync outcomes to `Domain` rather than the internal state table. `prepareDriverAndCredentials()` diffs the old vs. new driver + decrypted credentials and queues one line per change (`"API driver set to X"` / `"API driver changed from X to Y"` / `"<Field label> set"` / `"<Field label> updated"` / `"<Field label> cleared"`); lines are flushed in `post_addItem()`/`post_updateItem()`. **Never logs a credential value, only the field's label** — the diff is computed from decrypted values in memory purely to detect *whether* something changed. `post_purgeItem()` logs a single "API configuration removed (was X)" line.
- **Domain registrar-supplier assignment** (`src/HookHandler.php::logRegistrarChange()`): logs to `Domain`'s own Historical tab (`Log::history($domains_id, Domain::class, [0, '', '[Domain Manager] ' . $message])`, same corrected convention) only when the resolved `registrar_suppliers_id` actually changes — `"Registrar supplier set to %s"` / `"...changed from %s to %s"` / `"...cleared"`, using `Dropdown::getDropdownName(Supplier::getTable(), $id)` for the display name.
- **Sync milestones** (`src/Service/SyncLogger.php::milestone()`): every successful registrar/DNS sync leg also logs to `Domain`'s Historical tab the same way, so all Domain-side Domain Manager activity (registrar assignment *and* sync outcomes) is consistently prefixed "[Domain Manager]" in one place.
- Deliberately **not** logged here (scope stayed to user-initiated configuration and sync outcomes, not per-record bookkeeping): per-sync `DomainState` upserts beyond the milestone line already covered above, and `ImportedRecord`/`ImportLock` internal rows (no user-visible list/tab exists for either, so a native History entry there would never be seen).

---

## 3.8 Dinahosting driver: real implementation (was a stub)

`DinahostingDriver` (`src/Driver/DinahostingDriver.php`) was originally shipped in Phase 3 as a pure stub (every method threw `NotImplementedException`) alongside the still-stubbed `IonosDriver`. Investigating a "Test credentials" bug report (toast showing a generic message, `domainmanager-errors.log` apparently staying empty) found no defect in the toast/logging pipeline itself — verified two independent ways (direct controller invocation, and a full real HTTP request through login+CSRF+routing on a disposable test instance) — the pipeline correctly surfaced and logged the stub's fixed "Not yet implemented for this driver" result. The actual gap was that the driver never attempted a real API call to have a real result to report. This section documents its real implementation.

**API**: `https://dinahosting.com/special/api.php`, GET requests, `responseType=json`. The official docs (`en.dinahosting.com/api/documentation`) are a command index without inline example bodies; the exact JSON envelope and field names below were cross-verified against the maintained third-party client `github.com/libdns/dinahosting` (`provider.go`), which is the only source found with byte-accurate wire format.

- **Envelope**: `{trId, responseCode, message, data, errors, command}`. Success = `message === "Success."` OR `responseCode === 1000`. Failure responses put a human message + code in `errors[]` (`{code, message, parameter}`), **always over HTTP 200** — Dinahosting never varies the HTTP status for business-logic outcomes, only for real transport failures.
- **Auth**: the docs list two equivalent options — `AUTH_USER`/`AUTH_PWD` query parameters (used in their own example URLs), or a `Authorization: Basic` header. This driver deliberately uses the **Basic Auth header** (via Guzzle's `auth` client option) rather than query parameters, so credentials never end up in a request URI that could be captured by a proxy/CDN access log — confirmed working against the live API (a deliberately-wrong username/password round-tripped a real `responseCode=2200` "Authentication error." from Dinahosting's own server, correctly classified as `AuthFailed`).
- **Credentials must belong to the account's super-admin user** — a sub-user or domain-limited account cannot authenticate against the API at all (see `README.md#dinahosting` for the user-facing setup note). This is also *why* `testConnection()` below can only do one account-wide auth check rather than a scoped-permission probe: there's no lesser-privileged credential to test against.
- **`testConnection()`**: Dinahosting authentication is a single account-wide username/password, not scoped per capability, so one lightweight, side-effect-free probe (`System_GetRequestTypes` — confirmed domain-independent from the docs' own example request, which omits a `domain` parameter) classifies both `'registrar'` and `'dns'` from the same outcome. `responseCode` → `ConnectionTestStatus`: `2200` (`AUTH_ERROR_USER`) → `AuthFailed`, `2201` (`AUTH_ERROR_OBJECT`) → `Forbidden`, `2501` (`COMMAND_TIMEOUT`) → `Timeout`, anything else non-success → `UnknownError`. `httpStatusCode` is left `null` for these envelope-classified outcomes (always ~200 regardless of the real result — showing "HTTP 200" next to an "auth failed" badge would be misleading); it's only populated for genuine transport-level 5xx.
- **`fetchLifecycle()`**: `Domain_GetExpirationDate` / `Domain_GetRegistrationDate` (each confirmed: single `domain` parameter, returns a string). **Known gap, flagged rather than guessed**: no documented response shape for a registrar-hold/suspended status command (`Domain_Status_Get`) was found anywhere searched, so `LifecycleStatus` here only distinguishes `Ok`/`Expired` (via the expiration date) — never `Suspended`. Revisit if Dinahosting's docs (or a support ticket) ever surface that command's real shape.
  - **`Domain_GetRegistrationDate`'s exact semantics are unconfirmed** (checked directly against the live doc page, not from memory: the entire description is "Returns registration date of domain." — no elaboration). Two readings are possible: (a) the domain's real/original registry creation date (a WHOIS/RDAP-style "Creation Date", invariant across registrar transfers per ICANN's Transfer Policy), or (b) the date the domain was added to/transferred into this Dinahosting account specifically. **Assumed to be (a)**, based on the command taking only `domain` (no account-scoping hint) and Dinahosting modeling inbound transfers as their own separate command family (`Domain_CheckForTransfer`, `Domain_Transfer_GetStatus`, `Billing_Transfer_Domain`) rather than folding "transfer date" into this general domain-info command — but this is inference from API shape and EPP/registry convention, not a confirmed fact. **Only verifiable against a domain known to have been transferred in from another registrar** (compare this command's returned date to that domain's actual WHOIS/RDAP creation date) — genuinely hard to test opportunistically since transferring a domain isn't a routine, frequent event; revisit whenever such a domain becomes available to check.
- **`fetchZoneRecords()`**: `Domain_Zone_GetAll`. Field names for **A/AAAA (`ip`), CNAME (`destinationHostname`), TXT (`text`)** are confirmed against the reference client. **Known gap**: that client only ever *writes* A/AAAA/TXT/CNAME, so it never modeled MX/NS fields specifically — `extractContent()` falls back to the same generic field set the reference client uses for record types it doesn't recognize either. MX priority ordering in particular is unconfirmed; verify against a real account with real MX/NS records before relying on it.
- Both pipeline methods route API errors through a shared `request()` helper mirroring `CloudflareDriver::request()`'s shape (`DriverException` with a safe message, technical detail to `PluginLogger::error()` — including the object-not-found code `2303` mapped to a clear "domain not managed by this account" message).
  - **Fixed a real bug (found live, §addendum "Debug: Dinahosting Registrar Auth Failure"): `request()` originally collapsed `2200` (`AUTH_ERROR_USER`, the account's own credentials rejected) and `2201` (`AUTH_ERROR_OBJECT`, credentials fine but *this specific domain* isn't authorized under the account) into the identical "Dinahosting authentication failed, check the username/password" message** — even though `testConnection()`'s `classifyEnvelope()` (just above) already correctly treats them as distinct (`AuthFailed` vs `Forbidden`). Reported live as domain `pontecm.com` (supplier: Dinahosting) showing registrar = "authentication failed", while a different domain (`tic.gal`) under the *same* supplier/credentials synced `ok` and Check Connection reported success — ruling out a real account-level credential problem. `2201` now gets its own message ("Dinahosting authentication succeeded, but this account is not authorized to manage this domain"), so a per-domain authorization gap is never misreported as a credentials problem.
- `PluginLogger::redact()` (§3.6) was widened to also catch Dinahosting's non-standard `AUTH_PWD`/`pwd=` credential naming (the existing pattern only matched the literal word "password") — defense in depth, since this driver's own code never logs a raw request URI in the first place.
- **§9 Phase 7 (2026-07-21)**: `fetchLifecycle()` now also fetches `authInfo` via `Domain_GetAuthcode` (real command, confirmed by live probe; see §9 for the full per-driver support matrix). `privacyEnabled`/`domainLock`/`transferLock`/`autoRenew` each have a real, confirmed-existing Dinahosting command too, but no confirmed response shape for any of them — left `null` rather than guessed.

### 3.8.1 `DnsRecordWriterInterface` implementation — write mode for A/AAAA/CNAME/TXT

`DinahostingDriver` now also implements `DnsRecordWriterInterface` (§11.4's `WRITABLE_TYPES` — `A, AAAA, CNAME, TXT` — is an exact match for what Dinahosting's write API covers). Its shape is dictated entirely by real constraints in `github.com/libdns/dinahosting`, and differs sharply from `CloudflareDriver`'s id-based REST CRUD (§12):

- **No update command exists.** Only per-type add/delete commands: `Domain_Zone_AddTypeA`/`DeleteTypeA`, `AddTypeAAAA`/`DeleteTypeAAAA`, `AddTypeCname`/`DeleteTypeCname`, `AddTypeTXT`/`DeleteTypeTXT`. `updateRecord()` is synthesized as delete-then-add — **not atomic**: if the add half fails after the delete succeeds, the record is left deleted with no automatic rollback (Dinahosting has no transaction to roll back).
- **No per-record id exists** in `Domain_Zone_GetAll`'s response (confirmed absent from the reference client's model). `ZoneRecord::remoteId` is therefore a synthetic `base64("$type|$name")` token (`encodeRemoteId()`/`decodeRemoteId()`) — round-trippable between this driver's own read and write paths, not a real provider identifier.
- **A/AAAA/CNAME deletes act on hostname alone**, with no value filter — deleting "one" A record at a name that has several (e.g. round-robin) would delete all of them. TXT is the sole type where Dinahosting's delete also accepts an optional `value` filter, used here for defense in depth.
- **Guard against the above, deliberately, not provisionally**: `assertSingleRecordAtName()` re-scans the zone (via the existing `fetchZoneRecords()`) before every create/update/delete and refuses the write with a clear `DriverException` if more than one record already shares `type`+`name`. This is a permanent data-loss guard given the API shape, not a placeholder to be lifted once something is "confirmed" — there is nothing to confirm; the API genuinely has no scoped delete.
- **No `ttl` parameter exists on any Add command** — TTL is server-managed. `createRecord()`/`updateRecord()` discard the caller's `$ttl` entirely; the returned `ZoneRecord` is populated by a follow-up `Domain_Zone_GetAll` read (`findByIdentity()`) reporting whatever TTL Dinahosting actually applied.
- **No single-record read command exists** — `fetchRecord()` (used only for the edit-confirmation modal, per §11.9) does the same whole-zone-scan-and-filter as the collision guard.
- **Unconfirmed against a live write call**: whether Dinahosting's `hostname` parameter on the Add commands wants the same absolute form `fetchZoneRecords()` already reads back (assumed here) or a zone-relative label instead. Same caveat class as the already-flagged MX/NS field gap (§3.8) — verify against a real account before treating this as settled.
- No "record not found" responseCode is documented (distinct from `2303`/`CODE_OBJECT_NOT_EXISTS`, which means "domain not managed by this account", not "record absent within an otherwise-managed zone") — unlike `CloudflareDriver::deleteRecord()`'s 404-is-success handling, a delete against an already-absent record currently surfaces as a generic Dinahosting API error rather than a silent success.

### 3.8.2 Addendum (2026-08-03): live-account findings that superseded several of the caveats above

Several gaps this section originally flagged as "unconfirmed" were resolved against a real Dinahosting account while chasing a string of live write-back bug reports. Recorded here rather than editing the historical text above out from under it:

- **`Domain_Zone_GetAll` reports hostnames inconsistently by type**, confirmed live: `@` for an A/AAAA/CNAME apex record, the bare zone name itself for a TXT/MX apex record, and a bare *relative* label (`manel`, not `manel.example.com`) for every non-apex record — never the absolute form this driver's other write paths assume. Left un-normalized, this silently broke `findByIdentity()` for virtually every non-apex record: a genuinely successful create was misreported as failed ("Dinahosting did not report the newly created record"), and the resulting user retries created real duplicate records upstream. `qualifyHostname()`, applied in `fetchZoneRecords()`, normalizes every reported hostname into one absolute-FQDN convention (matching Cloudflare/IONOS and `DnsRecordWriterInterface`'s own contract) before anything else in the driver ever sees it.
- **The `hostname` parameter's required form differs between the Add and Delete commands** — resolving the "unconfirmed against a live write call" caveat above, but not the way that caveat guessed. Confirmed with raw HTTP calls against a live account, bypassing this driver's own exception mapping, for both A and TXT: `Domain_Zone_AddType*` accepts the absolute form fine, but every `Domain_Zone_DeleteType*` command rejects it outright (`responseCode 2303`, `Param "hostname"/"ip" value doesn't exist`) even for a record `findByIdentity()` had just resolved to moments earlier — it wants the *relative* label back. `relativeHostname()` (the inverse of `qualifyHostname()`) is applied only to the outgoing `hostname` param on delete; `findByIdentity()` itself still compares against the absolute form throughout. Apex delete for TXT/MX specifically remains unconfirmed (no existing apex record was safe to test-delete against a live zone) but uses the same `@` convention observed for A/AAAA/CNAME apex, rather than guessing at a type-specific exception.
- **`2303`/`CODE_OBJECT_NOT_EXISTS` does not always mean "domain not managed by this account"**, contradicting this section's own line above (§3.8, `request()`'s shared error mapping) — that reading was only ever confirmed for domain-level commands (`Domain_Zone_GetAll`, `Services_GetDomains`). For a `Domain_Zone_DeleteType*` call specifically, the same code means "this hostname/value combination doesn't exist" (the raw error text is literally `Param "hostname"/"ip" value doesn't exist`), and mapping it to the domain-level message is actively misleading in that context. Not yet corrected in `request()`'s shared handler as of this writing — the fix so far is the `relativeHostname()` change above, which stops the delete calls that were triggering the misleading message in the first place; revisit `request()`'s per-command-aware error mapping if a delete against a genuinely-absent record ever needs to be told apart from other failures (§3.8's existing "no documented record-not-found responseCode" gap, still open).
- **`assertSingleRecordAtName()` also depends on the `qualifyHostname()` fix above** — before it, an upstream duplicate reported with mismatched relative/absolute names could evade `findByIdentity()`-based detection entirely and be silently mirrored into GLPI as a second local `DomainRecord` (observed live: two real duplicate "manel" A records, created by the create/retry bug above, went undetected by this guard until the hostname normalization fix landed).
- **`ImportedRecord.remote_id` needed a one-time repair migration.** It's set once at creation and never rewritten for an already-synced, unchanged record (`RecordReconciler::createRecord()`), so any record synced before the `qualifyHostname()` fix still carries a `remote_id` encoding the old, un-normalized hostname, breaking its delete/update/restore. `DinahostingDriver::renormalizeRemoteId()` plus `Installer::renormalizeDinahostingRemoteIds()` (idempotent, run on every install/upgrade) re-encode just that column for every Dinahosting-managed domain's records — deliberately never touching the `DomainRecord`/`Domain` rows themselves, so anything already linked to them (tickets, contracts, projects) is unaffected.

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

**§9 Phase 7 (2026-07-21)**: the same `GET /v1/domainitems/{domainId}` call `fetchLifecycle()` already made returns `domainLarge`'s `authInfo`/`privacyEnabled`/`domainLock`/`transferLock`/`autoRenew`/`domainType`/`dnsSecEnabled` fields too (all confirmed directly in the spec, re-read live 2026-07-21) — no extra request needed; IONOS is the only driver of the three that can populate all 7. See §9 for the full per-driver support matrix.

---

## 3.10 Cloudflare driver: switched to account-scoped API tokens (credential shape changed 2026-07-21)

Cloudflare supports two token origins that authenticate identically (`Authorization: Bearer <token>`, no auth-mechanism difference) but differ in ownership: **personal/user tokens** (My Profile → API Tokens, tied to whichever human created them — inherit that user's own zone access and go stale if the user loses access/leaves) versus **account-scoped tokens** (`cfat_...`, Manage Account → API Tokens, requires Super Administrator, owned by the Cloudflare Account itself). A service integration like this plugin should use the latter — addressed via `§addendum "Switch Cloudflare Driver to Account-Scoped API Tokens"`.

**Only account-scoped (`cfat_...`) tokens are supported by this driver — personal/user tokens are not.** This isn't just a recommendation: `probeTokenVerify()` (below) calls `accounts/{account_id}/tokens/verify`, an account-scoped endpoint that a personal/user token cannot authenticate against (that token type has no relationship to any specific account — see the confirmed bug below), and `fetchLifecycle()` always builds its path from the configured `account_id` too. A personal token pasted into this form will fail Check Connection with `auth_failed`/HTTP 401 regardless of how valid or well-scoped it otherwise is — this is expected, not a bug, given the deliberate switch documented here; the fix is to create the correct token type (Manage Account → API Tokens), not to debug the token itself.

**Confirmed live bug, found and fixed after initial release of this change**: `probeTokenVerify()` was initially left calling the pre-existing `user/tokens/verify` endpoint unchanged, on the (incorrect) assumption from item 3 of the addendum ("Confirm the auth header itself is unchanged... this is not an auth-mechanism change") that no endpoint-level change was needed beyond the account-scoping additions. Reported live: a freshly created, valid account-scoped token and Account ID still failed Check Connection with `auth_failed`/HTTP 401. Root cause, confirmed against Cloudflare's live OpenAPI spec: `user/tokens/verify` and `accounts/{account_id}/tokens/verify` are two distinct operations, and the user-scoped one structurally rejects account-owned tokens with a plain 401 — a documented, known Cloudflare API quirk (independently reported by other integrators hitting the same thing), not a credentials mistake. Fixed by calling the account-scoped endpoint, using the same `account_id` credential already required for the zone/registrar lookups.

- **New required credential field: `account_id`** (`DriverRegistry::getCredentialFields()`, non-secret, `'required' => true` — a new, generic, optional metadata key any driver field can now set, read by `supplier_tab.html.twig` to add the HTML `required` attribute; nothing else changes for fields that don't set it). **No database migration was needed or added**, despite that being one candidate approach: `account_id` lives in the same `GLPIKey`-encrypted `api_credentials` JSON blob as every other credential field (§2's existing per-driver, arbitrary-keys design), exactly like Dinahosting's non-secret `user` field already does — adding a new named key to that JSON needs no schema change at all. A config saved before this field existed simply has no `account_id` key in its decrypted JSON; detected via `SupplierConfig::getSavedCredentialFlags()['account_id']` being `false`, same mechanism already used for every other "is this field saved" check.
- **Zone lookup now scoped by account** (`CloudflareDriver::findZone()`): confirmed against Cloudflare's current, live OpenAPI spec (`github.com/cloudflare/api-schemas/blob/main/openapi.json`, the `GET /zones` operation's `parameters`) that the literal query parameter is **`account.id`** (a dotted string key, not `account_id`, not a nested/deepObject `account[id]` form) and that it's optional in the schema — but passing it is the actual fix here: without it, a token with visibility into more than one account could previously resolve to a zone under a *different* account than intended, since `findZone()` only ever matched on `name`.
- **Registrar lookup no longer depends on a DNS zone existing first**: previously `fetchLifecycle()` called `findZone()` purely to read `account.id` back out of the zone response (the only way to learn the account id before this change). Now it uses the stored `account_id` credential directly as the `accounts/{id}/registrar/domains/{domain}` path segment, and no longer calls `findZone()` at all — removing an incidental coupling between two independent Cloudflare products (a domain can be a Cloudflare Registrar domain without necessarily having an active zone in the same account, or vice versa). `request()`'s existing empty-result handling still reports a clear "not managed by Cloudflare Registrar on this account" error if the domain isn't found there.
- **`testConnection()` validates presence, not correctness, of `account_id`/`token` before attempting any network call** (`CloudflareDriver::missingConfigMessage()`), returning a new, distinct `ConnectionTestResult::notConfigured()` result (`ConnectionTestStatus::NotConfigured`, §3.5.2) rather than letting a missing-config `DriverException` fall through to `fromException()`'s generic `UnknownError` classification. Rendered as its own amber `text-bg-warning` badge/toast ("Not configured") — visually distinct from a red "Authentication failed" badge, since nothing was actually attempted. `connection_test_panel.html.twig`'s status maps and `supplier_tab.html.twig`'s toast-color JS list both include `not_configured` alongside the existing transient-warning statuses.
- **Existing configs missing `account_id`** (token saved, no account id): `SupplierTab::showForSupplier()` computes `cloudflare_missing_account_id` and `supplier_tab.html.twig` renders a standing warning alert in the credentials card ("...reissue this token as an Account API Token to continue using Cloudflare sync") whenever it's true — informational only, doesn't block editing/saving the rest of the form, and disappears once a value is saved. Nothing silently breaks and nothing silently keeps working on the old, less-scoped assumption either.
- **New help text** for the Cloudflare credential fields (toggled by the same driver-select JS as the fields themselves) pointing at Manage Account → API Tokens (not My Profile), the two minimum permissions (`Zone:DNS:Read`, `Zone:Zone:Read`), and where to find the Account ID (account Overview page's API section, or "Copy account ID" from the account-row menu) — confirmed against Cloudflare's own current docs (`developers.cloudflare.com/fundamentals/api/get-started/account-owned-tokens/` and `.../fundamentals/account/find-account-and-zone-ids/`), not assumed. No pre-existing "how to obtain a token" reference box was found anywhere in this plugin for any driver to model this on or update — this is a new addition, not an edit of prior content.
- **§9 Phase 7 (2026-07-21)**: `fetchLifecycle()`'s existing `$result` (the same `accounts/{id}/registrar/domains/{domain}` response already read for `created_at`/`expires_at`/`registry_statuses`) also carries `privacy` → `privacyEnabled`, `locked` → `transferLock`, and `auto_renew` → `autoRenew` (all confirmed directly against Cloudflare's live `registrar-api_domain_properties` schema, re-read 2026-07-21). `authInfo`/`domainLock`/`domainType`/`dnsSecEnabled` are confirmed genuinely absent from this schema — `locked` is the *only* lock concept Cloudflare's Registrar API models, with no separate general-edit-lock field, so it maps to `transferLock` only, never `domainLock`. See §9 for the full per-driver support matrix.

### 3.10.1 Check Connection now also probes zone/DNS scope (Phase 43, 2026-07-30)

`probeTokenVerify()` above only proves the token itself is valid/unrevoked (`accounts/{id}/tokens/verify`) — it says nothing about whether the token actually carries `Zone:DNS:Read`/`Zone:Zone:Read` for any zone. That gap was real and observed live: Check Connection reported "Success HTTP 200" for a supplier whose subsequent DNS sync 401/403'd on every domain (see CHANGELOG 1.3.0-alpha1/alpha4 for the logging-side half of this investigation).

`CloudflareDriver::testConnection()` now runs a second probe, `probeZoneScope()`, whenever `probeTokenVerify()` itself succeeds: the same account-scoped `GET /zones?account.id={id}&per_page=1` lookup `findZone()` uses for a real sync, with no `name` filter (only the scope matters here, not any particular zone). It classifies the raw HTTP status directly (not through `request()`, whose 401/403 branches throw a `DriverException` with no status code attached) and, on a `403`, returns the exact same user-facing message a real sync failure already gives (`request()`'s 403 branch, 1.3.0-alpha4): "this Cloudflare API token lacks DNS:Read permission for this zone" — so Check Connection and a live sync failure now agree on the diagnosis instead of the UI ever showing "Success" for a token that will demonstrably fail. A `200` with zero zones in the account is still reported as success (no zones to sync is a legitimate empty state, not a scope problem).

---

## 3.11 IDN / Punycode handling (`IdnNormalizer`, Phase 10, 2026-07-22)

Real-world case: `viñamoraima.com`, a genuine IDN GLPI stores as its human-readable Unicode `Domain::name`. DNS itself and every provider API this plugin calls only operate on the ASCII-compatible Punycode/ACE form (`xn--...`), so anything DNS- or API-facing that used the raw Unicode `name` directly was silently broken for this class of domain.

**Single conversion point**: `src/IdnNormalizer.php`, wrapping `idn_to_ascii()`/`idn_to_utf8()` (the `intl` extension) with the modern `INTL_IDNA_VARIANT_UTS46` variant and `IDNA_NONTRANSITIONAL_TO_ASCII`/`IDNA_NONTRANSITIONAL_TO_UNICODE` flags (the WHATWG/ICANN-recommended mode since 2017 — not the deprecated `INTL_IDNA_VARIANT_2003`). `intl` is now a **hard dependency**: `plugin_domainmanager_check_prerequisites()` (`setup.php`) blocks activation with a clear message if it's missing, rather than letting every subsequent sync fail with an opaque fatal error. Confirmed present and working in this environment's PHP 8.4; a plain ASCII domain round-trips through `toAscii()`/`toUnicode()` unchanged.

**Wired in at every DNS-/API-facing seam:**
- `NsResolver::getNameservers()` converts the fqdn to Punycode before `dns_get_record()` and its own FQDN regex — `dns_get_record()` simply fails/returns nothing for a raw Unicode label.
- Every driver's `normalizeDomain()` (`CloudflareDriver`, `IonosDriver`, `DinahostingDriver`) converts to Punycode before validating against the existing ASCII FQDN regex, so the outbound registrar/DNS API call itself always sends Punycode.
- **Per-provider Unicode-vs-Punycode API expectation is unconfirmed for Cloudflare and Dinahosting** — web research (official docs, 2026-07-22) found no explicit statement from either. Punycode was chosen as their outbound form since it's the only form guaranteed DNS-wire-compatible; flagged for revisiting if a live account ever surfaces a provider that actually wants Unicode.
- **IONOS is a confirmed exception, found via live testing, not documentation** (2026-07-22, `viñamoraima.com`): its two API surfaces disagree with each other, and neither of the Domains API's two candidate query encodings actually works. DNS sync succeeded querying the zone list with the Punycode form (`xn--viamoraima-u9a.com`). The registrar/lifecycle lookup against the *same* domain, same account, failed two different ways in turn:
  1. Querying the Domains API's `name` filter with that Punycode string returned `"No IONOS domain item found for xn--viamoraima-u9a.com with this account"` — a clean, well-formed (if wrong) empty result, for a domain that *was* registered on the account.
  2. Switching the query to the literal Unicode form instead (the seemingly obvious fix) made it worse: a raw, non-JSON HTML `400 Bad request` page straight from IONOS's own gateway — confirmed this wasn't a request-encoding bug on this plugin's side (Guzzle's query builder percent-encodes the UTF-8 bytes correctly, verified directly), meaning IONOS's edge/WAF rejects non-ASCII in this parameter outright, encoded or not.

  Since neither form of the `name` filter can be trusted for an IDN domain, `IonosDriver::findDomainId()` now **omits the `name` filter entirely** and paginates through the full unfiltered `domainitems` list (same bounded pagination `listAccountDomains()` already uses for Phase 8), matching each row's `name` client-side after canonicalizing it to Punycode. `findZoneId()`/`fetchZoneRecords()` (DNS) are unaffected — they never used a server-side name filter to begin with, and continue to query with Punycode.
- `NsProviderRegistry`'s wildcard NS-hostname matching was reviewed and is **not** affected: nameserver hostnames are themselves never IDN in practice, so no conversion was added there — a deliberate non-issue, not an unconsidered gap.

**Round trip on responses**: third-party bug reports (`cloudflare-go` #347, `terraform-provider-cloudflare` #1310) confirm at least Cloudflare can echo a domain name back in Unicode even when queried with Punycode. Any place this plugin compares a provider-returned name against its own Punycode-normalized domain now normalizes the returned name to Punycode first, rather than trusting a raw string match:
- `IonosDriver::findDomainId()` / `findZoneId()` (`strcasecmp` against the list endpoints' `name` field).
- `DomainDiscoveryMatcher::normalize()` (Phase 8's bulk-import existence check) — both a driver's `listAccountDomains()` result and GLPI's own stored `glpi_domains.name` are now canonicalized to Punycode before comparison; previously an IDN domain could false-negative as "does not exist yet" and risk a duplicate import.
- Cloudflare's `findZone()` needed no equivalent fix: the zone lookup filters server-side by the `name` query parameter (no client-side string comparison against a returned name exists there).

**New read-only field**: the Domain Manager panel (`domain_panel.html.twig`, via `DomainForm::inject()`) shows a **"Punycode / ASCII form"** line, computed on the fly from `Domain::name` via `IdnNormalizer::toAscii()` — not persisted redundantly. Deliberately **omitted entirely when identical to `name`** (i.e. for a plain ASCII domain), rather than shown as a duplicate value, to avoid clutter on the common case.

### 3.12 Cloudflare Registrar API migration (`/registrar/domains` → `/registrar/registrations`, 2026-07-27)

While adding Cloudflare's `DomainDiscoveryInterface` implementation (§9 Phase 8), checking Cloudflare's current, live OpenAPI spec directly (rather than trusting existing docblocks) found that the Registrar Domains endpoints `CloudflareDriver::fetchLifecycle()` already depended on — `GET accounts/{id}/registrar/domains/{domain}` and its list counterpart — are both marked `deprecated: true`, with an `x-stainless-deprecation-message` giving an explicit EOL of **2026-09-27** and naming the replacement (`domain-search`/`domain-check`/`registrations`). Since this was found only two months ahead of that date, both the new discovery method and the existing `fetchLifecycle()` were migrated together rather than building new code against an endpoint about to be switched off.

**New endpoints**: `GET accounts/{id}/registrar/registrations` (list, used by `listAccountDomains()`) and `GET accounts/{id}/registrar/registrations/{domain}` (single, used by `fetchLifecycle()`). Pagination is **cursor-based** (`result_info.cursor`, empty string = last page) — different from the offset/limit shape IONOS's Domains API and Cloudflare's own old endpoint used.

**Schema changes that mattered** (confirmed against the live spec's `registrar-api_registration` schema, not assumed):
- `registry_statuses` (a free-text, comma-joined list — previously substring-matched for `"hold"` to detect Suspended) is replaced by an explicit, exhaustive `status` enum: `active` / `registration_pending` / `expired` / `suspended` / `redemption_period` / `pending_delete`. `CloudflareDriver::mapStatus()` now matches this directly instead of guessing from free text; `registration_pending` maps to the existing `LifecycleStatus::Pending` case (added earlier for IONOS's mid-transfer state), and `redemption_period`/`pending_delete` both fold into `Expired` — both are post-expiration registry states and no finer-grained case exists.
- `privacy` (a plain bool) is replaced by `privacy_mode`, whose confirmed enum is the literal boolean `false` or the string `"redaction"` — normalized to the same bool-or-null shape `DomainLifecycle` already expects (`$privacyMode === 'redaction' || $privacyMode === true`).
- Everything else `fetchLifecycle()` reads (`created_at`, `expires_at`, `locked`, `auto_renew`) is unchanged in name and shape. The already-established "confirmed absent" fields (`authInfo`/`domainLock`/`domainType`/`dnsSecEnabled`, §9 Phase 7) remain absent on the new schema too — re-confirmed, not just carried over.
- A domain not managed by this account now surfaces as the API's own `success: false` / `"Domain not found"` error, handled for free by `request()`'s existing error path — no separate empty-result check needed like the old endpoint required.

`listAccountDomains()` follows the same shape as Dinahosting's: `previewStatus` is populated with the real `expires_at` value (a free, genuinely useful preview field), unlike IONOS's still-`null` gap.

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
      "patterns": ["ns[0-9]*.ui-dns.de", "ns[0-9]*.ui-dns.com", "ns[0-9]*.ui-dns.org", "ns[0-9]*.ui-dns.biz"],
      "driver": "ionos",
      "source": "… (narrowed to a numeric ns[0-9]* label 2026-07-27: ui-dns.* is a shared United Internet DNS platform — a bare *.ui-dns.{tld} wildcard also matched sibling brands' own labels, e.g. Strato's ns-strato.ui-dns.*, Arsys's ns-arsys.ui-dns.*)"
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
- **Dinahosting's own documented `*.dinahosting.com` hostnames aren't the only ones it actually delegates from** (Phase 16 addendum, found live 2026-07-27): a real Dinahosting-hosted domain showed up as "Unknown provider" with NS hosts `ns[.2-4].gestiondecuenta.com` — Dinahosting's customer control-panel domain, undocumented in its own help article, used for NS delegation on at least some accounts. Added as additional patterns on the same `Dinahosting`/`dinahosting` entry (not a new provider), same "confirmed via live `dig NS`, not vendor docs" precedent as the Strato/Arsys narrowing above.

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
   │             │         • new upstream        → DomainRecord->add() + ownership row (is_managed=1)
   │             │         • changed upstream    → DomainRecord->update() + refresh hash
   │             │                                  (+ ->restore() first if it had gone stale)
   │             │         • gone upstream       → DomainRecord->delete() (native trash bin,
   │             │                                  is_deleted=1; §5.4, revised)
   │             │         • reappeared          → DomainRecord->restore() (native, is_deleted=0)
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
- **§5.4 vanished records go to the native trash bin (revised — supersedes the original comment-marker design, §addendum "Vanished Records Go to the Native Trash Bin"):** the plugin never hard-deletes native records. An upstream-vanished record is soft-deleted via `DomainRecord::delete(['id' => $id])` (no `$force`, so — confirmed `DomainRecord::maybeDeleted()` is `true` on `11.0/bugfixes`, since `glpi_domainrecords` genuinely has an `is_deleted` column — this dispatches `Hooks::PRE_ITEM_DELETE`/soft-deletes rather than `Hooks::PRE_ITEM_PURGE`/hard-deletes), making it show up in GLPI's own native "deleted items" trash view exactly like any other soft-deleted asset. If it reappears in a later sync, it's restored via `DomainRecord::restore(['id' => $id])` (native, clears `is_deleted`) — no re-creation, no duplicate. `LockEnforcer::domainRecordPreDelete()`'s existing `$sync_in_progress` bypass (§0.3) already covers this delete() call for free, since `RecordReconciler::reconcile()` sets that flag around the entire reconciliation; no new lock-bypass code was needed. `RecordReconciler`'s own `is_stale` tracking column was removed entirely in favor of reading `DomainRecord::$fields['is_deleted']` directly as the single source of truth — keeping a second, plugin-owned "is this stale" flag in sync with the native one risked exactly the kind of drift this change was meant to eliminate (e.g. an admin manually restoring a record from GLPI's native trash UI, bypassing the plugin entirely). **Verified live** (not just by code reading): a throwaway console-command test against a real GLPI 11.0.8 instance created a record, ran a sync with it absent from the upstream snapshot (→ `is_deleted` flips to `1`), then ran a sync with it present again (→ restored, `is_deleted` back to `0`, same native record id, no duplicate) — see TESTING.md.
- **§5.5 inactive supplier (addendum "Skip Inactive Suppliers"):** `SupplierConfig::isSupplierActive(int $suppliers_id)` — one shared helper, checked at the top of both `syncRegistrarLeg()`/`syncDnsLeg()`, in `ConnectionTestController` before running a manual test, and implicitly covered in the mass-sync massive action and cron (both just call `SyncEngine::sync()`). An inactive supplier's credentials are never decrypted or sent over the network for this reason — the leg returns `STATUS_SUPPLIER_INACTIVE` immediately, distinct from `unconfigured` (no supplier resolved at all) and `error` (a call was attempted and failed). Logged via `SyncLogger::skip()` — `domainmanager.log` only, never `-errors.log` (not a failure) and never `Log::history()` (a pure no-op skip repeating on every cron run isn't a "milestone" worth the domain's Historical tab, unlike a real sync outcome). UI: the Supplier's own Check Connection button is disabled with a tooltip and the connection diagnostics panel shows an inline notice instead of stale/blank badges (`SupplierTab`/`supplier_tab.html.twig`/`connection_test_panel.html.twig`); the Domain form's Registrar/DNS sync badges show "Supplier inactive" (`DomainForm`'s status maps) instead of a generic error or "Not configured"; the "Sync now" massive action reports `Skipped %s — resolved supplier is inactive` per item rather than counting it as a KO sync error (`MassiveActionHandler`).
- All API payloads validated before persisting: type whitelist, hostname/content length caps, TTL int-range, UTF-8 scrub; unknown record types skipped (read-only scope: A, AAAA, NS, TXT, MX, CNAME).
- Cron loops **active, non-deleted, non-template** domains in batches (default 20/run, `CronTask` param-tunable) with per-domain try/catch isolation; each processed domain increments the task counter.
- **§5.6 fetch-succeeded audit line (addendum "Debug: ... Possible Silent IONOS DNS Failure"):** investigated a report of IONOS DNS reporting `ok`, "0 added, 0 updated, 0 restored, 0 flagged removed, 14 unchanged" on domain `pontecm.com`, worried this could be a fetch failure silently rendered as a clean "unchanged" result. **Confirmed not currently possible, by code walkthrough**: `syncDnsLeg()`/`syncRegistrarLeg()` only ever reach `reconcile()`/the lifecycle-mapping code *after* `fetchZoneRecords()`/`fetchLifecycle()` return without throwing — every real failure path in every driver's `request()` throws `DriverException` first, which aborts the leg to `STATUS_ERROR` before `reconcile()` (or the registrar `$updates` block) ever runs; and a reconciler run against an empty/wrong record set would show up as **all-stale** ("N flagged removed"), not "N unchanged" — a different, already-distinguishable symptom, not the same one. So "14 unchanged" reported here really does mean 14 real records were fetched and matched exactly. Added `SyncLogger::activity()` — a plain `domainmanager.log`-only line, logged immediately after each successful `fetchZoneRecords()`/`fetchLifecycle()` call and before `reconcile()`/`$domain->update()` runs — so this can be *proven from the log itself* on future reports instead of re-deriving it from a code review each time. `SyncLogger::skip()` (§5.5) now delegates to this same method internally.
- **§5.7 "Managed" search option on DomainRecord (addendum "Searchable 'Managed' Field on Domain Records"):** whether a native `DomainRecord` was imported/is tracked by this plugin (`1`) or created manually by a user, never touched by sync (no row at all — equivalent to "unmanaged" for search purposes). **No new table was created** — deliberate deviation from the addendum's own "e.g. `glpi_plugin_domainmanager_recordmeta`" example: `ImportedRecord`/`glpi_plugin_domainmanager_records` already *is* exactly that shape (a plugin table with a unique `domainrecords_id` FK, one row per plugin-tracked record, created only when the reconciler first touches a record) — adding a second table with the identical relationship would have been a redundant abstraction. `is_managed` (tinyint, default `1`) was added to that existing table instead via `Installer::migrateRecordManagedColumn()`, which also **drops the now-superseded `is_stale` column** in the same step (§5.4). `RecordReconciler::createRecord()` sets `is_managed = 1` once, at creation, and never changes it again — it stays `1` through updated/trashed/restored (§5.4); only a real purge (removing the `ImportedRecord` row via `HookHandler::domainRecordPurged()`, `ITEM_PURGE` only) ends the relationship, matching the addendum's rule that editing/going-stale never revokes provenance.
  - **Search option**: registered in the existing `plugin_domainmanager_getAddSearchOptionsNew()` hook (`setup.php`, same mechanism already used elsewhere, §3.7.1) — `PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_MANAGED = 9404`. Uses `'joinparams' => ['jointype' => 'child']`, confirmed directly against `Glpi\Search\Provider\SQLProvider::getLeftJoinCriteria()` on this exact installed `11.0.8` source (not assumed): this jointype builds `LEFT JOIN glpi_plugin_domainmanager_records ON glpi_domainrecords.id = glpi_plugin_domainmanager_records.domainrecords_id` — the correct shape for a satellite table with a direct, non-polymorphic FK back to the parent item. `linkfield` is set explicitly to `domainrecords_id` for clarity even though it also matches what `getForeignKeyFieldForTable('glpi_domainrecords')` would derive by default (confirmed: `substr('glpi_domainrecords', 5) . '_id'`). `datatype => 'bool'`, `massiveaction => false` (this value is plugin-derived, never meant to be set directly via bulk edit). A manually-created record with no `ImportedRecord` row at all reads as `NULL` through the `LEFT JOIN`, which GLPI's `bool` datatype already renders/filters as "No" — no extra default-value handling needed.
  - **Verified two ways**: (1) the option array's exact shape was confirmed correct by directly invoking the registered `plugin_domainmanager_getAddSearchOptionsNew('DomainRecord')` function inside the live container (returned exactly the expected `{id, table, field, linkfield, name, datatype, massiveaction, joinparams}` shape); (2) getting `Search::getCleanedOptions()`/`Search::getDatas()` to pick it up end-to-end inside a bare throwaway console-command test harness hit `Plugin::isPluginActive()`/`Plugin::getPlugins()` returning empty — a limitation of that specific synthetic bootstrap (a real HTTP request through the Kernel initializes the active-plugins list very differently and much more completely than a bare console command does; `bootPlugins()` alone didn't reproduce it either), **not** evidence of a problem with the option itself.
  - **Known real gap, flagged rather than glossed over: `DomainRecord` has no native, `Search::show()`-based list/search page in GLPI 11 core.** Confirmed directly: `front/domainrecord.form.php` (single-item form) exists, but there is no `front/domainrecord.php`, and `bin/console debug:router` shows no generic itemtype-agnostic search route either. The *only* existing UI for browsing multiple `DomainRecord`s is `DomainRecord::showForDomain()` (the "Records" tab on each `Domain`), which builds its own raw query and renders via `components/datatable.html.twig` — it does **not** consult `Search::getOptions()`/the search engine at all, so it cannot show or filter by this new option today. The search option is correctly registered per GLPI's supported plugin mechanism (satisfying the addendum's literal ask, and making the field available to any consumer that *does* call the generic search engine for this itemtype — e.g. Reports, a future dedicated list page), but there is currently no dedicated top-level place in the native UI where a user would go to filter Domain Records by "Managed" specifically. Building one (either a new `front/domainrecord.php`-equivalent, or reworking `showForDomain()`'s tab to route through `Search::show()`) would be a separate, larger change with its own regression surface on core-owned add/edit-record UI already living in that tab — not attempted here without being asked.

---

## 6. Endpoints, hooks & cron registration map

### 6.1 Supplier tab (credentials)
`SupplierTab` registered via `Plugin::registerClass(SupplierTab::class, ['addtabon' => Supplier::class])`. Tab "Domain Manager" renders `supplier_tab.html.twig`: driver `<select>` from `DriverRegistry::getAvailableDrivers()` + per-driver fields toggled by small inline JS:
- cloudflare → Account ID + API Token (§3.10 — Account ID added 2026-07-21, required)
- ionos → API Key + Secret
- dinahosting → Username + Password

Save posts to the standard tab form path handled by `SupplierConfig` (CommonDBTM add/update); payload assembled to JSON and encrypted with `GLPIKey::encrypt()`. Existing secrets are never echoed back (placeholder "●●● saved"); empty submit keeps the stored secret. Access gated by supplier rights: tab visible with READ on the supplier, save with entity-aware UPDATE on it (§8). A credential field's metadata (`DriverRegistry::getCredentialFields()`) may set `'required' => true` to add the HTML `required` attribute (§3.10); no server-side save-time rejection exists for a missing required field today — a field being genuinely required is only surfaced when it's actually *used* (Check Connection, sync), not enforced at save time.

**Driver exclusivity.** Each driver may only ever be assigned to one supplier at a time — the ambiguity of "which supplier's credentials should the sync engine use for this driver" is prevented structurally, not just discouraged. `SupplierConfig::getDriversClaimedByOtherSuppliers(int $suppliers_id)` queries `glpi_plugin_domainmanager_supplierconfigs` for any row with a real (non-`none`) `api_driver` whose `suppliers_id` differs from the one passed in; `SupplierTab::getDriverOptions()` uses it to exclude those drivers from the rendered `<select>` while always keeping `none` and the supplier's own current driver available. The same check (`SupplierConfig::isDriverClaimedByOtherSupplier()`) is re-run server-side inside `prepareDriverAndCredentials()` (both add and update paths) so a direct POST bypassing the dropdown is rejected with a clear error, not just silently trusted. The dropdown is sorted alphabetically by display label with `none` pinned first (`getDriverOptions()`), rather than `DriverRegistry`'s fixed declaration order. There is currently no bulk-edit/massive-action path for this field — `SupplierConfig` has no search/list page, and the existing "Sync now" massive action (§9 Phase 5.5) only syncs domains — so there is nothing else to guard today; a future bulk path for this field must reuse the same two `SupplierConfig` methods and skip colliding items individually rather than failing or overwriting.

The tab is laid out in two columns: the credentials form (left) and an always-rendered **connection diagnostics panel** (right, `connection_test_panel.html.twig`, §3.5) showing a single color-coded status badge, message, HTTP code and checked-at timestamp — defaulting to a gray "Not tested yet" badge. A **"Check Connection"** button (shown whenever the selected driver isn't `none`, gated on the same entity-aware supplier UPDATE) POSTs the **current live form values** (driver + credential inputs, not necessarily saved) to `/plugins/domainmanager/connectiontest/{suppliers_id}` (`ConnectionTestController`, supplier UPDATE + core CSRF) — this lets a user verify a token before ever hitting Save. Empty secret fields fall back to the already-stored value for the same driver, mirroring the save form's "empty submit keeps the stored secret" semantics. The controller runs `DriverFactory::createDriver()` → `ConnectionTestableInterface::testConnection()`, which still returns one `ConnectionTestResult` per capability the driver supports and persists all of them to the supplier's `supplierconfigs` row as before (only if it already exists, §3.5.3) — but the UI itself only ever surfaces **one** combined badge/toast, from `DriverRegistry::getPrimaryTestableCapability()` (§3.5): 'dns' when testable (a real, meaningful login probe for every current driver), else the first remaining capability. This is a display simplification, not a pipeline change — from a user's point of view "Check Connection" is fundamentally one login test, so a per-capability breakdown (e.g. IONOS's `registrar` slot, which is permanently a "not implemented" stub) added confusing noise rather than information. This supersedes the earlier flat, stored-credentials-only "Test credentials" button/`SupplierConfigTestController` design, which never shipped.

**Inactive supplier (§5.5).** `SupplierTab` passes `supplier_active` (the Supplier's own native `is_active` field) into both templates. When false: the Check Connection button renders `disabled` with a tooltip explaining why (`supplier_tab.html.twig`), and `connection_test_panel.html.twig` replaces its entire body with a single inline notice instead of the normal badge/message/timestamp — never stale or blank diagnostics. `ConnectionTestController` re-checks `$supplier->fields['is_active']` itself (409 response) so a direct POST bypassing the disabled button is still rejected before any credentials are decrypted or any request sent. The credentials form itself stays editable either way — an admin can still prepare/update credentials on a currently-inactive supplier before reactivating it; only the outbound test call is gated.

### 6.2 Domain form injection
| Hook | Itemtype | Handler | Purpose |
|---|---|---|---|
| `Hooks::POST_ITEM_FORM` | `Domain` | `DomainForm::inject()` | Renders `domain_panel.html.twig` inside the form: a ribbon-banner header (§6.5, title only — **Update Now** no longer lives here, see §9 Phase 9 addendum), then a one-row native `<table>` — columns Registrar, DNS/NS Provider, Registrar sync, DNS sync, Last sync (a one-row view of the same table shape as the Supplier tab's "Domains" list, §6.5). Registrar/DNS Provider are hyperlinked to the resolved Supplier's own Domain Manager tab when one exists (else plain text/muted fallback, with a link to the Infocom tab for Registrar, §0.1). Below the table: per-leg detail messages and the unsupported/unknown warning, plus the lock-disabling JS and the Update Now button-relocation JS (§9 Phase 9 addendum). When `registrar_status == 'ok'`, a further "Registrar details" section (§9 Phase 7) renders the field/value grid convention (§6.5) for WHOIS privacy / domain lock / transfer lock / auto-renew / DNSSEC (shared badge component, or "Not reported by this driver" when `NULL`) plus domain type and whether a transfer/EPP auth code is on file — the code's *value* is deliberately never rendered, only its presence, since it's a transfer-enabling secret, not display data. Rendered only with `domain` READ. |
| `Hooks::ITEM_ADD` / `ITEM_UPDATE` | `Infocom` | `HookHandler::infocomSaved()` | Mirror `suppliers_id` into `states.registrar_suppliers_id` whenever the Infocom row belongs to a `Domain` (§0.1) — the single source of truth is Infocom's own native field. Also recomputes `states.is_managed` live (§9 Phase 14). |
| `Hooks::PRE_ITEM_UPDATE` | `Domain` | `LockEnforcer` | Strip locked fields w/o unlock right (§0.3). |
| `Hooks::PRE_ITEM_UPDATE` | `Infocom` | `LockEnforcer::infocomPreUpdate()` | Strip a `suppliers_id` change once the Domain has a confirmed working registrar match, w/o unlock right or a bypassing action (§9 Phase 14). |
| `Hooks::PRE_ITEM_UPDATE`, `PRE_ITEM_DELETE`, `PRE_ITEM_PURGE` | `DomainRecord` | `LockEnforcer` | Block edits/removal of plugin-owned records w/o unlock right (conditional per-field for updates, §9 Phase 14). |
| `Hooks::ITEM_PURGE` | `Domain` | `HookHandler` | Cascade-delete state row, ownership rows, locks. |
| `Hooks::ITEM_PURGE` | `Supplier` | `HookHandler` | Delete its `supplierconfigs` row; null out matching `registrar_suppliers_id`/`dns_suppliers_id`. |
| `Hooks::ITEM_PURGE` | `DomainRecord` | `HookHandler` | Delete its ownership row and its `ImportLock` rows (when purged by a right-holder, §9 Phase 14). |

### 6.3 Update Now endpoint
`src/Controller/SyncController.php` — `#[Route('/sync/{domains_id}', name: 'domainmanager_sync', methods: ['POST'], requirements: ['domains_id' => '\d+'])]` ⇒ URL `/plugins/domainmanager/sync/{id}`, route `@domainmanager:domainmanager_sync`.
- Rights: `Session::haveRight('domain', UPDATE)` + `$domain->can($id, UPDATE)` (entity-aware); 403 JSON otherwise.
- CSRF: automatic via core `CheckCsrfListener`; the button JS sends `X-Glpi-Csrf-Token` from the page meta tag (`X-Requested-With: XMLHttpRequest`).
- Runs `SyncEngine::sync()` synchronously, returns `{registrar_status, dns_status, messages, last_sync_date}` JSON; the panel refreshes badges in place.
- **Deliberately never touches RDAP** (§9 Phase 21-26): "Update Now" only re-runs the configured registrar/DNS driver sync. `RdapClient` is invoked *only* from `Cron::cronRdapEnrichment()`'s own throttled tick (one lookup per 10-minute run, §9 Phase 22 "Rate-limit rationale") — never on-demand from a user action. Letting "Update Now" also trigger an RDAP lookup would mean an admin clicking it repeatedly (or a bulk "Review and sync" action across many domains) could burst well past `rdap.org`'s free-tier rate limit; the cron's own tick spacing is the *only* thing keeping this plugin's RDAP usage safe, so nothing else is allowed to call `RdapClient` outside it.

### 6.3.1 Unlink registrar endpoint (§9 Phase 14)
`src/Controller/DomainRegistrarUnlinkController.php` — `#[Route('/domainunlink/{domains_id}', ...)]` ⇒ URL `/plugins/domainmanager/domainunlink/{id}`.
- Rights: `Domain::canUpdate()` on that domain (entity-aware); 403 JSON otherwise. Unlike the Reassign action (§9 Phase 8, Supplier `UPDATE`-only), there's no target supplier to check rights against.
- Clears the Domain's `Infocom::suppliers_id`, wrapping the update in `LockEnforcer::$sync_in_progress` so the new `suppliers_id` lock (§0.3) never blocks it. `HookHandler::infocomSaved()` handles the state mirror reset and Historical-tab logging automatically, same as every other Infocom change.

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

### Entity display
Any time a plugin panel shows which **Entity** an item belongs to, reuse core's own tree/breadcrumb entity badge rather than a plain name — confirmed directly in `src/Entity.php` (`badgeCompletenameLinkById(int $entities_id): ?string` / `badgeCompletenameById(int $entities_id): ?string`) and its real call site `templates/components/itilobject/fields_panel.html.twig`, which picks between the two based on whether the viewer holds `Entity` READ (the link variant adds a clickable last-segment link; the plain variant is the same breadcrumb with no link, for viewers who can see the value but shouldn't navigate to it). Resolve server-side in PHP (`Session::haveRight(Entity::$rightname, READ)`) and pass the already-built HTML string into Twig, rendered with `|raw` (its own `htmlescape()` calls already make it safe). First (and so far only) use: the Domains list's Entity column, §9 Phase 9 addendum.

### Twig/rendering rules (consolidated here from scattered mentions elsewhere in this doc/session)
- Templates live under `templates/`, rendered via `Glpi\Application\View\TemplateRenderer::getInstance()->display('@domainmanager/name.html.twig', [...])` — never `echo`'d raw HTML from a hook callback.
- **`{{ path('@domainmanager:route_name') }}` cannot resolve route names inside a Twig template** — confirmed directly against `Glpi\Application\View\Extension\RoutingExtension::path()` on `11.0/bugfixes`: it only takes its router-first branch when a router was injected, and every Twig environment reachable from a plugin template (`TemplateRenderer::getInstance()`, and `Glpi\Controller\AbstractController::render()` which delegates to the same extension) constructs it with none. The fix is `{{ path('/plugins/domainmanager/literal/path') }}` — a **literal absolute path** — which still correctly gets `$CFG_GLPI['root_doc']` prepended via `Html::getPrefixedUrl()` (so it works on subdirectory installs too); it just never resolves a route *name*. This is exactly the bug behind the "Check Connection"/"Update Now" 404 (CHANGELOG, 2026-07-21) — the original fix used a raw string concatenation with no `path()` call at all, which happened to produce the right URL only because this dev environment is installed at the site root; `supplier_tab.html.twig` and `domain_panel.html.twig` now both correctly wrap the literal path in `path()`.
- Twig auto-escapes by default; only reach for `htmlescape()`/`jsescape()` when emitting HTML/JS outside Twig entirely. Wrap every user-facing string in `__('...', 'domainmanager')`.
- Deep-linking to a specific tab on another item (e.g. a Supplier's own Domain Manager tab, linked from the Domain form) uses GLPI's `forcetab=<Itemtype>$<tab_index>` query convention appended to `getLinkURL()`/`getFormURLWithID()` — verified empirically against this plugin's actual tab identifiers (`Infocom$1`, `GlpiPlugin\Domainmanager\SupplierTab$1`) by inspecting real rendered tab-list HTML, not assumed from a naming pattern.

**Rule for future work:** any new Domain Manager UI surface reuses the ribbon header, the shared badge component, and the table-vs-grid-vs-form choice above — don't introduce a new card/box style. If GLPI core's own convention for a given shape isn't yet confirmed, grep core first (`templates/components/form/`, `css/includes/components/`) before approximating from a screenshot.

---

## 6.6 Plugin configuration (`src/Config/Config.php`, §9 Phase 12)

Before this phase, the plugin had **no plugin-wide settings of any kind** — every existing "config" concept (`SupplierConfig`) is per-supplier API credentials, not a global setting. Phase 12's one setting — "Domain type to apply to imported domains" — is the first, so this section (and the pattern it follows) is new.

- **Storage**: GLPI's native config store (`Config::setConfigurationValues()`/`getConfigurationValues()`/`deleteConfigurationValues()`) under context `plugin:domainmanager`, key `domaintypes_id`. No new database table — deliberately, for a single int setting. `0` means "unset", the same convention the native `DomainType` dropdown itself already uses for "-----".
- **Access class**: `GlpiPlugin\Domainmanager\Config\Config` (`CommonGLPI` subclass, `$rightname = 'config'`), registered via `Plugin::registerClass(Config::class, ['addtabon' => \Config::class])` — it shows as a real, native-feeling tab ("Domain Manager") on Setup > General, not a bespoke standalone page. This is the exact same pattern already established by the sibling TICGAL plugin `uxia` (`GlpiPlugin\Uxia\Config\Config`) — followed here for consistency rather than inventing a different config-page convention.
- **`Hooks::CONFIG_PAGE`**: `$PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['domainmanager'] = 'Config'` (in `setup.php`) makes the plugin's row on Setup > Plugins show a "Configure" gear icon, resolving to `GET /plugins/domainmanager/Config` (`Controller\ConfigController::index()`), which just redirects to the Setup > General tab above — the actual form lives there, not on a separate page.
- **Save**: `POST /plugins/domainmanager/Config/Save` (`Controller\ConfigController::save()`), gated by `Session::checkRight('config', UPDATE)` both for the GET redirect and the POST save (this is core's own general-config right — every super-admin profile already has it, and it's the correct scope for a plugin-wide, not per-supplier/per-domain, setting). A submitted `domaintypes_id` that no longer resolves to a real `DomainType` (e.g. it was since deleted) is defensively treated as `0`/unset rather than stored as a dangling FK. The change is logged via `PluginLogger::activity()` — not `Log::history()` against some unrelated itemtype, since a plugin-wide setting has no genuinely-relevant itemtype with a visible Historical tab to attribute it to (the sibling `uxia` plugin's own config save doesn't log via History either, for the same reason).
- **Applied**: only by `Controller\DomainImportController` (Phase 8's bulk-import path), at `Domain::add()` time, and only if the configured value is `> 0` — the `domaintypes_id` key is omitted from the `add()` input entirely when unset, so an unset config produces a domain byte-for-byte identical (as far as `Type` goes) to one created by hand. **No other Domain-creation path exists in this plugin** (re-verified for this phase — `Cron.php`/`SyncController.php`/`MassiveActionHandler.php` only ever `getFromDB()` an existing `Domain`, never `add()`), so this is the only call site that needed updating.
- **Never re-applied on sync**: `Type` was never part of `SyncEngine`'s per-sync `update()`/`ImportLock` field set (§0.3, §9 Phase 8) — a domain's registrar/DNS sync has never touched `domaintypes_id` — and this phase doesn't add it there either. An admin's later manual change to `Type` is never reverted by a subsequent sync, by construction, not by a new guard.
- **`Type` stays out of the locked-fields list, deliberately** — it never was in `ImportLock`'s tracked Domain fields (`name`, `is_active`, `date_domaincreation`, `date_expiration`, §0.3/§9 Phase 8), and this phase doesn't add it: the config only decides the *initial* value at creation, never something a sync needs to defend against user edits, so there's nothing here for a lock to protect.
- **Upgrade default (pre-Phase-12 installs)**: `Installer::seedDomainType()` seeds the config value to the id of the already-seeded "Internet Domain" `DomainType` **only if** that type already existed *before* this install/activation call — meaning a prior version of the plugin (which unconditionally force-assigned it) already created it. A brand-new install has no such pre-existing type, so it defaults the config to `0`/unset instead. `Config::seedDefault()` itself is idempotent in the usual sense too — it only ever sets the value once (checked via whether the config key exists at all in `glpi_configs`, not whether it's `0`), so it never overwrites an admin's own later choice, including an explicit "clear it back to unset."
- **Uninstall**: `Config::uninstall()` (called from `Installer::uninstall()`) purges the `plugin:domainmanager` config context entirely — same residue-free rule as every other phase.

---

## 7. Install / uninstall (`src/Installer.php`, driven from `hook.php`)

**Install (idempotent, upgrade-aware via `Migration(PLUGIN_DOMAINMANAGER_VERSION)`):**
1. Create the four tables (§2) if missing; `Migration` field/key helpers for future upgrades.
2. Seed Domain Type "Internet Domain" (by-name check), then seed the §9 Phase 12 "domain type to apply to imported domains" config default (`Config::seedDefault()` — the previously-seeded type's id on an upgrade from a pre-Phase-12 install, `0`/unset on a fresh install; see §6.6).
3. Ensure the six `DomainRecordType` names exist.
4. `Migration::addRight('domainmanager:unlock_imported', UNLOCK_RIGHT, ['config' => UPDATE])` — granted by default to profiles holding config UPDATE.
5. Register the cron task (§6.4).
6. `$migration->executeMigration()`.

**Uninstall (zero residue):**
1. `Migration::dropTable()` × 4 plugin tables.
2. `CronTask::unregister('domainmanager')` (+ its `CronTaskLog` rows go with it).
3. `Config::uninstall()` purges the `plugin:domainmanager` config context (§6.6, §9 Phase 12).
4. `ProfileRight::deleteProfileRights(['domainmanager:unlock_imported'])`.
5. Delete plugin `DisplayPreference` rows for plugin itemtypes (defensive even though none are registered by default).
6. Native data is **left intact by design**: domains, domain records, the seeded Domain Type and record types remain (they are the user's inventory). Plugin lock rows live in plugin tables, so dropping them removes every locking artifact. *(The brief's "locked-field entries created by the plugin" are exactly these rows — nothing is ever written to `glpi_lockedfields`, per §0.3.)*

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
| Change the Registrar (Infocom `suppliers_id`) once confirmed (§9 Phase 14) | **`domainmanager:unlock_imported`**, or native Domain `UPDATE` via the new Unlink action, or native Supplier `UPDATE` via the existing Reassign action | `LockEnforcer::infocomPreUpdate()`; `Controller\DomainRegistrarUnlinkController`; `Controller\DomainRegistrarReassignController` |
| Edit/delete/purge plugin-imported DomainRecords | **`domainmanager:unlock_imported`** (+ native `managed_domainrecordtypes` gate still applies, §0.4) | `LockEnforcer` |
| Grant the plugin right | native `profile` UPDATE | `src/Profile.php` tab (`displayRightsChoiceMatrix` + `ProfileRight`) |
| See/change the "Domain type to apply to imported domains" setting | native `config` UPDATE (this plugin's only genuinely global, not per-supplier/per-domain, setting — unlike the supplier-credentials row above, native `config` is the correct scope here) | `Config\Config` tab on Setup > General + `Controller\ConfigController` (§6.6, §9 Phase 12) |

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
   - **Read-only "Domains" listing on the Supplier's Domain Manager tab** (`templates/supplier_domains_list.html.twig`, `DomainState::getDomainsForSupplier()`), so a supplier's involvement is visible without hunting through every `Domain` item individually. Each row: the domain name as a standard GLPI itemtype hyperlink (`Domain::getFormURLWithID()`), which role(s) this supplier plays for it (Registrar / DNS, each independently), and — when this supplier is the registrar but the domain's *detected* NS turned out to be a different provider — which one. Status badge reuses the sync engine's existing DNS classification verbatim (§5, no new logic), collapsed to three buckets: **plugin-managed** (`dns_suppliers_id` set), **known, unmanaged (yet)** (`dns_status` is `unsupported` or `unconfigured`), or **unknown** (`dns_status='unknown'`). Purely a read-only report — no add/edit/delete, no new rights. A fourth **Entity** column (added §9 Phase 9 addendum) shows each domain's own `entities_id` via core's own tree/breadcrumb entity badge, since this instance is multi-entity and the list previously gave no way to tell which entity a listed domain actually belongs to without opening it.
     **Union of two independently-sourced halves, corrected 2026-07-21 after a real undercount bug** (reported as "the Domains panel shows 1 domain, Supplier's native Items tab shows 3 for the same supplier" — confirmed live, not assumed): the query originally `INNER JOIN`ed `glpi_plugin_domainmanager_states`, so a domain only appeared once a sync/detection had already produced a state row — invisible until then, even though its registrar link was already real and immediately knowable. Fixed to read the **registrar** half live and directly from `glpi_infocoms.suppliers_id` (`LEFT JOIN`, itemtype=Domain) — GLPI's own native, always-authoritative link, the same one behind the Supplier's native "Items" tab count, requiring no sync/state row to exist at all — while the **DNS/NS** half stays genuinely sync-dependent (`states.dns_suppliers_id`, which cannot be known without at least one real NS-detection run) and must not suppress a row the registrar half already justifies. `registrar_status` is only trusted from the state row when that row's *own* `registrar_suppliers_id` mirror actually agrees with the live Infocom value just queried — a domain can have a real state row (DNS side populated) while its Infocom assignment predates or otherwise missed the mirror below, in which case the row's `registrar_status` describes some other (often nonexistent) registrar, not this one; showing it next to this supplier's real name would be actively misleading, not merely stale, so it falls back to `STATUS_NEVER` ("not yet checked *for this supplier*") instead.
   - **Registrar field on the Domain Manager panel is now read-only**, mirroring Infocom's native "Supplier" field instead of being an independently-editable plugin dropdown — see §0.1 (revised) and §6.2. Rendered via GLPI's own `fields.readOnlyField()` macro (`components/form/fields_macros.html.twig`) for visual/structural parity with the rest of the native Domain form, with a `forcetab=Infocom$1` deep link to where it's actually set.
   - **`SyncEngine::sync()` now also resolves the registrar live from Infocom, corrected 2026-07-21** (same investigation as the undercount fix above, same root cause) — `syncRegistrarLeg()` used to read only the state row's own `registrar_suppliers_id` mirror, never Infocom directly, and the sync's own state upsert never wrote that field back either. So a domain whose Infocom registrar assignment predates/missed `HookHandler::infocomSaved()`'s mirror stayed permanently "unconfigured" no matter how many times it was synced — the "run a sync" call-to-action below would have been hollow for exactly the domains it targets. Now `sync()` reads Infocom live once at the top, uses that value for the registrar leg, and writes it into the state upsert's `registrar_suppliers_id` — self-correcting the mirror on every sync, in addition to `HookHandler`'s existing reactive correction on every Infocom save (defense in depth, not a replacement for it — the Domain form panel still reads the mirror directly, so keeping it fresh via both paths matters).
   - **A recommendation banner** at the top of the "Domains" panel when any registrar-linked domain hasn't actually been verified (`registrar_suppliers_id > 0` from the live Infocom read, but the mirror check above says unverified) — e.g. exactly the case a fresh install of this plugin on an existing GLPI instance with domains already assigned to suppliers would hit on every one of them, until each gets its first sync. Links to Domain's native search, pre-filtered to this supplier — originally via a plugin-owned 2-hop `dropdown` search option (`PLUGIN_DOMAINMANAGER_SO_DOMAIN_REGISTRAR = 9403`), **dropped and replaced with core's own native search option id `53`** (`glpi_suppliers.name` under "Financial and administrative information", `Infocom::rawSearchOptionsToAdd()`) once later live testing found that native option already existed and this one was a pure duplicate — see §3.7.1 for the full correction.
   - **A native GLPI Massive Action** ("Sync now (Domain Manager)") on `Domain`, registered via `Hooks::USE_MASSIVE_ACTION`/`plugin_domainmanager_MassiveActions()` (`hook.php`) → `MassiveActionHandler` — lets the banner's link land on a multi-select-ready native search instead of asking an admin to open each domain individually. Batches `SyncEngine::sync()`, `MassiveAction::itemDone(..., ACTION_OK|ACTION_KO|ACTION_NORIGHT)` per item so one domain's failure never aborts the rest (mirrors the per-leg isolation §5 already has *within* one domain's own sync, applied across domains here) — a domain counts as KO if a leg's own status is `STATUS_ERROR`, or (with its own distinct message, §5.5) `STATUS_SUPPLIER_INACTIVE`; `unconfigured`/`unsupported`/`unknown` outcomes are expected, not failures. **Two core-required static methods, both verified against `src/MassiveAction.php` directly rather than assumed:** `processMassiveActionsForOneItemtype(MassiveAction, CommonDBTM, array $ids)` (the actual batch processor) and `showMassiveActionsSubForm(MassiveAction): bool` (returning `false` falls back to core's own plain "Post" submit button, since this action needs no extra fields) — omitting the second one is a **fatal `UndefinedMethodError`, not a silent no-op** (confirmed live: `MassiveAction::showSubForm()` unconditionally calls it on whichever processor class the action dropdown resolves to).
   - **A tab count badge** ("Domain Manager 3", matching the native "Items 3" badge for the same supplier) via `createTabEntry()`'s `$nb` parameter, computed from the exact same `DomainState::getDomainsForSupplier()` call the panel itself renders from — deliberately not a separate/simpler count, so the two numbers can never drift apart. Gated behind `$_SESSION['glpishow_count_on_tabs']`, the same session preference core's own tab-count badges check (e.g. `Document_Item::getTabNameForItem()`), to avoid an unconditional extra query on every tab-list render for users who've turned tab counts off.
6. **Phase 6 (post-1.0)** — bulk-import domains from a connected registrar/DNS supplier's account. Today the plugin only ever enriches a GLPI `Domain` item that an admin already created by hand (`Cron.php`/`SyncController.php` both `getFromDB()` an existing row and skip/404 otherwise — see §5); a domain that exists at the provider but has no corresponding `Domain` item is invisible to it, with no discovery path. **Entity assignment decided:** newly-discovered domains are created under a single configurable default entity (a plugin-setup-level setting, not per-supplier), and the SysAdmin is expected to move them to their real entity afterward via GLPI's native entity-transfer feature — same pattern GLPI already uses for other auto-discovered assets, so no bespoke per-import entity picker needs building. Gate behind v1.0.
7. **Phase 7a (implemented 2026-07-21)** — richer registrar domain metadata in the inventory. Implementing the real `IonosDriver` registrar pipeline (§3.9) surfaced that its Domains API response (`domainLarge`) carried several fields this plugin fetched but discarded, since `DomainLifecycle` only modeled `registrationDate`/`expirationDate`/`status`: **`authInfo`** (EPP transfer/auth code), **`privacyEnabled`**, **`domainLock`**, **`transferLock`**, **`autoRenew`**, **`domainType`**, **`dnsSecEnabled`**.

   **Per-driver support, confirmed live against each driver's real API (never guessed) before implementing anything, per this section's own original mandate:**

   | Field | Cloudflare | Dinahosting | IONOS |
   |---|---|---|---|
   | `authInfo` | confirmed absent — no such field anywhere in `registrar-api_domain_properties` (Cloudflare's live OpenAPI spec, re-read 2026-07-21) | `Domain_GetAuthcode` confirmed to exist as a real command (live-probed 2026-07-21: returns the account-wide auth-error envelope, not "Unknown command" — the same signal already used elsewhere in this driver to confirm real commands); response shape inferred by direct analogy to `Domain_GetExpirationDate`/`Domain_GetRegistrationDate`'s already-trusted `Domain_Get*` → single-string-in-`data` convention | confirmed supported (`domainLarge.authInfo`, string) — IONOS's own doc notes it's itself optionally absent (hidden when Domain Guard is active, unset by default for .eu/.de) |
   | `privacyEnabled` | confirmed supported (`domain_properties.privacy`, bool — exact semantic match) | command exists (`Domain_WhoisPrivacy_Get`, live-probe-confirmed real) but **no documented example response body anywhere** — left `null`, not guessed | confirmed supported (`domainLarge.privacyEnabled`, bool) |
   | `domainLock` | confirmed absent — Cloudflare's Registrar API models only **one** lock concept (`locked`), not a separate general-edit lock distinct from transfer protection; mapped to `transferLock` below instead, so this stays a genuine, honest structural gap for this driver, not an unconfirmed one | command exists (`Domain_Status_IsLocked`/`Domain_Status_Get`, live-probe-confirmed real) but shape unconfirmed — left `null` | confirmed supported (`domainLarge.domainLock`, bool) |
   | `transferLock` | confirmed supported (`domain_properties.locked`, bool, described as "Shows whether a registrar lock is in place... to prevent unauthorized transfers") | same gap as `domainLock` above — left `null` | confirmed supported (`domainLarge.transferLock`, bool) |
   | `autoRenew` | confirmed supported (`domain_properties.auto_renew`, bool — exact semantic match) | `Billing_Autorenew_GetAll` exists but is explicitly a bulk *list* endpoint ("GetAll"), not confirmed to be single-domain-scoped the way the other commands are — a different, worse kind of uncertainty than a mere undocumented body shape; left `null` | confirmed supported (`domainLarge.autoRenew`, bool) |
   | `domainType` | confirmed absent — no such field or equivalent concept anywhere in the schema | confirmed absent — no command anywhere in the documented command index maps to this concept | confirmed supported (`domainLarge.domainType`, enum `DOMAIN`\|`X_DOMAIN`\|`GENERIC_DOMAIN` — the spec names these values but never documents what distinguishes them; the Domain form cosmetically title-cases the raw value rather than inventing a meaning for `X_DOMAIN`/`GENERIC_DOMAIN`) |
   | `dnsSecEnabled` | confirmed absent **from the Registrar API specifically** — Cloudflare does have DNSSEC data, but only at the DNS-zone level (a different endpoint/product boundary), out of scope for this registrar-metadata DTO | confirmed absent — no DNSSEC command found anywhere in the command index | confirmed supported (`domainLarge.dnsSecEnabled`, bool) |

   All 7 fields were kept (none dropped for being "1-of-3"): the bar applied throughout is **confirmed-supported or confirmed-absent (an honest, verified story, exactly like `registrationDate`'s existing null-for-IONOS precedent) vs. genuinely unconfirmed (never guessed)** — not a raw vote count across drivers. Every Cloudflare/Dinahosting `null` above is either a real, spec-verified structural absence, or an explicitly flagged pending-live-verification gap; nothing was invented.

   - **DTO**: added directly to `DomainLifecycle` (`src/Dto/DomainLifecycle.php`) as 7 new nullable readonly properties, rather than splitting into a second DTO/interface method — for Cloudflare and IONOS, all 7 fields already come back in the exact same API response `fetchLifecycle()` already fetches for `registrationDate`/`expirationDate`/`status` (no extra request), and Dinahosting already makes multiple calls per `fetchLifecycle()` invocation (`Domain_GetExpirationDate` + `Domain_GetRegistrationDate`), so adding one more (`Domain_GetAuthcode`) fits its existing multi-call pattern rather than introducing a second orchestration path. `RegistrarDriverInterface::fetchLifecycle()`'s signature is unchanged.
   - **Persistence**: 7 new nullable columns on `glpi_plugin_domainmanager_states` (§2), **not** new native `glpi_domains` columns and **not** `ImportLock`-protected — see §2's own reasoning for why (no native form field exists for these, so nothing for a lock to protect; this directly answers this section's original open question on lock-semantics parity). `SyncEngine::syncRegistrarLeg()` populates them from a successful `DomainLifecycle` fetch; a leg that doesn't run/fails resets them to `null` in the same upsert, exactly matching how `registrar_message` already behaves on failure (state reflects the *last* sync outcome, not the best-ever-seen data).
   - **UI**: a new "Registrar details" section on the Domain form panel (§6.2), shown only when `registrar_status == 'ok'` — the field/value grid convention (§6.5), shared badge component for the 5 boolean-ish fields (`Not reported by this driver` text instead of a badge when `null`), plain formatted text for `domainType`, and a presence-only indicator for the auth code (see below). Not added to the existing one-row status table (§6.2) — a 12-column single row would be unreadable; this is exactly the "new section" alternative §9 originally floated.
   - **Security**: `authInfo` (the EPP transfer/auth code) is persisted but **deliberately never rendered** in the Domain form UI, even when populated — it's a transfer-enabling secret equivalent to a password, viewable by anyone with plain `domain` READ (a much broader audience than credential-management rights), not ordinary display data. The panel only ever shows whether one is on file, never its value; no reveal control exists in this phase (a real transfer workflow, if one is ever built, is the point where "reveal" would become an actual, scoped need — not before).
   - **Verified live end-to-end**, not just against specs: a real sync against a real IONOS-registered domain and a real Dinahosting-registered domain (both already-configured test suppliers on `glpi-claude`) correctly populated `authInfo` for both drivers (confirming the Dinahosting `Domain_GetAuthcode` shape inference was right) and `transferLock`/`autoRenew`/`domainType`/`dnsSecEnabled` for IONOS, with the rendered "Registrar details" section matching exactly. One real, live-observed data point worth recording: **IONOS's own `privacyEnabled`/`domainLock` fields, despite being documented as plain (non-optional) booleans in the spec, came back absent from the actual response for a real domain** — the defensive `isset()`-per-field handling already used for `authInfo` (spec-documented as optional) turned out to matter for the "always present" fields too; don't assume a schema's lack of an "optional" marker guarantees the key is always sent.
   - **Phase 7 addendum "Searchable 'Proxy Status' Field for CDN-Proxied Records" — implemented.** Extended `glpi_plugin_domainmanager_records` (§5.7 — the same table `is_managed` lives on, not a separate one) with a second field, `is_proxied` (`ZoneRecord::$isProxied` → `RecordReconciler` → `Installer::addRecordProxiedColumn()`), a genuine **three-state** value: `1` proxied, `0` DNS-only-but-proxy-eligible, `NULL` not applicable. Refreshed on **every** sync for every matched record regardless of which reconcile branch ran (unlike `record_hash`-gated fields, a proxy toggle can change with no other content change, so it must never be gated behind "content changed").
     - **Eligibility is read live from Cloudflare's own per-record `proxiable` flag, not a hardcoded type list** — a deliberate improvement over this addendum's original sketch (which assumed only A/AAAA/CNAME, based on third-party docs). Confirmed directly against Cloudflare's current API docs (`developers.cloudflare.com/api/resources/dns/subresources/records/`) that **every** DNS record type's response schema carries both `proxied` (current state) and `proxiable` (`"whether the record can be proxied by Cloudflare or not"`) — the API's own live, per-record answer, matching this plugin's established "the provider's own answer wins over a guessed static list" principle (same reasoning already applied to Phase 8's registrar-mismatch handling). `CloudflareDriver::fetchZoneRecords()` sets `is_proxied` from `proxied` only when `proxiable` is true; `null` otherwise. IONOS/Dinahosting never populate it (always `null` — no `proxiable` concept exists for either).
     - **Tri-state search rendering — verified live end-to-end, and the original "promising lead" was only half right; corrected here rather than left stale.** Real test rows were created (`is_proxied` = `1`, `0`, `NULL`) and filtered through GLPI's actual search engine (`ajax/search.php?action=display_results`, a real authenticated HTTP round trip, plus the raw SQL captured via MariaDB's general query log to see exactly what the search engine built):
       - `equals`/`notequals` (Yes/No) are **exactly correct** — `field = 1` / `field = 0`, naturally excluding `NULL` rows via ordinary SQL three-valued logic. Verified live: filtering for Yes returned only the one proxied test row, No returned only the one non-proxied-eligible row, neither leaked into the other or included the `NULL` row.
       - **Display rendering is correctly three-state**: confirmed in `SQLProvider`'s `case "bool":` display formatter that it only calls `Dropdown::getYesNo()` when the raw value, cast to string, is non-empty — a `NULL` value casts to `''` and skips the call entirely, rendering a genuinely blank cell; `0` casts to `'0'` (non-empty) and correctly renders "No". No coercion of `NULL` to "No" happens.
       - **`empty` ("is empty") does NOT cleanly isolate `NULL`-only — confirmed via live-captured SQL, not assumed.** `SQLProvider.php`'s `case "bool":` WHERE-builder deliberately falls through (comment: `// no break here : use number comparaison case`) into the same handling as `integer`/`decimal`/`count`/etc., whose `empty` means **"0 or NULL" together** (captured real SQL: `` (`is_proxied` = 0) OR `is_proxied` IS NULL ``). This is confirmed, deliberate core GLPI behavior for the `bool` datatype generally, not specific to this plugin's join — and there is no available override: the `addWhere()`/`Hooks::AUTO_ADD_WHERE` per-search-option override points only exist for itemtypes the plugin itself owns (checked via `isPluginItemType($itemtype)`), and `DomainRecord` is a core itemtype, so neither is reachable for a search option a plugin merely *adds* to it. **Accepted as a real, minor, documented limitation** rather than forced: the two individually-useful queries (show proxied / show eligible-but-not-proxied) both work with full precision; only "show me records where proxying doesn't apply at all" conflates with "show me records marked not-proxied," a much lower-value query this plugin does not attempt to fix via core.
     - Label **"Proxy status"** (no plugin-name prefix, same convention as "Managed", §5.7). Search option `PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_PROXY = 9405` (`setup.php`), same single-hop `'child'` jointype as `PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_MANAGED`.
     - **Also caught live, unrelated to this addendum's own logic**: after adding this new search option, it and the pre-existing "Managed" option (9404) both silently failed to register at all — `plugin_domainmanager_getAddSearchOptionsNew()` wasn't being invoked, confirmed by direct instrumentation. Root cause: a preceding `bin/console glpi:plugin:install --force` (used to apply this phase's migration) does not, by itself, refresh a currently-Enabled plugin's runtime hook registration on this GLPI version — a full deactivate/reactivate cycle was required before either search option (old or new) worked again. Not a bug introduced by this change, but a real environment gotcha worth recording: **after any migration-only reinstall, verify plugin hooks (search options, tab registration, etc.) are actually live again — don't assume `--force` alone is equivalent to a deactivate/reactivate cycle.**
8. **Phase 8 (IONOS slice implemented 2026-07-22)** — bulk-import undiscovered registrar domains. Scenario: a configured registrar Supplier's real account has domains that don't exist as native `Domain` assets yet, with no way today to see the gap or bulk-create them. **Scoped down deliberately, same pattern as Phase 5's batching**: IONOS-only driver support, import-modal-only "Registrar mismatch" surface, and the NS-registry additions below all deferred to their own follow-up addenda rather than attempted in one pass.
   - **New capability interface**, `Contract/DomainDiscoveryInterface` (`listAccountDomains(): DiscoveredDomain[]`), and a minimal `DiscoveredDomain` DTO (`name` + an optional driver-specific `previewStatus`). **`IonosDriver` implements it**: its Domains API already has a proven list endpoint (`GET /v1/domainitems`, the same call `findDomainId()` already made) — the cheapest, lowest-risk slice. `IonosDriver::listAccountDomains()` reuses `findDomainId()`'s exact pagination shape (`limit`/`offset`, `requestDomainsApi()`), just without its `name` filter; `previewStatus` is left `null` — the `domainitems` list response's fields beyond `id`/`name` weren't re-verified for a useful preview value in this pass, a real, honest gap, not a guess. **`DinahostingDriver` implements it too (added 2026-07-27)**: `Services_GetDomains` (Services category, no parameters) was confirmed as a real command via the docs site's own command index — but, same documentation gap as every other Dinahosting command in this plugin, the site provides no example response body, and its "Simulator" turned out to actually round-trip a live API call rather than fake one (confirmed: fake credentials returned a real `responseCode=2200` auth error in the exact envelope shape this driver already parses), so it could only demonstrate the failure shape, not a success one. The real success shape — `{"responseCode":1000,"message":"Success.","data":[{"domain":"...","tld":"...","startDate":"YYYY-MM-DD","endDate":"YYYY-MM-DD"}, ...]}` — was confirmed with one live, read-only call against a real account (Óscar's explicit one-time permission, same precedent as the earlier live driver-verification work in §3.8/§3.9). Unlike IONOS, the response is a **flat, unpaginated list** (no `count`/`limit`/`offset` fields at all) — `DinahostingDriver::listAccountDomains()` needs no pagination loop. `previewStatus` is populated with the real expiration date (`endDate`) — a genuinely useful preview this API happens to include for free, rendered in `domain_discovery_modal.html.twig` next to "Not yet in GLPI" for domains not yet in GLPI. **`CloudflareDriver` implements it too (added 2026-07-27)**, and this slice surfaced a real finding: the `GET accounts/{id}/registrar/domains` endpoint hinted at above does exist, but confirming it against Cloudflare's current, live OpenAPI spec (not assumed) found it — and its single-domain counterpart `fetchLifecycle()` already used — both marked `deprecated: true`, EOL **2026-09-27**, with an explicit pointer to a newer Registrations API as the replacement. Rather than build new discovery code against an endpoint two months from being switched off, both `listAccountDomains()` (new) and `fetchLifecycle()` (migrated) now use `GET accounts/{id}/registrar/registrations` (list, cursor-paginated via `result_info.cursor`) and `GET accounts/{id}/registrar/registrations/{domain}` (single). The new schema isn't a pure rename: `registry_statuses` (free-text, substring-matched for `"hold"`) is replaced by an explicit `status` enum (`active`/`registration_pending`/`expired`/`suspended`/`redemption_period`/`pending_delete`, mapped directly — `mapStatus()` no longer guesses), and `privacy` (plain bool) is replaced by `privacy_mode` (`false`/`"redaction"`), normalized to the same bool-or-null shape `DomainLifecycle` expects. `previewStatus` is populated with the real expiration date, same pattern as Dinahosting.
   - **`DriverFactory::forDiscovery(SupplierConfig): ?DomainDiscoveryInterface`** — nullable-return (not throwing), unlike `forRegistrar()`/`forDns()`: "this driver has no discovery support" is the common case here, not an error. Builds the real driver instance and checks `instanceof DomainDiscoveryInterface` — a genuine structural check, never a per-driver allowlist, so the "Import Domains" button (`SupplierTab`) will appear automatically for any future driver that implements the interface with no extra UI wiring.
   - **Matching**: `Service/DomainDiscoveryMatcher` enriches the raw discovered list against GLPI's own inventory with one batched query (`glpi_domains` LEFT JOIN `glpi_infocoms`, all non-deleted/non-template rows loaded and matched in PHP against a normalized — `strtolower(trim())` — name map, rather than building a case-insensitive SQL `WHERE`, for which this plugin has no existing raw-expression precedent). Deliberately **global** (cross-entity), per spec — this answers "does this domain exist anywhere in GLPI", not "is it visible to me" (unlike `DomainState::getDomainsForSupplier()`'s entity-scoped listing, §9 Phase 5.5). `mismatch = exists && existing_suppliers_id !== $suppliers_id` — this single condition also covers "not yet linked to any registrar" (`existing_suppliers_id === 0`), worded contextually in the template ("Set registrar to X" vs "Reassign registrar to X") rather than as two separate states.
   - **UI**: an "Import Domains" button on the Supplier tab (`supplier_tab.html.twig`), gated on `discovery_supported` (computed in `SupplierTab::showForSupplier()` via `DriverFactory::forDiscovery()`) **and** `can_edit` **and** the supplier being active (disabled+tooltip when inactive, same convention as Check Connection — an inactive supplier's credentials must never be used for an outbound call, §addendum "Skip Inactive Suppliers"). The modal itself is built with core's own `Ajax::createModalWindow()` (`SupplierTab::showForSupplier()`), the same helper `Html::showMassiveActions()` uses for every native massive-action dialog — **not** a hand-rolled `fetch()` + `innerHTML` modal, which is what this shipped with initially and was caught live on `glpi-claude` as three separate-looking symptoms that were actually one root cause: the modal "didn't look like a GLPI modal" (a custom ribbon-header div instead of core's real `components/modal.html.twig` chrome), the entity dropdown "didn't show all the entities," and its own "+"-style widget affordance didn't work. Root cause: assigning fetched HTML via `element.innerHTML = ...` inserts `<script>` tags into the DOM **without ever executing them** (a genuine, easy-to-miss browser behavior — different from jQuery's DOM-manipulation methods, which do evaluate embedded scripts) — so neither `Entity::dropdown()`'s own select2/AJAX-search initialization script nor this modal's own reassign-button click handler ever actually ran; only whatever static HTML happened to be present worked at all. Fixed by using `Ajax::createModalWindow()`, whose generated JS loads the URL via jQuery's `.load(url, fields)` into `.modal-body` on `show.bs.modal` — jQuery's own AJAX/DOM helpers *do* evaluate response scripts, and `components/modal.html.twig` provides the real native header/dialog chrome for free. One consequence worth noting, not a workaround: `.load()` always passes a `fields` object (even empty `{}`), which makes jQuery issue the request as **POST**, not GET — so `DomainDiscoveryController`'s route accepts both (`methods: ['GET', 'POST']`); CSRF is still handled for free via core's global `$(document).ajaxSend()` handler, which attaches the CSRF header to every jQuery AJAX call automatically. `domain_discovery_modal.html.twig` itself was correspondingly simplified to render only what belongs inside `.modal-body`/`.modal-footer` (no more hand-rolled header), and the trigger button calls `domainmanager_import_modal.show()` directly (matching the exact convention `Html.php`'s massive-action link uses) instead of Bootstrap's `data-bs-toggle`/`data-bs-target` attributes, since the modal element itself is created and appended to `<body>` dynamically by the generated script, not present in the initial page markup. The modal body itself: an `Entity::dropdown()`-rendered entity selector applying to the whole batch, and a plain `<table>` (same convention as `supplier_domains_list.html.twig` — no pagination/search UI added, not needed at this scale) — not present → checkable (checked by default, a "select all not-already-present" header toggle included); present, no mismatch → checkbox disabled/grayed, "Already exists" with a link; present, mismatch → same plus a "Registrar mismatch" badge (`text-bg-warning`, reusing the existing DNS "unmanaged" badge color rather than inventing a new one, since a real distinct treatment was explicitly deferred, see below) and an inline "Reassign/Set registrar to X" button.
   - **Import**: the checkbox table is a real `<form>` POSTing to `POST /plugins/domainmanager/domainimport/{suppliers_id}` (`DomainImportController`) — a full-page submit-and-redirect-back (matching `SupplierConfig`'s own form convention), not a fetch, since this is a real multi-field bulk submission. Existence is **re-checked at submit time** (`DomainDiscoveryMatcher::loadExistingDomains()`, reused rather than N+1 per-name queries) rather than trusting the modal's snapshot — a concurrent change since it was rendered must not silently create a duplicate; already-existing names are skipped and counted, never treated as a batch failure. Creation sets `name`/`entities_id`/the seeded "Internet Domain" `domaintypes_id`, then `Infocom::add(['itemtype' => Domain::class, 'items_id' => ..., 'suppliers_id' => ...])` — this alone triggers the pre-existing `HookHandler::infocomSaved()` hook (already registered for `Hooks::ITEM_ADD` on `Infocom::class`), which both corrects the state row's registrar mirror **and** logs the change on the Domain's own Historical tab for free — no extra code needed for either. Deliberately pre-locks nothing (locking only ever starts after a domain's first real sync, an existing rule, §0.3/§5). **Initial sync enqueued**: `SyncEngine::sync()` is called synchronously per created domain (same call `SyncController`/`MassiveActionHandler` already make), wrapped in try/catch — a sync failure is logged and counted but never fails the import itself, matching `MassiveActionHandler`'s per-item isolation. A summary message ("N imported — M already existed — S could not be synced yet") is queued via `Session::addMessageAfterRedirect()` before redirecting back to the supplier's Domain Manager tab (`forcetab`, same convention as the Infocom deep link in `domain_panel.html.twig`).
   - **Reassign**: `POST /plugins/domainmanager/domainreassign/{domains_id}` (`DomainRegistrarReassignController`, invoked via fetch from the modal's inline button, like Check Connection) finds or creates the Domain's `Infocom` row and sets its `suppliers_id` — again, `infocomSaved()` handles the state mirror and Historical-tab logging automatically, nothing extra written here. **Auth is deliberately Supplier `can(UPDATE)` only, not `Domain::canUpdate()`** — "same rights check as the credentials tab," this phase's own explicit, pre-approved design (flagged plainly during planning, not silently implemented): someone who can manage a Supplier's API config can redirect a Domain's registrar link through this action even without Domain edit rights.
   - **Rights**: reuses the existing config-level right already gating the credentials tab (`SupplierConfig::$rightname`, no new right) plus native `Domain::canCreate()` for the import action specifically — checked server-side in `DomainImportController` and fails with a clear message (not silently) when absent, per spec.
   - **No schema/migration changes at all** for this slice: `DiscoveredDomain` is never persisted (fetched live every time the modal opens), so `Installer.php`, `Profile.php`, and `setup.php`'s rights/search-option registration are all untouched — a real simplification this scoped-down slice enabled, not an oversight.
   - **Deferred, explicitly, to their own follow-up addenda** (not attempted in this pass):
     - **NS provider registry additions** (`resources/ns-providers.json`, §4) for detection-only coverage: Openprovider, Hostinger, OVHcloud (check for a pre-existing entry from the earlier popular-provider sweep, §5, before adding a duplicate), Aruba, OpusDNS — unrelated research (verifying real nameserver wildcard patterns from each provider's own docs) that doesn't block or depend on the import feature itself.
   - **Phase 8 addendum (implemented 2026-07-27): generalized "Registrar mismatch" state on the Domain form's own Registrar sync status panel.** The gap: `HookHandler::infocomSaved()` mirrors a changed Infocom `suppliers_id` into the state row's `registrar_suppliers_id` **immediately**, but left `registrar_status`/`registrar_message` untouched — both describe whatever the *previous* registrar's last sync found, not the new one. A domain reassigned from a supplier whose last sync was "OK" would show "OK" next to the *new* supplier's name until the next sync happened to run, which reads as a real, verified success it never actually was — "genuinely incorrect stored data," exactly the failure mode this deferred item was about, not limited to the import modal's own already-solved mismatch case. Fixed by having `infocomSaved()` reset `registrar_status` (and clear `registrar_message`) the moment it detects a real reassignment, using a new dedicated `DomainState::STATUS_REASSIGNED` rather than reusing `STATUS_NEVER` (which would wrongly imply the domain has no sync history at all) or `STATUS_ERROR` (nothing failed) or `STATUS_SUPPLIER_INACTIVE` (an intentional, calm state — this isn't): reassigning between two known suppliers → `STATUS_REASSIGNED` ("Registrar changed, not yet verified", `text-bg-info` — deliberately calmer than the danger-red "Error" badge, since nothing failed, but more attention-grabbing than the secondary-grey badges, since it should prompt an "Update Now" click); clearing the registrar entirely → the existing `STATUS_UNCONFIGURED`; a domain's *first* registrar assignment (no prior supplier to be wrong about) → the existing `STATUS_NEVER`. Every registrar sync path in `SyncEngine::syncRegistrarLeg()` already unconditionally overwrites both fields on its next run, so `STATUS_REASSIGNED` is always transient — it only ever appears in the window between a reassignment and the domain's next sync, both in the Domain form panel (`DomainForm::getStatusLabels()`/`getStatusClasses()`) and the Supplier's own "Domains" list (`supplier_domains_list.html.twig`'s local status maps), the same rendering pattern both already used for every other status.
9. **Phase 10 (implemented 2026-07-22)** — IDN / Punycode handling. Real-world case surfaced this while testing Phase 8 (2026-07-22): `viñamoraima.com`, a genuine internationalized domain name, was failing silently across NS detection and every driver call, since DNS and every provider API only operate on a domain's ASCII-compatible **Punycode** (ACE, `xn--...`) form, not the human-readable Unicode form GLPI stores as `Domain::$fields['name']`. Full detail in §3.11: `IdnNormalizer` (new hard `intl` dependency, blocked at activation if missing), wired into `NsResolver`, all three drivers' `normalizeDomain()`, the IONOS zone/domain-id lookups' response-side matching, and `DomainDiscoveryMatcher`'s existence check (round-trip safety); a new read-only "Punycode / ASCII form" field on the Domain Manager panel, shown only when it differs from `name`. `viñamoraima.com` is documented as a permanent regression case in `TESTING.md`'s Phase 10 section.
10. **Phase 9 addendum — Design/UX polish (implemented 2026-07-22)**. Two cosmetic fixes, no schema/logic changes, requested as their own running addendum against already-shipped UI:
    - **"Update Now" relocated out of the `domain_panel.html.twig` ribbon-banner header into the Domain form's own Save / Put in trashbin button row.** Discovered while implementing this that a plugin cannot render *into* that row directly: both it (`.form-button-separator`) and the `Hooks::POST_ITEM_FORM` call that renders this plugin's whole panel live in core's `templates/components/form/buttons.html.twig`, but the hook call comes *before* the row's markup in that same template — a plugin hook has no access to a later sibling that doesn't exist yet at render time, and core's own `addbuttons` mechanism (the officially-supported way to add a button to that row) is populated by whatever calls `showForm()`/`showFormButtons()` for the itemtype, which for `Domain` is core itself, not reachable from a plugin. Fixed client-side: the button still renders from `domain_panel.html.twig` (initially `d-none`, so there's no flash of it in the wrong place), and a small inline script — deferred to `DOMContentLoaded` since the button row doesn't exist yet at the point this script tag is parsed — moves the actual DOM node into `.form-button-separator`, inserted immediately after the native Save button (`button[name="update"]`) if present, else appended. Core's own button row uses `flex-row-reverse` (right-to-left visual layout from DOM order), so DOM order `[Save, Update Now, Put in trashbin]` renders visually as **Put in trashbin / Update Now / Save** — matching the requested layout without needing to touch core's CSS. Styled `btn btn-outline-secondary me-2`, the same class convention core's own `addbuttons` handling already uses for a plugin-contributed button in this exact row (same Bootstrap `.btn` size/padding/border as its "Save"/"Put in trashbin" neighbours, distinct color only). The click handler itself (fetch to `/plugins/domainmanager/sync/{id}`, badge refresh) is unchanged — only the button's DOM position/class changed. Verified live end-to-end on `glpi-claude` with Playwright: button confirmed inside `.form-button-separator` in DOM order `[update, domainmanager-updatenow, delete]`, ribbon header confirmed to no longer contain a button, and a simulated click confirmed it still fires the sync `fetch()` call.
    - **New "Entity" column on the Supplier tab's "Domains" list** (`supplier_domains_list.html.twig`), placed last (after DNS/NS provider) rather than crowding the DOMAIN/REGISTRAR pair — each domain's `entities_id` (already fetched by `DomainState::getDomainsForSupplier()`, just previously unused by this list) rendered via `SupplierTab::describeEntity()`, core's own tree/breadcrumb entity badge (§6.5 "Entity display"). Verified live: a domain moved into a child entity ("Client1" under "TICGAL-Dev-01") rendered the correct two-segment breadcrumb, root-entity domains rendered the single-segment form, and the added column caused no horizontal overflow at a 1280px viewport.
    - Both verified against a live GLPI 11.0.8 instance (`glpi-claude`, port 65008) after running `bin/console cache:clear` — this container's Twig/Symfony template cache (`/var/glpi/files/_cache/<version>-<hash>-production/`, distinct from `/var/www/glpi/files/_cache`) does not appear to auto-invalidate on file mtime changes the way a dev-mode cache would, so a stale-render false negative is possible on this environment after a **template** edit until that command is re-run — worth remembering for future sessions against this same container.
    - **Correction (2026-07-27): `cache:clear` only covers the Twig/Symfony cache above — it does *not* clear PHP opcache.** Editing a **PHP** file (e.g. a driver class) and re-testing live can still silently serve the old bytecode even after `cache:clear`, despite `opcache.validate_timestamps=On` and a confirmed-fresh mtime on the mounted file — seen live while adding `DinahostingDriver::listAccountDomains()` (§9 Phase 8 addendum). The fix that actually worked was `podman restart glpi-claude_glpi_1` (safe — no data loss, the DB lives in the separate `glpi-claude_db_1` container). So: template-only changes → `cache:clear`; any PHP change → restart the container to be safe, `cache:clear` alone is not sufficient.

11. **Phase 12 (implemented 2026-07-27) — configurable "Domain type to apply to imported domains".** Bulk-import (Phase 8) force-assigned the seeded "Internet Domain" `DomainType` to every domain it created, with no way for an admin to turn this off or pick a different one. Replaced with a real plugin-wide setting — the plugin's **first** general config of any kind (§6.6) — applied only at creation time (never re-applied on sync, never added to the locked-fields list). Fresh installs default to unset (imported domains get no type, same as one created by hand); installs upgrading from a pre-Phase-12 version default to the previously-hardcoded "Internet Domain" type, so upgrading changes nothing about current behavior until an admin deliberately changes it (`Installer::seedDomainType()` distinguishes the two by whether that `DomainType` row already existed before the current install/activation ran). See §6.6 for the full design and §8 for the right (`config` UPDATE).

12. **Phase 14 (implemented 2026-07-27) — conditional per-field locking, Registrar `suppliers_id` lock + Unlink action, Domain-level "Managed" field.** An addendum asked for four things, and research against the actual (not assumed) current code found the first was mostly already done:
    - **Conditional field locking was already correct for Domain's date fields and `is_active`** — `SyncEngine::syncRegistrarLeg()` only adds `date_domaincreation`/`date_expiration` to the lock set when the registrar's `DomainLifecycle` DTO reported a non-null value that sync; `is_active` is always locked, correctly, since `LifecycleStatus` is a non-nullable DTO field with no "not reported" case. No code change needed there — see §0.3's corrected wording. `DomainRecord`, however, used a hardcoded static field list (`LockEnforcer::PROTECTED_RECORD_FIELDS`) applied uniformly regardless of what the DNS driver actually reported — generalized to the same per-sync `ImportLock`-driven mechanism `Domain` already used (`RecordReconciler` now calls `ImportLock::replaceLocks(DomainRecord::class, ...)` on both its create and update paths, `domains_id` staying a separate always-locked structural field). Today's `ZoneRecord` DTO has no nullable fields among `name`/`data`/`ttl`/`domainrecordtypes_id`, so this is behavior-preserving today, but a future DNS driver whose DTO gains a nullable field is now handled correctly without further plugin changes.
    - **`suppliers_id`/Registrar now has real lock enforcement**, previously nonexistent (it was only ever rendered read-only in the UI, §0.1/§6.2, with no server-side protection at all). `LockEnforcer::infocomPreUpdate()` (new `Hooks::PRE_ITEM_UPDATE` hook on `Infocom::class`) strips a `suppliers_id` change once the Domain has a confirmed working registrar match (`DomainState::registrar_status === STATUS_OK`). See §0.3 for the full mechanism.
    - **New "Unlink registrar" action** (`Controller\DomainRegistrarUnlinkController`, `POST /plugins/domainmanager/domainunlink/{domains_id}`), a genuinely new controller — research confirmed no "Phase 13"/"unlink" concept existed anywhere in the codebase prior to this phase, only the Phase 8 "Reassign" flow. Clears the Domain's Infocom `suppliers_id`, bypassing the new lock via the same `LockEnforcer::$sync_in_progress` runtime flag `SyncEngine` uses (rather than requiring `domainmanager:unlock_imported`). Auth is `Domain::canUpdate()` — unlike Reassign's Supplier-`UPDATE`-only check, unlinking has no target supplier to check rights against. Surfaced as a small unlink button next to the read-only Registrar field in `domain_panel.html.twig`, visible when a registrar is assigned and the user can update the Domain.
    - **"Add Record" was never actually gated on managed status** — an earlier addendum had proposed hiding/disabling it on actively-managed domains, but grepping the whole plugin (`DomainRecord::showForDomain()` is never overridden) confirmed this was never implemented. Nothing to reverse; verified instead as a permanent regression case in `TESTING.md` (a manually-added record on a managed domain gets no `ImportLock` rows and `ImportedRecord::isPluginOwned()` false, since `RecordReconciler` never touches it).
    - **New Domain-level "Managed" field**, mirroring the existing DomainRecord-level one (§5.7) but computed differently: `Managed = 1` iff either role (Registrar or DNS) currently resolves to a real, driver-backed, **active** supplier — independent of whether the most recent sync attempt then succeeded or errored. Implemented as `is_managed` on the existing `glpi_plugin_domainmanager_states` table (§2) rather than a new table, since that table already has one row per Domain. See §2 for the schema/computation detail and `DomainState::resolvesToActiveDriver()` for the shared "real, driver-backed, active" check reused by both `SyncEngine` and `HookHandler::infocomSaved()`.

13. **Phase 16 (implemented 2026-07-27) — single source of truth for Registrar/DNS status, cross-tab (`PHASE13_PLAN.md`; numbered 16 here since a "Phase 13" never actually existed in the codebase — see the Phase 14 unlink note above).** Root cause: `DomainState::getDomainsForSupplier()`'s registrar-verification gate compared the state row's `registrar_suppliers_id` mirror against `$suppliers_id` — the supplier *whose tab is being viewed* — instead of the row's own live Infocom `registrar_suppliers_id`, fetched in the same query. Correct for a domain viewed from its own registrar's tab (the two happen to be equal there) but always false when viewed from a *different* supplier's tab for a role it doesn't hold (e.g. `ticgal.com`, registrar IONOS / DNS Cloudflare, viewed from Cloudflare's "Domains" list) — so the Registrar column showed `STATUS_NEVER` there regardless of the domain's real, current `registrar_status`. The Domain form was never affected — `DomainForm::inject()` reads `state->fields` directly, no such gate. Fixed by comparing the mirror against the row's own `registrar_suppliers_id` (the Infocom join result) instead of `$suppliers_id`; the check is now genuinely tab-independent. Single incorrect gating condition in the one existing shared read path, not two independently-drifted implementations.
    - **DNS/NS column badge attempt, then revert (regression, same day):** first switched from the detection-only `managed`/`unmanaged`/`unknown`/`never` classification to the same shared `dns_status` sync-outcome badge (`DomainState::getStatusLabels()`/`getStatusClasses()`) the Registrar column and Domain form use, on the theory that the classification was pure duplication. **Confirmed a regression by direct user testing** — the raw `dns_status` badge showed a materially worse/wrong-looking status than the classification it replaced (the finer sync-outcome vocabulary doesn't degrade gracefully the same way for the NS column, which the Registrar column and Domain form don't need to handle: `dns_suppliers_id` can be set — genuinely managed — while `dns_status` reflects a less reassuring in-between value, and collapsing that into "the same badge as Registrar" reads worse than the old blanket "Plugin managed" green did). **Reverted**: `SupplierTab::describeDnsProvider()` and the Twig template's `dns_kind_labels`/`_classes` maps are back exactly as they were pre-Phase-16, unchanged in this phase after all — only the Registrar column actually got the shared-badge treatment. Lesson: unlike the Registrar gate (a genuine single-bug fix, verified correct), this was a design change dressed as cleanup, and design changes to already-working UI need real testing, not just "it's more consistent," before landing.
    - **Column header renamed "DNS / NS Provider" → "DNS Provider"** (both here and the Domain form panel, §6.2) per explicit request — one term, not two. Briefly landed as "NS provider" (matching the `NsProviderRegistry`/"NS Provider" search-option naming, §9 Phase 15 addendum) before a follow-up request settled on "DNS Provider" instead, consistent with the term already used loosely elsewhere in the Domain form's messages/alerts. This rename is scoped to the provider-identity column header specifically, not every "NS"/"DNS" string in the UI (e.g. `NsProviderRegistry`'s class name and the "NS Provider" search option keep their existing names).
    - **Audited for other independent computations of the same status, per the same request**: the tab-counter badge (`SupplierTab::getTabNameForItem()`) already reuses `getDomainsForSupplier()` directly, so it just inherits the fix above with no separate logic to change. `Cron.php`/`MassiveActionHandler.php` both read straight from `SyncEngine`'s own result (the authoritative sync pipeline) to decide what to persist, never a second display-layer computation — confirmed by inspection, no change needed.
    - Permanent regression case added to `TESTING.md`: view the same domain's Registrar/DNS status from its own Domain form and from every Supplier tab it's linked to (registrar tab and DNS-provider tab, when different), immediately after both "Update Now" and the batch "Review and sync" action, confirming identical badges everywhere with zero lag.

14. **Phase 16 (implemented 2026-07-27, revised same day) — Supplier-side searchable fields.** Five real, filterable/sortable search options registered on the native `Supplier` itemtype (ids 9411-9415, `plugin_domainmanager_getAddSearchOptionsNew()`). Initial version made all three fields plain counts; revised after feedback that a count alone doesn't let you see *which* domains — modeled instead on core's own `CommonITILTask::rawSearchOptionsToAdd()` pairing for Tickets (its "Description" `itemlink`/text list plus its "Number of tasks" `count`, confirmed against the live 11.0.8 source at `src/CommonITILTask.php`):
    - **"Registrar"** (id 9412, `datatype` => `itemlink`) and **"NS Provider"** (id 9413, `itemlink`) now show the actual clickable Domain names for that role, via a two-hop `beforejoin` chain (Supplier -> `glpi_infocoms`/`glpi_plugin_domainmanager_states` -> `glpi_domains`), each paired with a `count`-only companion — **"Number of domains (Registrar)"** (id 9414) and **"Number of domains (NS Provider)"** (id 9415) — for sorting/filtering by quantity without pulling the name list.
    - **"Domains"** (id 9411, unchanged) stays count-only: it reuses `DomainState`'s `registrar_suppliers_id` mirror column to OR both roles in one join, since a single search-option join can only OR two columns on one table, and there's no way to union two different tables' matching rows into one name list within the search framework's one-join-per-option model. Good enough for "is this supplier worth a closer look" filtering; the authoritative per-domain cross-check is still `DomainState::getDomainsForSupplier()` on the Supplier's own tab.
    - **Live-verified**, not just read from docs: built a throwaway bootstrap script against the `glpi-claude` 11.0.8 dev container (`Glpi\Kernel\Kernel` + `Search::getDatas('Supplier', ..., [9411..9415])`) and confirmed the generated SQL — both `itemlink` two-hop joins and the `count` single-hop joins — executes cleanly (returned 3 rows, all-null values only because that dev instance currently has zero domains linked to any supplier, not a query defect).
    See `search-options-registry.json` for the full per-option collision-check record.

15. **Phases 21-23 (implemented 2026-07-28) — RDAP as a fallback/supplementary data source (`PHASE21_PLAN.md`).** Registrar drivers each report a different subset of lifecycle/lock metadata (§3.6-§3.10); RDAP (`rdap.org`) is queried as a driver-agnostic supplement to fill whatever gaps the configured driver leaves, never to override or duplicate what a driver already reports.
    - **No IANA bootstrap-file cache, no per-TLD authoritative-server map.** The cron design's own rate-limit ceiling (one lookup per 10-minute tick) caps worst case at ~144 requests/day to `rdap.org`, nowhere near its 10-req/10s limit — the engineering cost of a bootstrap cache buys nothing at this call volume.
    - **TLD support is detected reactively, not from a maintained list.** A 404 (or "no redirect found") from `rdap.org` for a domain's TLD is treated as a normal "no RDAP data for this TLD" outcome — same `last_rdap_check_date` bookkeeping, same "not an error" logging rule as any other checked-and-nothing-new result — rather than excluding TLDs ahead of time from a hand-maintained list.
    - **Schema** (`glpi_plugin_domainmanager_states`, `Installer::addRdapColumns()`): `last_rdap_check_date` (cron eligibility gate), `rdap_last_changed_date`, `rdap_pending_delete`/`rdap_pending_transfer` (tri-state, no driver has an equivalent), `rdap_dnssec_signed` (tri-state, dual-source with `registrar_dnssec_enabled`), `rdap_registrar_name`/`rdap_registrar_iana_id`, `rdap_nameservers` (JSON array) — all nullable/additive.
    - **Phase 21 — `Service/RdapClient.php`, `Dto/RdapLookupResult`, `Service/RdapGapChecker`.** The client queries `rdap.org/domain/{name}` directly via GLPI's own HTTP client factory. `RdapGapChecker::getGaps()`/`hasGap()` compares a Domain's current state against what's actually still unreported — registration/expiration dates come from the native `glpi_domains` columns, DNSSEC is only a gap when *neither* the driver *nor* a prior RDAP check has it, and registrar-of-record/nameservers are deliberately excluded from the gap set entirely (see Phase 22 below).
    - **Phase 22 — `Cron::cronRdapEnrichment()`, registered as its own Automatic Action ("RdapEnrichment"), default every 10 minutes, independent of `DomainSync`.** Each tick scans up to 50 oldest-/never-checked candidates (`RDAP_CANDIDATE_SCAN_LIMIT`), skips any without an actual gap, and processes exactly the first genuine gap found — one domain per execution, by design, since the tick spacing itself is the whole rate-limit defense. A clean no-op (nothing eligible) logs to `domainmanager.log` only, never to `domainmanager-errors.log`; a genuine failure (network error, non-404 non-2xx, unparsable body) goes to the error log. Registrar-of-record and nameservers are populated **unconditionally** on every successful lookup (never gap-gated, since no driver reports them at all) but are purely informational — never touch `Infocom::suppliers_id`, `DomainRecord`, or `ImportLock`, reinforced by `RdapGapChecker`'s own doc comment so this doesn't get accidentally wired into the lock/sync mechanism later. Notices/remarks are logged to `domainmanager.log` only on a domain's first-ever lookup, diagnostic only — no schema, no UI.
    - **Phase 23 — UI surfacing (`domain_panel.html.twig`, `config.html.twig`).** The Registrar details table gained two RDAP-only columns ("Pending delete", "Pending transfer") and the existing DNSSEC column now falls back to `rdap_dnssec_signed` with a "via RDAP" marker (`ti-satellite` icon + tooltip) when `registrar_dnssec_enabled` is null — same "Not reported by this driver" fallback text otherwise. A new cross-check sub-panel (below the details table, same `card-body`, shown only on an actual mismatch to avoid noise on the common case) compares `rdap_registrar_name`/`rdap_registrar_iana_id` against the Infocom Supplier on file, and `rdap_nameservers` against a live `NsResolver::getNameservers()` lookup performed by `DomainForm::inject()` only when RDAP has already reported something to compare against (so a never-RDAP-checked domain's form doesn't pay for a DNS query it can't use). The Config page (§6.6) gained a read-only status line — `DomainState::getRdapEnrichmentStatus()` counts domains still date-eligible for a check today (the cron's own eligibility query, without the extra per-domain `RdapGapChecker` filter, since that's the honest cheap-to-compute reading of "still due for a look") and reports the most recent `last_rdap_check_date` across all domains.
    - **Registrar-of-record is diagnostic-only, restated:** `rdap_registrar_name`/`rdap_registrar_iana_id` is never a second source of truth for "who is the registrar" — the plugin's only authoritative concept remains Infocom's `suppliers_id` mirror (§0.1). A mismatch is surfaced so it's visible, not so it's actionable from this panel.

16. **Phase 24 (implemented 2026-07-28) — richer automatic-action logs.** Both `Cron::cronDomainSync()` and `Cron::cronRdapEnrichment()` previously logged only a single bare count line (`$task->log()`) per run — enough to see *that* something happened, not *where*. Brought in line with the same per-entity breakdown convention GLPI core's own crons use (e.g. "close tickets"): `cronDomainSync()` now tallies domains-synced/errors per `Dropdown::getDropdownName('glpi_entities', ...)` and logs one line per entity touched that run, on top of the existing overall summary line. A second breakdown, per registrar Supplier (`DomainState::registrar_suppliers_id`), is layered on top — a genuinely useful axis specific to this plugin, since a bad sync run is at least as likely to be one misbehaving registrar API as an entity-wide problem. `cronRdapEnrichment()` only ever touches one domain per tick (§9 Phase 22's rate-limit design), so its "breakdown" collapses to a single log line naming that domain's entity, its registrar Supplier (or "no registrar"), and a short outcome summary (which fields RDAP actually filled, "no new data from RDAP", or the 404 no-data case) — `processRdapEnrichment()` now returns that outcome string instead of `void`. This logging is additive only — `PluginLogger`'s own file-based activity/error logs (`domainmanager.log`/`domainmanager-errors.log`) are unchanged; the two serve different audiences (an admin skimming Setup > Automatic actions vs. a file-based diagnostic trail). Codified as a general requirement for future plugins in the `glpi-plugin-builder` skill's "Cron/automatic-action logging requirement" section.

17. **Phase 25 (implemented 2026-07-28) — RDAP "last changed"/"last transfer" dates surfaced, new `rdap_transfer_date` column.** `rdap_last_changed_date` had been captured since Phase 21 but was never actually rendered anywhere in the UI — the only RDAP-only date genuinely dead-ended in the database. Added two new columns to the Domain form panel's main status table (between "DNS sync" and "Last sync", per explicit request): "Last changed" and "Last transfer" — no "(RDAP)" suffix on screen, per a follow-up request that the source shouldn't clutter the visible label (kept in the doc trail here and in `CHANGELOG.md` instead) — both shown regardless of `rstatus`/`dstatus` (RDAP is an independent data source, not gated on the driver's own sync outcome). The transfer date needed a genuinely new field: RDAP's `transfer` eventAction (most recent registrar transfer) was parsed by nothing in `RdapClient::parse()` — added as `$transferDate` on `RdapLookupResult`, a new `RdapGapChecker::GAP_TRANSFER_DATE` (same "no driver reports this, always a gap until first successful RDAP check" treatment as pending-delete/pending-transfer — a domain that has simply never been transferred keeps this gap open forever, same accepted trade-off those two already have), and a new nullable `rdap_transfer_date` datetime column added via `Installer::addRdapColumns()`'s existing idempotent `addField()` calls (no separate migration method needed — adding one more `addField()` line to an already-idempotent method is safe for both fresh installs and upgrades past this version).

18. **Phase 26 (implemented 2026-07-28) — Transfer lock/Domain lock RDAP gap-fill, RDAP fields made searchable. Storage approach superseded same day by Phase 27 below — kept here for the historical record.** `RdapClient::parse()` had computed `transferLock`/`domainLock` since Phase 21 (from the `client transfer prohibited`/`server transfer prohibited` and `client delete prohibited`/`server delete prohibited` status values), but neither value was ever stored or displayed — dead fields end-to-end. Initial approach: the same dual-source treatment DNSSEC already had, via two new parallel `rdap_transfer_lock`/`rdap_domain_lock` columns and a "via RDAP" tooltip marker when the driver itself reported nothing. Also made 7 RDAP-sourced Domain fields searchable (ids 9423-9429).
    - Confirmed against the real RDAP JSON envelope for a live domain (user-supplied event list) that `RdapClient::eventDate()`'s `'transfer'`/`'last changed'` lookups target the correct, distinct `eventAction` values — RDAP also exposes a `"last update of RDAP database"` event (registry/database bookkeeping metadata, not domain-specific), which this plugin correctly never reads.

19. **Phase 27 (implemented 2026-07-28) — naming/storage revision: drop the "rdap_" prefix, fold Transfer lock/Domain lock/DNSSEC into their existing columns.** Explicit feedback after seeing Phase 26 live: don't create a parallel column when one already exists for the same concept — just fill the existing one — and don't put "RDAP" in field/column names at all (RDAP is a *source*, not part of the data's identity, same as no column says which registrar driver reported it either).
    - **Transfer lock/Domain lock/DNSSEC**: `rdap_transfer_lock`/`rdap_domain_lock`/`rdap_dnssec_signed` columns dropped entirely. `RdapGapChecker::getGaps()` now checks the *existing* `registrar_transfer_lock`/`registrar_domain_lock`/`registrar_dnssec_enabled` columns directly (gap only when null), and `Cron::processRdapEnrichment()` writes RDAP's value straight into those same columns when gap-eligible. The Registrar details table's cells go back to a single plain Yes/No/— reading — no more dual-source branching or "via RDAP" marker, since there's only one column now.
    - **`SyncEngine` fix, required for the above to actually work**: every registrar sync previously wrote all 7 registrar-metadata fields *unconditionally* on every run, resetting any field the current driver doesn't report back to `null` — harmless on its own, but since RDAP's gap-fill now targets those exact same columns, the very next ordinary sync would silently erase whatever RDAP had just filled in. Changed to only include a field in the update when *this* sync's registrar leg actually reported a value for it, leaving it untouched otherwise (mirrors the pattern `date_domaincreation`/`date_expiration` already used via a conditional `$updates` array, `syncRegistrarLeg()`). Accepted trade-off: no code path resets these fields to `null` on registrar reassignment/unlink either, so a stale value from a previous registrar (or a stale RDAP fill) can persist on screen until something else overwrites it — this was already true for every field not part of the reassignment's own explicit reset logic, so it isn't a new class of staleness, just a slightly wider one.
    - **`last_changed_date`/`transfer_date`**: no existing equivalent (genuinely new concepts), so they keep their own columns but lose the "rdap_" prefix — renamed from `rdap_last_changed_date`/`rdap_transfer_date`. Same for `pending_delete`/`pending_transfer` (renamed from `rdap_pending_delete`/`rdap_pending_transfer`). `rdap_registrar_name`/`rdap_registrar_iana_id`/`rdap_nameservers` keep the prefix deliberately: unlike the fields above, these represent a genuinely different concept from anything already named "registrar" on this table (Infocom's Supplier mirror, §0.1) and dropping the prefix would read as if they were that same authoritative value.
    - **Migration mechanics** (`Installer::addRdapColumns()`): `Migration::addField()`/`changeField()` each queue their ALTER clause from the *live* schema at call time, with no awareness of each other's pending clauses in the same run — calling both `addField('last_changed_date', …)` and `changeField('rdap_last_changed_date', 'last_changed_date', …)` unconditionally in the same migration produced a single `ALTER TABLE` with two clauses creating the same target column, which MySQL rejected outright (`Duplicate column name`, hit live against `glpi_glpi_1`). Fixed by checking `$DB->fieldExists()` first and calling exactly one of the two per column: `addField()` for a genuine 1.0.0-vintage install that never had either name, `changeField()` for a beta install still on the old name, neither for an install already on the final name (fresh installs via the `CREATE TABLE` path, or an already-upgraded beta).
    - Search options: the 3 `(RDAP)`-suffixed options for Transfer lock/Domain lock/DNSSEC (ids 9425/9426/9429) are gone — the existing `PLUGIN_DOMAINMANAGER_SO_DOMAIN_TRANSFER_LOCK`/`DOMAIN_LOCK`/`DNSSEC` options already search the exact column RDAP now fills too. Their ids are left as gaps in the 9400-9429 block rather than renumbered (a previously-registered id must never silently change meaning). The remaining 4 (Last changed, Last transfer, Pending delete, Pending transfer) just lost their "(RDAP)" label suffix and field-name prefix.

20. **Phase 28 (implemented 2026-07-28) — fix false-positive RDAP cross-check mismatches; RDAP registrar of record surfaced on the Supplier tab.** Live testing on `glpi_glpi_1` found the Phase 23 cross-check panel flagging matches as mismatches: a Dinahosting-registered domain showed "Registrar mismatch: RDAP reports DINAHOSTING S.L. (IANA #1262) · Supplier on file: dinahosting" (same registrar, RDAP just includes the legal suffix), and "Nameserver mismatch" between two nameserver lists that were actually identical, just in a different order.
    - **Registrar name comparison** (`DomainForm::registrarNamesLikelyMatch()`): a plain case-insensitive `==` is too strict for RDAP data, which commonly carries the registrar's full legal form. Rather than maintain an exhaustive legal-suffix list (S.L./S.A./Inc./LLC/Ltd/GmbH/…) that would never really be complete, both names are stripped to bare alphanumerics and compared by substring containment either way — "dinahosting" is a substring of "dinahostingsl" once punctuation/spaces are gone, so this passes without needing to know "S.L." is a legal suffix at all.
    - **Nameserver comparison** (`DomainForm::normalizeNsList()`): lowercase, trailing-dot-stripped, deduplicated, sorted before comparing — the same normalization `NsResolver`'s own live lookup already applies, just not previously applied to RDAP's side before comparing. The *displayed* lists are untouched: each source's nameservers are still shown exactly as reported (order/case as-is) — only the comparison used to decide whether to show the mismatch badge at all was wrong.
    - Both comparisons moved out of the Twig template (`{% set ... = ... %}` boolean expressions, not really testable) into two new private static methods on `DomainForm`, computed once in `inject()` and passed to the template as plain booleans (`registrar_mismatch`, `ns_mismatch`).
    - **RDAP registrar of record now also shown on the Supplier's own Domain Manager tab** (`DomainState::getRdapRegistrarInfo()`, `SupplierTab.php`, `supplier_tab.html.twig`): the most recently RDAP-checked, still-populated `rdap_registrar_name`/`rdap_registrar_iana_id` among that Supplier's registrar-linked domains, shown as a plain read-only row next to the API driver selector. Addresses the "should we add the IANA to the Supplier tab" question raised during this same review — an admin can now confirm/record the real-world registrar identity once per Supplier rather than only discovering it per-Domain when a genuine mismatch happens to trip the cross-check panel. Same "informational only, never a source of truth" rule as the per-Domain panel (§9 Phase 21 "Registrar-of-record note") — this is a read-only display, no write path.

21. **Phase 31** — architecture only (this document, §11). No code. Stop for approval before Phase 32.

22. **Phase 32 (implemented 2026-07-29) — schema, one right, one search option.** `PLUGIN_DOMAINMANAGER_VERSION` bumped to `1.2.0-alpha1` (§11.15's Phase-15-trap requirement). `is_glpi_created` added to `glpi_plugin_domainmanager_records` (non-nullable tinyint, default `0`, `addField()`/`addKey()`, already present in `createTables()`'s raw CREATE TABLE for fresh installs — §11.12). New right `domainmanager:dns_records` (CREATE/UPDATE/DELETE bits, not auto-granted to any profile), registered/deregistered via the existing `Installer::registerRights()`/`Profile::uninstall()` plumbing, rendered through `Profile::displayRightsChoiceMatrix()` alongside the existing unlock right (§11.6). New DomainRecord search option "Created from GLPI" (id **9430**, not 9425/9426/9429 — those were briefly live in the same-day-superseded Phase 26/27 change and stay permanent gaps per the plugin's own never-reassign rule, so the reserved block widens by one instead). No `DnsRecordWriterInterface`, no IONOS implementation, no UI yet — that's Phase 33 onward.

Every phase leaves install → uninstall residue-free.

---

## 10. Versioning & changelog policy

`PLUGIN_DOMAINMANAGER_VERSION` in `setup.php` is the single source of truth for the plugin's version (no `composer.json` version field is used). **Every bump of that constant must land in the same commit as a matching changelog entry** — never bump the version constant without touching a changelog file in the same change. What that entry looks like depends on whether the bump is a pre-release or a real release, and the two-file split below (added 2026-07-31).

**Two changelog files, split by audience (added 2026-07-31):**
- **`CHANGELOG-dev.md`** — the full history. Every version this constant has ever held, pre-release included, each with its own dated header.
- **`CHANGELOG.md`** — user-facing, one header per **real release only** (no `-alphaN`/`-betaN` suffix). No pre-release detail lives here at all.

**Entry format (both files, revised 2026-07-31):** two buckets per version header, `### Features` and `### Bugs` — not Keep a Changelog's Added/Changed/Removed/Fixed/Documented/Verified split. One line per item, a short factual statement, not a multi-sentence paragraph with investigation narrative. (The narrative/root-cause detail this project used to carry inline in the changelog belongs in commit messages and this doc's own phase log above, not the changelog — a changelog entry answers "what changed," not "how was it found and why.") A version with nothing fitting either bucket (a pure refactor, a version-bump-only release) still gets a one-line note under whichever bucket fits best, or a single top-level line if genuinely neither (e.g. "version bump only — no functional changes").

**Where a bump's entry goes:**
- **Pre-release bump** (`-alphaN`/`-betaN`): entry goes under `CHANGELOG-dev.md`'s running `## [Unreleased]` section — **not** a new dated header of its own. (This reverses this section's own earlier "Pre-release amendment," which had each pre-release bump minting its own header; reversed 2026-07-30 per direct user feedback that a wall of near-identical per-bump headers for one continuous line of work reads as noisy churn, not useful history. `CHANGELOG.md` is untouched by a pre-release bump — nothing to add there yet.)
- **Real release** (no suffix, e.g. `1.3.0`): this is the point `CHANGELOG-dev.md`'s accumulated `[Unreleased]` content gets promoted into one new dated `## [x.y.z] - YYYY-MM-DD` section there, and `CHANGELOG.md` gets its own new dated header with the same Features/Bugs bullets, trimmed further if needed. `[Unreleased]` resets to present-but-empty in `CHANGELOG-dev.md` afterward.

**Known gap, already reconciled (2026-07-31):** `1.2.0` itself was never actually cut as a real release — only `1.2.0-alpha1`..`-alpha4` and `1.2.0-beta1`..`-beta3` exist in the history, then the version line jumped straight to `1.3.0-alpha1`. Rather than leaving that whole feature arc (the entire DNS-record-write-back capability) absent from the user-facing `CHANGELOG.md`, the `[1.3.0]` entry there explicitly covers everything shipped since `1.1.0` (the true previous real release), with a note explaining why. Don't retroactively invent a `[1.2.0]` header — that gap in the version-number sequence is real project history.

---

---

## 11. Phases 31–35 — Manual DNS record write-back to IONOS ("Managed — editable")

### 11.1 Scope

The first **write-direction** capability in this plugin. Until now every pipeline has been
strictly upstream → GLPI: `RecordReconciler` mirrors provider DNS records into native
`DomainRecord` rows and the provider is always authoritative. This feature lets an operator
create, edit and delete a **DNS record** from inside GLPI and have the change pushed to the
provider.

**In scope:** DNS records only, on IONOS only, for four record types (§11.4).

**Explicitly out of scope, and not deferred-with-intent — simply not part of this feature:**

- Domain lifecycle operations of any kind: registration, renewal, transfer, transfer-lock,
  auto-renew, privacy, DNSSEC toggles. All of that is *domain-level registrar* metadata, read
  by `fetchLifecycle()` and displayed read-only. Nothing here makes it writable.
- Zone creation or deletion. Only records within an already-existing zone.
- Cloudflare and Dinahosting write paths. The interface is provider-neutral; only IONOS
  implements it.
- Bulk or scripted editing. One record, one deliberate action, one confirmation.

**Direction of authority is unchanged.** The provider remains the source of truth. A write from
GLPI is a *request to change upstream*, after which upstream is re-read and continues to win.
No part of this feature makes GLPI authoritative, and nothing in §5's reconciliation model is
inverted.

### 11.2 Provenance model: three categories, one of them derived

The plugin already distinguishes two provenances for a native `DomainRecord`. This feature adds
a third *capability* concept, which is deliberately **not** stored.

| Category | Meaning | How it's known |
|---|---|---|
| **Manual** | Created by a user in GLPI, never touched by sync | **No** `glpi_plugin_domainmanager_records` row exists |
| **Managed** | Plugin-tracked: imported and reconciled by sync | An `ImportedRecord` row exists (`is_managed = 1`) |
| **Managed — editable** | A *sub-state of Managed*: the record can additionally be *written* upstream from GLPI | **Derived live** from the domain's DNS driver at render/action time |

**"Managed" keeps exactly its existing meaning.** It is not renamed, not redefined, and not
split. Both existing search options survive untouched — the Domain-level one backed by
`glpi_plugin_domainmanager_states.is_managed`, and the record-level
`PLUGIN_DOMAINMANAGER_SO_DOMAINRECORD_MANAGED` (id 9404) backed by
`glpi_plugin_domainmanager_records.is_managed`. **No saved search built on either breaks.**

**Editability is a derived capability, never a stored flag.** It is computed the same way
`DomainState::resolvesToActiveDriver()` already computes driver availability: resolve the
domain's DNS supplier → resolve its driver → ask whether that driver implements
`DnsRecordWriterInterface`. Nothing is persisted.

This was chosen over storing per-record boolean flags (an `is_readonly` + `is_managed` pair was
considered and **rejected**). Storing an overlapping fact invents a synchronisation problem
that does not otherwise exist: the two flags could disagree, or both be true, and code would
need to define what that means. A derived capability cannot drift from reality, because it *is*
read from reality on each use.

**Naming: "Managed — editable", badge text "Editable from GLPI".** An earlier draft called this
"Full control (records)" and that name was **rejected before shipping**, for reasons worth
recording:

- **It claimed more than the feature does.** Four record types out of eleven, no zone operations,
  no registrar lifecycle. "Full" was wrong on its face.
- **It read as a third sibling of Manual and Managed**, competing with them, when it is in fact a
  *sub-state of Managed* — every editable record is by definition plugin-tracked. Nesting it
  under Managed is the accurate relationship and invents no new vocabulary.
- **It had no graceful degradation.** "Full control" is binary, and editability is not: it
  degrades into distinguishable states that an operator needs told apart —
  `editable` · `read-only` · `editable — unverified` · `read-only — provider credential lacks
  DNS edit`. The last two do not arise on IONOS, whose API key is not scope-limited per zone, but
  they are unavoidable for any provider with granular tokens.

The domain-scope caveat the old parenthetical carried still holds and is stated plainly instead: a
domain's *records* can be editable while its registrar metadata (transfer locks, auto-renew,
expiry) stays read-only, because that metadata is domain-level and lives with the registrar.

### 11.3 Capability keys off the DNS provider, not the registrar

**Editability is decided by the domain's nameservers, not by who it was bought from.** This is
the single most misreadable part of the feature and the reason the interface lives on the DNS
side.

- `ticgal.com` — registrar IONOS, DNS Cloudflare → **not** editable. The registrar can't
  edit records it doesn't serve.
- A domain with IONOS nameservers, registered elsewhere, or with no registrar recorded in GLPI
  at all → **is** editable.

The plugin already detects this: `dns_suppliers_id` on `DomainState` is populated by
`NsResolver`/`NsProviderRegistry` from the domain's live NS records at sync time. Editability
therefore reads off the *detected* DNS provider, and the new interface is
**`DnsRecordWriterInterface`**, a sibling of `DnsPipelineInterface` — not a registrar concern.

**Consequence: the capability is volatile, with no GLPI action involved.** Change nameservers at
the registrar and the badge appears or disappears on the next sync. This is correct behaviour,
not a defect, and it has one operational implication handled in §11.9: a stale page can offer a
write against a zone IONOS no longer serves, so the driver re-checks immediately before pushing.

### 11.4 Editable record types: A, AAAA, CNAME, TXT

**Writable:** `A`, `AAAA`, `CNAME`, `TXT`. Nothing else, and no configuration switch to widen it.

**Read scope is unchanged** — the existing six-type whitelist (`A`, `AAAA`, `NS`, `TXT`, `MX`,
`CNAME`). Every writable type is already inside it, so **this feature touches no read path in
any driver.**

That containment is load-bearing, not incidental. If a type could be created that
`fetchZoneRecords()` does not read, the following happens with no error anywhere: the push
succeeds and the record is live upstream; the local `DomainRecord` and `ImportedRecord` rows are
written; the next sync fetches the zone *without* that record because it isn't whitelisted;
`RecordReconciler` correctly concludes the record vanished upstream and soft-deletes it to the
trash. A live record, silently shown as deleted, by the plugin's own hand. Keeping write scope a
strict subset of read scope makes that failure **structurally impossible** rather than guarded
against.

**Why these four and not more:**

- They cover the ordinary daily work — host records and verification/policy strings.
- `NS` and `MX` are excluded deliberately. Nobody should be re-pointing delegation or mail
  routing from an inventory tool; both have zone-wide consequences, and NS in particular is what
  *grants* this capability in the first place (§11.3), so editing it could revoke the authority
  that permitted the edit and make the panel used to fix it disappear. Recovery would be manual
  at IONOS. **This reasoning is recorded here specifically so NS/MX are not later re-added as an
  obvious convenience.**
- `SOA` is zone metadata; `PTR` belongs to reverse zones the account does not own; `CAA`
  misconfigured blocks certificate issuance; `SRV` and the rest are niche and carry multi-field
  RDATA. None are worth the surface.

**Honest framing of "safe".** `TXT` already carries mail-affecting power, since SPF, DKIM and
DMARC all live there. Excluding `MX` does **not** firewall mail. The claim this list supports is
*bounded blast radius* — one hostname or one policy string per record — not "cannot break
anything". A mangled SPF record is a bad afternoon. `TXT` stays because it is the type most often
needed from outside and the cost of excluding it would be most of the feature's value.

**`ALIAS` was requested and then dropped, on evidence.** GLPI does ship an `ALIAS`
`DomainRecordType` (id 3, `fields => []`) — verified in `src/DomainRecordType.php::$knowtypes`
on `11.0/bugfixes` — so the dropdown row exists and nothing would need inventing on the GLPI
side. The problem is IONOS. Every source listing `ALIAS` describes **IONOS Cloud DNS**
(`api.ionos.com`, the DCD panel, the `ionoscloud` Terraform provider, `ionosctl`), which is a
*different product* from the Hosting/Developer DNS API this driver uses
(`api.hosting.ionos.com/dns/v1`) — the same product confusion §3.9 already warns about for the
IAM federation-domains API. On the Hosting side, `ALIAS` appears nowhere: IONOS's own DNS Pro
template documentation lists supported types as A, AAAA, CNAME, MX, SRV, SPF (TXT) and TXT, its
general DNS settings help covers A/AAAA, CNAME, MX, TXT, SRV and CAA, and neither the maintained
`libdns/ionos` client nor community zone-export tooling models `ALIAS` at all. This is
absence-of-evidence rather than a confirmed rejection (§11.15 records how to settle it), but it
is not a basis for building UI. `ALIAS` remains **readable** in principle — it is one of GLPI's
11 types — but on IONOS Hosting it will most likely never arrive from sync either.

**Reference: GLPI's full seeded type set**, verified in `DomainRecordType::$knowtypes` on
`11.0/bugfixes` (seeded by core install; the 9.5 migration calls `getDefaults()`). Recorded here
because the plugin's installer asserts a subset of it by name.

| id | name | declared `fields` |
|---|---|---|
| 1 | A | — |
| 2 | AAAA | — |
| 3 | ALIAS | — |
| 4 | CNAME | `target` |
| 5 | MX | `priority`, `server` |
| 6 | NS | — |
| 7 | PTR | — |
| 8 | SOA | 7 fields |
| 9 | SRV | `priority`, `weight`, `port`, `target` |
| 10 | TXT | `data` |
| 11 | CAA | `flag`, `tag`, `value` |

### 11.5 The `data` string convention: match core, one transformation

Core stores record RDATA in `DomainRecord.data` (text), with an optional `data_obj` that core
itself clears on `data` change (§0.5). The plugin writes `data` only — unchanged here.

Core has a **documented convention** for composing `data` from a type's declared fields,
verified in `templates/pages/management/domainrecordtype_helper.html.twig`: field values are
joined with a single space in declaration order, and two transforms apply per field —
`is_fqdn` fields get a **trailing dot appended** if absent, and `quote_value` fields are
**wrapped in double quotes** with inner quotes escaped.

**Decision: the plugin follows core's convention.** Records created manually through GLPI's own
form already exist in these zones; producing a second, subtly different representation for the
same record would make plugin-created and user-created records differ by one character and churn
`record_hash` on every sync.

Applied to the four writable types, and cross-referenced against what IONOS actually returns:

| Type | Core convention | IONOS Hosting wire format | Transformation needed |
|---|---|---|---|
| A | plain value | plain value | none |
| AAAA | plain value | plain value | none |
| CNAME | `target` is `is_fqdn` → **trailing dot** | **no** trailing dot | **strip on write, append on read** |
| TXT | `data` is `quote_value` → **quoted** | **returned quoted** | none — the two agree |

So the entire serialization burden of this feature is **one transformation, on one type, in one
driver.** TXT's agreement is a genuine convergence, corroborated independently: `libdns/ionos`
calls `strconv.Unquote` on TXT content, and a community zone-export tool's author documented
double-quoted TXT as the one thing needing manual correction.

`prio` is meaningful only for `MX` and `SRV`, both excluded, so the write payload always carries
`prio: 0`. Multi-field RDATA (`MX`, `SOA`, `SRV`, `CAA`) is entirely outside this feature.

### 11.6 Rights

**Superseded 2026-07-29 (Phase 34b).** Phase 32 shipped a single right,
`domainmanager:dns_records`, carrying `CREATE`/`UPDATE`/`DELETE`. That model assumed a
plugin-owned write surface (§11.10, pre-Phase-34b) where one blanket right could gate every
write regardless of type. Once Phase 34b moved to intercepting GLPI's own **native**
`DomainRecord` tab (§11.15a) instead of a plugin panel, a single flat right stopped being
granular enough: Óscar's explicit direction was that the plugin's rights should **fully
supersede** native visibility and editability per record type, not just gate a separate panel.

**Four rights rows, one per writable type, each carrying three bits — `CREATE`, `UPDATE`,
`DELETE`:**

- `domainmanager:dns_records_a`
- `domainmanager:dns_records_aaaa`
- `domainmanager:dns_records_cname`
- `domainmanager:dns_records_txt`

Each still rendered via `Profile::displayRightsChoiceMatrix()` (§11.16), one row per type, so the
profile UI reads as four ordinary GLPI right rows rather than a bespoke matrix.
`CREATE`/`UPDATE`/`DELETE` gate the corresponding native operation via the item hooks (§11.7).

**No `READ` bit — investigated and rejected, not merely deferred (2026-07-29).** The original
intent (Óscar: plugin rights should "completely supersede" native permissions) would extend to
hiding unreadable record types from the native tab's row list. Traced against the live
`11.0/bugfixes` source: `CommonGLPI::displayStandardTab()` (`src/CommonGLPI.php:674`) calls
`DomainRecord::displayTabContentForItem()` directly; the only surrounding plugin hooks are
`Hooks::PRE_SHOW_TAB`/`POST_SHOW_TAB`, which fire *before and after*, not *instead of* — there is
no native mechanism to substitute what actually renders. The only two ways to filter rows would
be output-buffering and scraping/stripping `<tr>`s out of `showForDomain()`'s rendered HTML, or
duplicating `showForDomain()`'s ~90 lines wholesale as the plugin's own copy and rendering that
instead. Both are fragile (silently drift the day core changes that method's markup) and neither
is "native" in any meaningful sense — they'd just move the hack from a button into a scraper.
Rejected in favour of leaving visibility native/core-gated for everyone; only CREATE/UPDATE/DELETE
get per-type plugin rights. See §11.17.

**Not a per-type expansion of `domainmanager:unlock_imported`.** That right is unchanged: it
still governs local overrides of plugin locks on non-managed records and emptying the trash. The
new per-type rights govern IONOS-managed records specifically, superseding native visibility and
editability for exactly the four writable types; every other `DomainRecordType` (`NS`, `MX`, …)
is untouched by this section and continues to be governed by core alone.

**Rejected alternatives, recorded:** the original single flat right (Phase 32) is superseded, not
merely extended, because "one row, three bits" cannot express "profile X can see and create TXT
but not touch AAAA." A two-tier critical/non-critical split remains pointless (§11.4 already
excludes `NS`/`MX`). Sixteen flat single-bit rights (4 types × 4 bits) would work but produce an
unreadable profile UI and lose the matrix helper's per-row action-label grid — four matrix rows
is the smallest shape that keeps both granularity and the native look.

**Every entry point checks rights server-side**, entity-aware, as with every existing controller
(§6.3). UI gating (hiding the native edit link or a row of the tab) is cosmetic only; the hooks
in §11.7 are the actual enforcement.

### 11.7 Interaction with existing enforcement

**Superseded 2026-07-29 (Phase 34b).** The original invariant — *"plugin-tracked records are
edited through the plugin, or not at all,"* with `LockEnforcer` blocking native edit/delete
unconditionally — assumed a plugin-owned panel as the sole write surface. Phase 34b inverts this:
**the native `DomainRecord` tab (edit form, massive-action delete, inline create) becomes the
write surface**, and the plugin intercepts it via `hook.php` item hooks rather than blocking it.

**`LockEnforcer` continues to block native edit/delete exactly as before for every field/type
outside this section's scope** (imported non-DNS-provider fields, non-writable record types like
`NS`/`MX`, or writable-type records on a domain whose driver isn't write-capable). For the four
writable types on an IONOS-managed, write-capable domain, `LockEnforcer` steps aside and the item
hooks below become the enforcement point instead — there is still exactly one gate active at a
time per record, just not always the same one.

**Three hooks on `DomainRecord`, mirroring core's own add/update/delete lifecycle — registered in
`setup.php`'s `plugin_init_domainmanager()` (this plugin has no separate `hook.php` file; all
`$PLUGIN_HOOKS` entries live there already):**

- `PRE_ITEM_ADD['DomainRecord']` (`pre_item_add`, before the local row exists) → runs pre-flight
  (rights, type, §0.4 managed-types check, live NS re-check) then `createRecord()`. On failure,
  aborts the local add the same way `LockEnforcer::blockRecordRemoval()` already does
  (`$item->input = false`) — there is never a local row with no corresponding IONOS record. On
  success, the returned `ZoneRecord` (carrying the provider-assigned id) is stashed keyed by
  `spl_object_id($item)` for the paired post-hook below, since `pre_item_add` fires before the
  row has a local id to attach anything to.
- `ITEM_ADD['DomainRecord']` (`post_addItem`, row now exists with a local id) → new entry
  alongside the existing `Infocom`/`Domain` entries already in that array. Retrieves the stashed
  `ZoneRecord` for this same object instance and only then creates the `ImportedRecord` row
  (`remote_id`, `record_hash`), `ImportLock::replaceLocks()`, and the Historical line — mirroring
  what the removed controller's `create()` action did after its own local `add()` call.
- `PRE_ITEM_UPDATE['DomainRecord']` (`pre_updateItem`) → **this itemtype already has an entry**,
  `LockEnforcer::domainRecordPreUpdate` (blocks edits to plugin-imported records). Only one
  callback per itemtype fits in `$PLUGIN_HOOKS[event]['domainmanager']`, so this is extended
  in place, not added alongside: for a record that's a writable type on an IONOS-managed,
  write-capable domain *and* the profile holds the matching per-type `UPDATE` right, it now runs
  pre-flight (rights, type, live NS re-check, live re-fetch-and-diff — §11.10) and
  `updateRecord()` instead of the unconditional block; every other record still hits the
  original lock logic unchanged.
- `PRE_ITEM_DELETE['DomainRecord']` (`pre_deleteItem`) → **also an existing entry**,
  `LockEnforcer::domainRecordPreDelete`, extended the same way. Per §11.11, the *soft*-delete
  (native delete without `$force`, into the trash) is what triggers the upstream IONOS
  `deleteRecord()` call — not the later hard purge. `PRE_ITEM_PURGE['DomainRecord']`
  (`LockEnforcer::domainRecordPrePurge`, emptying the trash) is **untouched by this phase**: the
  record was already deleted at IONOS when it was soft-deleted, so purging the local trash row
  remains gated by `domainmanager:unlock_imported` exactly as today.

Each hook checks, in order: is this record's domain IONOS-managed and write-capable (§11.2/§11.3)?
Is the type one of A/AAAA/CNAME/TXT? Does the active profile hold the matching per-type
`CREATE`/`UPDATE`/`DELETE` bit (§11.6)? Any "no" and the hook simply returns without calling the
driver — native GLPI behaviour proceeds untouched, exactly as it does today for every other
itemtype. Only when all three are "yes" does the hook call the driver; a driver failure throws,
which GLPI surfaces as its own native form/massive-action error and aborts the native write —
there is no scenario where a local write succeeds while the IONOS push silently fails, or vice
versa.

Two mechanical requirements carried over from the original design:

1. **The delete path needs the existing `LockEnforcer::$sync_in_progress` bypass**, the same one
   `RecordReconciler::reconcile()` already sets around reconciliation, so cron-driven
   reconciliation is never mistaken for a hooked user-driven purge.
2. **Native `managed_domainrecordtypes` gate (§0.4) still pre-flights independently.**
   `DomainRecord::prepareInput()` blocks add/update when the acting profile's manageable record
   types exclude the record's type, unless `Session::isCron()`. The hook's own pre-flight must
   check this *before* calling the driver, naming the specific profile setting in the message —
   otherwise a push could succeed at IONOS and then fail to save locally. Because the four
   writable types are all within the set profiles already need for "Update Now" today, this
   requirement does not widen.

### 11.8 `DnsRecordWriterInterface`

New contract at `src/Contract/DnsRecordWriterInterface.php`, sibling of `DnsPipelineInterface`:

- `createRecord()` — returns the provider-assigned record id
- `updateRecord()`
- `deleteRecord()`
- `fetchRecord()` — single-record read, live from the provider

**`fetchRecord()` belongs on the write interface, not the read one.** `DnsPipelineInterface`
exists to fetch whole zones for reconciliation; a single-record read exists only to serve the
edit confirmation modal (§11.9) and has no reconciliation role. Adding it to
`DnsPipelineInterface` would oblige Cloudflare and Dinahosting to implement something neither
needs.

**No `supportedRecordTypes()` method.** With four types, all supported by the one implementing
driver, it would encode nothing. It is the right addition at the moment a second writer driver
disagrees with the first — not before.

**Failures reuse the existing taxonomy** (`AUTH_FAILED`, `FORBIDDEN`, and the rest) through each
driver's own `request()` helper and `DriverException`. **No new error classification is
introduced.** Outbound HTTP continues through `Toolbox::getGuzzleClient()`; credentials continue
through `GLPIKey`.

### 11.9 IONOS implementation

Base `https://api.hosting.ionos.com/dns/v1`, auth `X-API-Key: <prefix>.<secret>` — both already
in use by `fetchZoneRecords()` (§3.9).

**Endpoints and payload shape below are taken from the authoritative live spec**,
`https://developer.hosting.ionos.de/assets/kms-swagger-specs/dns.yaml` (openapi 1.0.2, fetched
and read directly 2026-07-29 — the DNS counterpart of the `domains.yaml` spec §3.9 already used,
served the same way: `application/octet-stream`, pulled with `curl`). This **corrects two
assumptions** an earlier draft made from the `libdns/ionos` reference client alone (kept below,
struck through in spirit, for the record):

| Operation | Request | Notes |
|---|---|---|
| create | `POST /zones/{zoneId}/records` | Body is a JSON **array**; response is `201` with an array of created records **including their ids** — reference client was right here |
| update | `PUT /zones/{zoneId}/records/{recordId}` | Body is the `record-update` schema, **`{content, ttl, prio, disabled}` only — no `name`/`type`**; response is `200` **with a full record body** (`record-response`) |
| delete | `DELETE /zones/{zoneId}/records/{recordId}` | `200`, no documented response body |

Create payload (`record` schema): `{name, type, content, ttl, prio, disabled}`. Update payload
(`record-update` schema) is the narrower `{content, ttl, prio, disabled}` shown above.

**Wire-level traps, each with a required response:**

1. **`PUT` is a partial update, not a full replace — corrected 2026-07-29.** The spec's own
   `record-update` request schema has no `name`/`type` field, and its `200` response is a full
   `record-response` body, not empty. Both halves of the original assumption (full-replace body
   required; no response body) were wrong, sourced from the reference client rather than the
   spec. This removes the "live re-fetch structurally required to build a valid body" rationale
   §11.10 originally gave for its live re-fetch step — that step is kept for the confirmation-modal
   UX itself (§11.10), not because the API demands a full payload.
2. **TTL below 60 is rejected with HTTP 400.** Not asserted anywhere in the schema itself (no
   `minimum` on `ttl`), so this remains reference-client/live-probing knowledge, not spec-verified.
   The reference client omits the field entirely when TTL is 0. Both belong in modal validation,
   client- and server-side.
3. **`disabled` must be sent explicitly as `false`.** The schema defaults it to `false`, but the
   reference client's own comments raised doubt about the server-side default; sending it
   explicitly removes the ambiguity regardless of which is correct.
4. **Names are absolute at IONOS and carry no trailing dot.** Corrected 2026-08-03 (Phase 58 audit):
   the read path does **not** relativise — `fetchZoneRecords()` passes `$row['name']` straight
   through, and this is correct, not a gap, since Phase 58 settled the absolute FQDN as the
   internal canonical form regardless of provider. The only wire-level transform IONOS needs is on
   write: strip a trailing dot before sending, via `IonosDriver::wireHostname()`.
5. **The create response carries the provider id**, so `remote_id` is captured at push time from
   the response itself — no follow-up read, no reliance on the next sync. (This is all that
   remains of an earlier "store the provider id" work item: the `remote_id` column already
   exists and is indexed, and `RecordReconciler` already matches on it first, falling back to
   `record_hash`.)
6. **`ALIAS` is absent from the spec's `recordTypes` enum entirely** — confirms, rather than
   merely infers (§11.4), that it doesn't exist on the Hosting DNS API.

**Zone resolution** reuses `findZoneId()` unchanged, including its client-side case-insensitive
match — the API has no filter-by-name parameter for zones.

**Implementation note (Phase 33):** `IonosDriver` stores/round-trips `$data` in the same
convention `ZoneRecord::$data` already uses for reads (TXT unquoted, CNAME with no trailing dot —
see `DnsRecordWriterInterface`'s docblock), not the literal core field-join convention this
section originally described. That is consistent with what `fetchZoneRecords()` and
`RecordReconciler` already do today: `extractContent()` unquotes TXT on read and never appends a
CNAME dot, so a plugin-written record's `$data` matches what the next sync would read back —
which is the actual property §11.5 was protecting.

### 11.10 Immediate push, pre-flight replaces the confirmation step

**Superseded 2026-07-29 (Phase 34b).** The original design (Phase 34) built three purpose-built
confirmation modals in front of a plugin-owned write path. Phase 34b removes the plugin-owned
path entirely (§11.7) in favour of intercepting GLPI's own native add/edit/purge — there is no
modal to place a confirmation step in front of, because there is no longer a plugin-rendered
step in the flow at all. **Every check the modals used to perform up front now runs as
server-side pre-flight inside the item hook, and a failure aborts the native operation with
GLPI's own native error surface**, rather than being caught earlier in a dedicated screen.

**No staging, no pending state, no Apply step** — unchanged from the original rejection: a queued/
batch-apply model was designed and rejected as unnecessary mechanism (kept here for the record).

**What the hooks check before calling the driver, all carried over from the modal design:**

- **Live re-fetch-and-diff before update.** `pre_updateItem` calls `fetchRecord()` against IONOS
  before pushing, not the local mirror. The mirror is only as fresh as the last sync, so someone
  editing in IONOS's own panel since then would have their change silently overwritten by a GLPI
  edit built on stale values. If the live values disagree with the local mirror, **the hook aborts
  the save and surfaces the disagreement in the native error message** (no modal to render it in
  any more). If the fetch itself fails, proceed with the local values but attach a visible warning
  that they could not be verified against the provider — never silently.
- **A live NS re-check runs immediately before every push** (create/update/delete alike). The
  re-fetch proves the *record* still exists; it does not prove IONOS is still authoritative for
  the zone. Nameservers can have moved to another provider since the last sync while the zone
  remains present in the IONOS account, in which case the API would accept a write that changes
  nothing anyone resolves. Re-checking the domain's NS immediately before pushing closes that
  window (§11.3's volatility) — this check now lives in the hook, not a modal's pre-submit step.
- **Delete confirmation is native GLPI's own** (native "are you sure" on the row/massive-action
  soft-delete) — no plugin-rendered delete modal remains; `pre_deleteItem`'s pre-flight (§11.7) is
  the actual safety net that pushes the IONOS delete, the native confirm dialog is only UX. The
  later hard purge (emptying the trash) has nothing left to push upstream — the record was
  already deleted at IONOS when it was soft-deleted — so it stays exactly as gated today
  (`domainmanager:unlock_imported`, §11.11).

### 11.11 Delete is a local soft-delete

Upstream: the record is deleted at IONOS. Locally: **soft-deleted into GLPI's native trash**, via
`DomainRecord::delete(['id' => $id])` **without** `$force` — `DELETE`, never `PURGE`.

This is the established mechanism, not a new one: `glpi_domainrecords` genuinely has an
`is_deleted` column, `DomainRecord::maybeDeleted()` is `true` on `11.0/bugfixes`, and §5.4 already
routes upstream-vanished records through exactly this path. **§5.4's rule that the plugin never
hard-deletes a native record stands unbroken.** Emptying the trash remains governed by the
existing `domainmanager:unlock_imported`.

**One residual risk, documented rather than mechanised:** an admin restoring such a record from
the native trash gets a row that looks live in GLPI but no longer exists at IONOS. The next sync
re-trashes it within one cron interval, because upstream remains authoritative. This is a
**TESTING.md line**, not a reason to build restore-time interception.

### 11.12 Schema: one column

**`is_glpi_created`** — `tinyint`, default `0`, added to `glpi_plugin_domainmanager_records` via
idempotent `Migration::addField()` + `addKey()`, exactly as `is_proxied` was.

**Why store it at all**, when editability is deliberately derived (§11.2): this is *history*,
not capability. It is unrecoverable if not captured at creation — nothing about a record later
reveals who authored it. For the plugin's first write path, "show me every record this feature
created" is a query worth being able to answer, in testing and in incident review.

- **Default `0` is factually correct, so there is no backfill.** Every pre-existing row was
  created by the reconciler.
- **Set once at creation, never changed** — immutable, like `is_managed`. The reconciler's update
  path must leave it alone; a record authored in GLPI stays authored in GLPI even after upstream
  edits.
- `tinyint` over a varchar enum, deliberately: the question is binary and stays binary.

**The create path writes the `ImportedRecord` row by reusing `RecordReconciler::createRecord()`**
— `remote_id` from the API response, `record_hash`, `is_managed = 1`, `is_glpi_created = 1`. Not a
parallel implementation. A second code path constructing ownership rows is a second code path to
drift.

**Search option:** `datatype => 'bool'`, `massiveaction => false`, reusing 9404's
`jointype => 'child'` shape. The id is allocated with `tools/getsearchoptions.php` against the
live instance, checking existing ids for `DomainRecord` first to avoid collision.

**Note the known `bool` search-engine behaviour (§5.7):** `equals`/`notequals` are exact, but
`empty` means "0 **or** NULL" for the `bool` datatype in core, and cannot be overridden for a
search option a plugin merely adds to a core itemtype. With a non-nullable default of `0` this is
harmless here, but it is why the column is not nullable.

**Editability is deliberately not searchable.** No mirror column, no two-hop-join search option
— it is a live-computed badge only. Filtering the Domain list by DNS provider approximates it
today, exactly, while IONOS is the only writer. A stored mirror on `states`, recomputed wherever
`is_managed` already is, remains available as a small self-contained addition if a saved search is
ever wanted.

### 11.13 Historical logging: prefixed lines, zero new search options

Every write logs to the **parent `Domain`'s** Historical tab — consistent with `SyncLogger` — via
the plugin's established convention:

```
Log::history($items_id, Domain::class, [0, '', '[Domain Manager] ' . $message]);
```

`id_search_option = 0` plus a text prefix. **No search option is registered for logging.** Per
§3.7.1, registering one purely to obtain a Historical label also makes it a permanent Search
column and filter — GLPI 11 has no label-only flag — which is exactly why 9401/9402/9403 were
removed (§9 Phase 27).

Four lines, e.g.:

```
[Domain Manager] Record created from GLPI: A www → 1.2.3.4 (TTL 3600)
[Domain Manager] Record updated from GLPI: CNAME shop — data 1.2.3.4 → 5.6.7.8
[Domain Manager] Record deleted from GLPI: TXT _acme-challenge
[Domain Manager] Record push to IONOS failed: <safe message>
```

### 11.14 No rollback, anywhere

If the push succeeds and the local write then fails, **nothing is rolled back at the provider.**
The reconciler is the backstop and it already does the right thing:

- pushed upstream, local write failed → next sync **re-imports** the record
- deleted upstream, local soft-delete failed → next sync **re-trashes** it

Both converge because upstream is authoritative. Compensating writes would mean issuing a second
provider mutation to undo a first, from a code path that has just demonstrated it is failing —
strictly worse than letting the existing convergence mechanism do its job. **This is a deliberate
design choice and should not be "fixed" later.**

**§10 amendment (still required, not yet decided).** §10's changelog policy is written entirely
around plain `x.y.z` sections; pre-release identifiers (`-alphaN`/`-betaN`, §11.15) are new to
this plugin. §10 must be extended to state how those sections relate to the eventual `1.2.0`
section — whether `1.2.0` consolidates the pre-release bullets into one section or is a bare bump
referring back to them. §10 already has a rule for content-free bumps, so either is consistent —
it needs choosing once, in writing, before Phase 32's first version bump lands.

### 11.15 Phases and versioning

Six phases. Each is one Claude Code session, committed and pushed before context is cleared.

| Phase | Version | Content |
|---|---|---|
| **31** | — | This architecture section. **No code.** Stop for approval. |
| **32** | `1.2.0-alpha1` | Schema (`is_glpi_created`), one right, one search option |
| **33** | `1.2.0-alpha2` | `DnsRecordWriterInterface` + IONOS implementation. Testable via throwaway harness, no UI |
| **34** | `1.2.0-alpha3` | Controllers, three modals, rights gating, §0.4 pre-flight, live NS re-check, `ImportedRecord` row, Historical lines. No UI trigger yet — the modals exist and are reachable by URL but nothing in GLPI's own `DomainRecord` tab opens them. **Superseded by Phase 34b** (§11.15a): the controller/modal write path is removed, not wired up, once the native-hook design was adopted |
| **34b** | `1.2.0-alpha4` | Rights redesigned as a per-type READ/CREATE/UPDATE/DELETE matrix (§11.6); native tab fully superseded — `hook.php` item hooks intercept native add/update/purge (§11.7), pre-flight logic ported from the removed modals into the hooks (§11.10), `DomainRecord`'s native tab display overridden to filter rows by per-type READ (§11.15a) |
| **35** | `1.2.0-beta1` | End-to-end verification; finalise `ARCHITECTURE.md`, `CHANGELOG.md`, `TESTING.md`. Refining only, nothing new built (implemented 2026-07-29) |
| **36** | `1.2.0-beta2` | Managed-domain indicator + conditional hiding of native `DomainRecord::showForDomain()` add controls (§11.18) — client-side companion to §11.15a/§11.17's "no hook substitutes what the tab renders" finding: that's still true server-side, but `POST_SHOW_TAB` can drive a JS hide of the *rendered* controls |
| **37** | `1.2.0-beta3` | Custom write-back UI supersedes Phase 36's conditional hiding (§11.19): native add controls always hidden and replaced by a Domain-Manager-branded, per-type-rights-scoped add form on any write-back-editable domain; "goes live" banner/field-lock on a plugin-imported record's own edit page; delete/purge confirmation. UI-visibility layer only — no change to the underlying, already-authoritative `LockEnforcer`/`DnsRecordWriteback` enforcement |
| release | `1.2.0` | |

**Alpha means "still assembling"; beta means "complete and hardening"** — an honest signal if a
client is to test before release.

### 11.15a Phase 34b: native tab superseded by per-type rights, not a bespoke design

**Explicit constraint, set by Óscar when Phase 34 landed with no UI trigger:** the create/edit/
delete actions must be surfaced as **GLPI's own native buttons/icons**, in GLPI's own native
`DomainRecord` tab, not a plugin-designed toolbar or row layout bolted alongside it. Investigating
this against the live `11.0/bugfixes` source changed the design further, twice, before landing
here — recorded in order so the reasoning isn't lost:

**Finding 1 — the native tab has no per-row extension point.** `DomainRecord::showForDomain()`
(`src/DomainRecord.php:406`) is a hand-rolled table, not a `Search::show()`-driven list: it builds
its own `$entries` array from a direct DB query and renders through
`components/datatable.html.twig`. It already has native affordances — the `name` column links to
core's own full-page edit form, `showmassiveactions` gives core's own checkbox/purge toolbar, and
an inline "link a record" + collapsible create form covers creation — but none of them are a
plugin extension point; core's edit form and massive-action purge write straight to
`glpi_domainrecords` with no hook for an external API call. **This ruled out "add a button that
opens our modal"**: there is nowhere in this specific view to attach a per-row custom button
without hand-editing/duplicating `showForDomain()`, which is a bigger deviation from "native" than
adding a button would have been in the first place.

**Finding 2 — the real native extension point is `hook.php` item hooks.** GLPI's real convention
for a plugin intercepting native CRUD on a *core* itemtype it doesn't own is `hook.php`'s
`item_add`/`item_update`/`item_purge` per itemtype (§11.7) — core's own UI stays untouched, the
plugin observes/vetoes at the model layer. Discussing scope, Óscar's initial goal was for the
plugin's own rights to fully supersede native permissions, including which types a profile can
even *see* — but that half (READ/visibility) turned out to have no hook equivalent at all:
`CommonGLPI::displayStandardTab()` (`src/CommonGLPI.php:674`) calls
`DomainRecord::displayTabContentForItem()` directly, and the only surrounding plugin hooks
(`Hooks::PRE_SHOW_TAB`/`POST_SHOW_TAB`) fire before/after, never instead-of. Investigated and
rejected rather than built — see §11.6's `READ` note and §11.17.

**Resolution:** writes only, via the native extension point that actually exists. Create/update/
purge go through the three `hook.php` item hooks in §11.7, leaving core's own edit form,
massive-action toolbar and inline-create form completely untouched as UI — the plugin only ever
intercepts at the model layer, never renders its own button, modal, or row filter. Visibility
stays core-gated for every profile, same as any other `DomainRecord`.

**Superseded from the original Phase 34b scope:** `Ajax::createModalWindow()`/`SupplierTab.php`'s
modal-open convention, the GET-loadable modal route variants, and per-row edit/delete button
rendering are all dropped — there is no plugin-rendered button or modal left in this design at
all (§11.10). `src/Controller/DnsRecordWriteController.php` and its three Twig modals become dead
code; their pre-flight logic (rights, type, live NS re-check, live re-fetch-and-diff) is ported
into the `hook.php` handlers instead of being deleted outright.

**Phase 32 must bump `PLUGIN_DOMAINMANAGER_VERSION` (from `1.1.0`) or `Installer::install()`
never re-runs** and the migration silently does not apply. This is the Phase 15 trap; it is the
single most likely way this feature fails to install.

**Five bumps means five manual reactivations** on the live 11.0.8 instance — GLPI auto-deactivates
a plugin on every version change until an admin reactivates it (§3.6.1). Expected, not a fault.

### 11.16 Verifications required before the corresponding phase

Confirm against the live `11.0/bugfixes` branch and live provider docs. **Never from recall.**

**Before Phase 32:**
- Exact core right-bit constants and the actual core mechanism for rendering a single right's
  CREATE/UPDATE/DELETE checkboxes. **Resolved (2026-07-29), correcting an earlier error:**
  `Profile::displayRightsChoiceMatrix()` **does exist** on `11.0/bugfixes` (`src/Profile.php:3565`)
  — the prior note claiming it doesn't exist was checked against the wrong branch (`main`, i.e.
  GLPI 12-dev) and is wrong; `glpi-plugin-builder`'s Trap 12 is being corrected to match. What is
  true, and the actual reason a plugin can't just reuse core's tree wholesale: `Profile::getRightsForForm()`
  (which assembles the *core* rights tree passed into that method from `base_tab.html.twig`) is
  explicitly documented "only used for GLPI core rights and not rights added by plugins." A plugin
  instead calls `displayRightsChoiceMatrix()` directly with its own hand-built `$rights` array:

  ```php
  $rights = [[
      'label' => __('Domain Manager - DNS record write-back', 'domainmanager'),
      'field' => 'plugin_domainmanager_dnsrecord',
      'rights' => [
          CREATE => __('Create'),
          UPDATE => __('Update'),
          DELETE => __('Delete'),
      ],
  ]];
  $profile->displayRightsChoiceMatrix($rights, [
      'canedit' => Session::haveRight('profile', UPDATE),
      'title'   => __('Domain Manager'),
  ]);
  ```

  called from the plugin's own `Profile`-tab hook (`getTabNameForItem`/`displayTabContentForItem`
  when `$item instanceof Profile`), the same pattern core's own `base_tab.html.twig` macro uses.
  `Html::showCheckboxMatrix()` is the renderer underneath `displayRightsChoiceMatrix()`, not
  something the plugin calls directly. §11.6 is corrected to point at this mechanism.
- That a non-semver-clean version suffix (`-alpha1`) triggers the migration re-run normally in
  `src/Plugin.php` and is neither rejected nor mis-ordered by plugin validation. This is
  **explicitly unverified** — an earlier assumption that the check is a plain string comparison
  was never confirmed, and GLPI uses `version_compare` for `minGlpiVersion` elsewhere. Cheap to
  check; the whole schema path depends on it.

**Before Phase 33: done (2026-07-29).**
- Fetched and read `https://developer.hosting.ionos.de/assets/kms-swagger-specs/dns.yaml`
  (`curl`, served as `application/octet-stream` as expected; openapi 1.0.2). Confirmed: `ALIAS`
  is **absent** from the `recordTypes` enum entirely (§11.4's evidence-based exclusion is now
  spec-confirmed, not just inferred); no `minimum`/`maximum` constraint is declared on `ttl` or
  `prio` anywhere in the schema, so the "TTL below 60 is rejected" and priority behaviour remain
  reference-client/live-probing knowledge, not spec-verified — unchanged risk, now explicitly
  labelled as such in §11.9 rather than silently assumed.
- Confirmed create/update/delete paths and payload schema against the spec, **correcting** two
  reference-client-only assumptions in the original §11.9: `PUT` (update) takes a narrower
  `{content, ttl, prio, disabled}` body (no `name`/`type`) and its `200` response is a full record
  body, not empty. Detail moved into §11.9 itself so it isn't duplicated here.

**Before Phase 34:**
- GLPI 11's own confirmation-modal convention, before hand-rolling one.

**Before Phase 35: done (2026-07-29).**
- Code-level verification of Phase 34b implementation (ARCHITECTURE.md §11.7/§11.15a):
  confirmed all four `hook.php` item hooks (PRE_ITEM_ADD, ITEM_ADD, PRE_ITEM_UPDATE,
  PRE_ITEM_DELETE) are registered in `setup.php` and routed to `DnsRecordWriteback` (create/update)
  or extended `LockEnforcer` (update/delete). Pre-flight checks (rights, type, manageable-types,
  live NS re-check, live re-fetch-and-diff on update) are present in code and fire before any
  driver call. Per-type rights (dns_records_a/aaaa/cname/txt) with CREATE/UPDATE/DELETE bits are
  defined in `Profile.php` and rendered via `displayRightsChoiceMatrix()` per GLPI 11 conventions.
  `ImportedRecord` rows created at post-add time with `is_glpi_created = 1`. Historical logging
  format (§11.13) is implemented. Soft-delete (native delete to trash) pushes to IONOS; hard
  purge is local-only, gated by `domainmanager:unlock_imported` (§11.11). Non-writable types
  (NS/MX/etc.) and non-IONOS domains (Cloudflare/Dinahosting/unmanaged) are unaffected, falling
  through to existing `LockEnforcer` logic unchanged (§11.7). Dead `DnsRecordWriteController`
  (Phase 34's controller/modals) is unreachable from routing; pre-flight logic ported into the
  hooks per §11.15a. Static verification only, not live HTTP, in this first pass.
- **Live addendum (2026-07-29, same day):** `glpi-claude` (port 65008) brought up and the
  plugin installed/activated for real, migrating cleanly from `1.2.0-alpha4` to `1.2.0-beta1`.
  Because this container's suppliers carry real production IONOS/Dinahosting/Cloudflare
  credentials (not throwaway test accounts), the live pass was deliberately scoped to
  **read-only checks** (confirmed with Óscar first, rather than assumed): per-type rights
  registration confirmed in the DB (32 rows, all default `0`, matching "not auto-granted to any
  profile"); rights matrix confirmed live-rendered on the Profile "Domain Manager" tab via a
  real authenticated HTTP session (Playwright/Chromium), matching `Profile::getAllRights()`
  exactly; the native `DomainRecord` tab on a real IONOS-managed domain (`desmarque.es`, 18
  real records) confirmed rendering normally with a working "New Domain record" button,
  live-reconfirming §11.15a's "Add Record was never actually gated" finding. A real
  create/update/delete round trip (confirming an actual push reaches IONOS, and that a push
  attempted without the per-type right creates the record locally with no upstream call) was
  **not** performed — deliberately deferred, not silently skipped, same as the live-driver-call
  precedent in §3.8/§3.9: it needs a throwaway zone or Óscar's direct involvement, not a routine
  read-only pass. See `TESTING.md` §35.17 for the full detail. §11.15's table marks Phase 35 as
  implemented; §10 amendment for pre-release changelog consolidation is recorded (pre-release
  sections remain separate in the file; the final `1.2.0` section consolidates them into one
  section at release time).

### 11.17 Deferred and rejected, recorded so they are not silently revisited

| Item | Disposition |
|---|---|
| Read-scope expansion to all 11 GLPI types | **Implemented** (post-1.2.0). `ZoneRecord::TYPES` / `Installer::RECORD_TYPE_NAMES` now list all 11 GLPI types (added `ALIAS`, `PTR`, `SOA`, `SRV`, `CAA`), so the three drivers' read-scope filter no longer blocks them. **Resolved, doc-verified, Phase 50 (2026-08-03; no live account access used for any of the three, per explicit request — this is a documentation-based check, not a live-tested guarantee):** IONOS's 2026-07-29 conclusion (flat `content`, no split sub-fields) re-checked, still stands. Cloudflare's current DNS Records API reference confirms SRV/CAA responses carry both a structured `data` object *and* a fully pre-serialized `content` string (same shape MX already used) — `CloudflareDriver`'s existing flat pass-through is correct as-is; SOA is not a Cloudflare *record* type at all (zone-level, not exposed via the records endpoint), so no SOA row ever reaches that driver's read loop. Dinahosting has no documented structured shape for SRV/SOA/CAA in its own API docs, and the reference client (`libdns/dinahosting`) treats SRV as an opaque flat value and doesn't mention SOA/CAA at all — `DinahostingDriver::extractContent()`'s existing generic fallback is the correct best-effort handling. No code changes to `WRITABLE_TYPES` or any create/update path; this was a read-path-only check (SRV/SOA/CAA remain write-disabled by design, §11.4/§11.8). See the doc-verified comments in each driver's extraction method for sources. |
| `ALIAS` writable | **Dropped on evidence** (§11.4). Settle definitively via the spec enum, or a single POST against a throwaway zone. |
| `NS` / `MX` writable | **Excluded by design** (§11.4), not an oversight. |
| Apex-NS detection, apex read-only rule, "delegation lives at the registrar" modal copy | **Moot** — `NS` is not writable. Reasoning preserved in §11.4 so it is not re-derived. |
| `SOA` / `PTR` special handling | **Moot**, same reason. |
| Staged changes with an Apply step | **Rejected** (§11.10). |
| Two-tier rights (`…_critical`) | **Rejected** — no critical tier remains (§11.6). |
| Stored `is_readonly` / two-flag provenance | **Rejected** — derived capability cannot drift (§11.2). |
| Reconciler exception list for unread types | **Rejected** — carves an exception into the one mechanism whose entire job is "upstream is authoritative". |
| `supportedRecordTypes()` on the writer interface | **Deferred** until a second writer driver exists (§11.8). |
| Editability as a searchable field | **Deferred**; Option 3 (mirror column on `states`) noted in §11.12. |
| Rollback / compensating writes | **Rejected permanently** (§11.14). |
| Single flat `domainmanager:dns_records` right (Phase 32 shape) | **Superseded** by a per-type CREATE/UPDATE/DELETE matrix, once the write surface moved to native-tab hooks (§11.6). |
| Plugin-owned write panel + 3 confirmation modals + `DnsRecordWriteController` (Phase 34 shape) | **Superseded** by native-tab `hook.php` interception (§11.7/§11.10/§11.15a); the controller/modals become dead code, pre-flight logic ported into the hooks. |
| Per-type `READ` right hiding rows from the native `DomainRecord` tab | **Investigated and rejected**, not deferred: no native hook lets a plugin substitute `DomainRecord::displayTabContentForItem()`'s output (`PRE_SHOW_TAB`/`POST_SHOW_TAB` fire around it, not instead of it); only HTML-scraping or a wholesale duplicate of `showForDomain()` would work, and both are fragile in a way genuinely worse than not having the feature (§11.6, §11.15a). Note this is about server-side row filtering specifically — client-side JS hiding of the whole add-controls block via the same hooks is a different, much smaller surface, and was implemented in Phase 36 (§11.18), then superseded by Phase 37's custom add panel (§11.19). |
| Native add controls hidden only when the user holds *no* per-type CREATE right at all (Phase 36 shape) | **Superseded** by Phase 37 (§11.19): native add controls are now always hidden on a write-back-editable domain and replaced by a custom, rights-scoped add panel, regardless of how many CREATE rights the user holds. |

### 11.18 Phase 36: managed-domain indicator + conditional hiding of native add controls

**Trigger:** live testing surfaced that GLPI core's own "Link a record" dropdown+Add and "New
Domain record for this item" controls (§11.15a) are unconditionally visible on *every*
`DomainRecord` tab, including on an IONOS-managed domain for a profile holding none of the
per-type write-back rights (§11.6) — confusing, since a native add there either falls through to
a local-only row or gets rejected outright by `DnsRecordWriteback::onPreAdd()`. Separately, there
was no visual indicator anywhere on the `DomainRecord` tab (or the item header) that a domain is
under Domain Manager's management at all — only `supplier_domains_list.html.twig` and
`domain_panel.html.twig`'s own status cards show that, and neither is visible from the Records tab.

**Two features, both client-side, both hooked the same way:**

1. **Managed indicator** — a small `ti ti-world-cog` icon appended to core's own
   `.navigationheader-title` element (`src/CommonGLPI.php::showNavigationHeader()`, confirmed on
   `11.0/bugfixes`), shown whenever `DomainState.is_managed` is true for the domain being viewed.
   Deliberately an icon with a `title` tooltip, not a badge/banner — Óscar's own call, after an
   initial "managed" badge suggestion read as too heavy for something that should read as native
   chrome, not a bolted-on notice.
2. **Conditional hiding of the native add controls** — on the Records tab specifically
   (`options['itemtype'] === DomainRecord::class`), hidden only when *both* (a) the domain's DNS
   is under IONOS write-back (`DnsRecordWriteback::isDomainDnsEditable()`) and (b) the current user
   holds none of the four per-type CREATE rights (`DnsRecordWriteback::userMayCreateAnyType()`).
   Per Óscar's explicit call: a user who *can* write at least one type keeps seeing the native
   controls unchanged — this is a permission-driven visibility choice, not a blanket "managed
   domains never get native add" rule.

**Why this doesn't contradict §11.15a/§11.17's rejection of server-side row-hiding:** that finding
is still correct — no hook substitutes what `DomainRecord::displayTabContentForItem()` renders.
What's new here is a *client-side* DOM hide, driven by `Hooks::POST_SHOW_TAB` (confirmed on
`11.0/bugfixes`, `src/CommonGLPI.php::displayStandardTab()`: fires once per non-main tab actually
rendered — including on the very page load where that tab is the `forcetab`-forced one, not only
on later ajax tab switches — via `Plugin::doHook(Hooks::POST_SHOW_TAB, ['item' => $item, 'options'
=> $options])`, a plain (non-itemtype-keyed) hook whose callback filters on `$item instanceof
Domain` itself, same convention as the existing `POST_ITEM_FORM` → `DomainForm::inject()`). It
hides the *whole* add-controls block (the outer `<div class="mb-3">` wrapping both the "Link a
record" form and the "New Domain record" button+collapsible form — confirmed verbatim from
`src/DomainRecord.php::showForDomain()`) by selecting on the one stable, non-translated,
non-`mt_rand()` hook available: `form[id^="domain_form"]`, then hiding its closest `div.mb-3`
ancestor. Matching on visible button text was ruled out (locale-dependent); matching on the
`mt_rand()`-suffixed `add_new_record_btn{{ rand }}` id alone was ruled out (doesn't reach the
sibling "Link a record" form in one selector).

**Two hook entry points needed, not one:** `POST_ITEM_FORM` (existing, `DomainForm::inject()`)
only fires on the Domain item's own main form tab — a user landing directly on the Records tab
(`forcetab=DomainRecord$1`) never triggers it. `POST_SHOW_TAB` (new, `DomainForm::onShowTab()`)
covers every *other* tab. Both call the same private `DomainForm::renderManagedIndicator()`
helper, rendering one shared Twig partial (`templates/domain_managed_indicator.html.twig`), so the
icon-insertion script has exactly one implementation regardless of which hook fired it. The icon
insertion is idempotent (checks for an existing `.domainmanager-managed-icon` child before
appending) and only needs to run once per full page load — `showNavigationHeader()` renders the
header once, tab-agnostic, and is never replaced by client-side ajax tab switching, so there's no
need to re-run on every tab click.

### 11.19 Phase 37: custom write-back UI, superseding Phase 36's conditional hiding

**Trigger:** live-testing Phase 36 with a real profile (`tech`/Technician) surfaced that the
underlying concern wasn't really "hide the buttons when rights are missing" — it's that reusing
core's generic native controls for a write-back action gives no visual cue that a click is about
to reach a real external provider live, with no rollback (§11.14), regardless of whether the user
technically holds the right to do it. Óscar's explicit call: distinct, plugin-branded controls for
create — reusing native buttons here specifically risks an accidental live create/edit/delete —
plus visible "this goes live" warnings on edit/delete, plus front-end blocking of editing locked
(non-writable-type) records, not just the existing server-side strip-after-the-fact.

**Explicitly kept driver-agnostic, not IONOS-specific**, per Óscar's instruction: every new
`DnsRecordWriteback` helper (`hasTypeRight()`, `writableTypes()`, `creatableTypesForDomain()`,
`writableSupplierName()`) and every new template gate on `isDomainDnsEditable()` (true for *any*
`DnsRecordWriterInterface` driver — currently only `IonosDriver`, but the check itself never names
it) and on the generic per-type rights matrix (§11.6) — nothing here hardcodes IONOS. Adding a
second write-capable driver (Cloudflare, Dinahosting) needs no change to this UI layer at all.

**Three surfaces, one underlying principle — make the existing, already-authoritative
enforcement visible *before* the click, not just after:**

1. **Add, replacing native entirely (`templates/domainrecord_add_panel.html.twig`,
   `DomainForm::renderRecordWritePanel()`).** Supersedes Phase 36's "hide only when the user holds
   *no* per-type CREATE right at all" — now, on any write-back-editable domain, the native "Link a
   record"/"New Domain record" block (§11.15a/§11.18) is unconditionally hidden and replaced with a
   Domain-Manager-branded form, same DOM relocation technique as §11.18 (`form[id^="domain_form"]`
   → closest `div.mb-3`). The replacement form's type `<select>` is built server-side from
   `DnsRecordWriteback::creatableTypesForDomain()` — only types this user holds CREATE for — so
   there is no type option in the form that could ever be silently rejected or fall through to a
   local-only add; if that list is empty, the panel shows an explanatory message instead of an
   empty form. The form still POSTs to `DomainRecord::getFormURLWithID(0)` with a plain `name="add"`
   submit — GLPI's own generic `CommonDBTM::add()` flow, so `DnsRecordWriteback::onPreAdd()`/
   `onPostAdd()` fire exactly as they do for the native path (§11.7). This is a *new UI*, not a
   revival of Phase 34's removed controller/modals (§11.15a) — no new controller, no new route, no
   duplicated business logic; only a differently-styled, narrower-scoped `<form>` posting to the
   same native endpoint.
2. **Edit — banner or lock, never silent (`templates/domainrecord_edit_panel.html.twig`,
   `DomainForm::injectDomainRecord()`).** Reached through `DomainForm::inject()`'s existing
   `Hooks::POST_ITEM_FORM` registration (GLPI allows exactly one callback per plugin per hook, so
   `inject()` now dispatches on `$item instanceof Domain` vs. `instanceof DomainRecord` rather than
   registering a second hook entry). For a plugin-imported record (`ImportedRecord::isPluginOwned()`)
   of a writable type where the user holds the per-type UPDATE right: a ribbon banner names the
   live-update consequence. Otherwise — non-writable type (NS/MX/SOA/…) *or* a writable type the
   user lacks UPDATE for — every editable field (`name`/`data`/`ttl`/`domainrecordtypes_id`) is
   cosmetically disabled with a lock icon, same convention `injectDomain()` already uses for
   Domain's own synced fields. This directly answers "block editing of the locked records": the
   *enforcement* already existed (`LockEnforcer::domainRecordPreUpdate()` silently strips these
   fields either way), this phase only stops the round trip that used to be needed to discover that.
3. **Delete/purge confirmation, same template/hook.** When the user holds the per-type DELETE right
   for a writable, plugin-imported record, a `window.confirm()` guards the native "Put in
   trashbin"/"Delete permanently" buttons (`name="delete"`/`name="purge"`, confirmed verbatim on
   `11.0/bugfixes`'s `templates/components/form/buttons.html.twig`). No confirmation is added when
   the user lacks the right — `LockEnforcer::blockRecordRemoval()` already blocks it server-side
   with an error message; adding a client-side warning for an action that's going to be rejected
   anyway would be noise, not signal.

**Deliberately not built, and why:** per-row custom edit/delete buttons *inside* the Records-tab
table itself remain out of scope, unchanged from §11.15a's Finding 1 — `showForDomain()` still has
no per-row extension point, and this phase's edit/delete affordances live on the record's own
native full-page edit form instead, which *does* have a clean hook (`POST_ITEM_FORM`). Bulk/massive-
action delete (checkbox selection across multiple rows on the Records tab) is not given a per-row-
type-aware confirmation — cheaply determining which selected checkbox ids are writable+permitted
before the confirm dialog would need extra plumbing disproportionate to the value versus the
single-record edit-page confirmation above; flagged as a known, deliberate gap rather than silently
skipped.

**Live-testing addendum (found immediately, 2026-07-29, before this phase was even committed):**
hands-on testing of the new add panel surfaced three more issues, all fixed in the same change:

- **Real bug, predates this phase:** `DnsRecordWriteback::onPreAdd()`'s absolute-name construction
  was backwards — `"$zoneName.$name"` (e.g. `beiro.net.dnss`) instead of
  `DnsRecordWriterInterface::createRecord()`'s own already-correctly-documented convention,
  `"$name.$zoneName"` (`dnss.beiro.net`). IONOS rejected every non-apex create with
  `INVALID_RECORD`/`invalidFields: ["name"]` because of this — not a driver-side or IONOS-side
  problem, this call site simply built the string in the wrong order. Fixed at the one place that
  builds it (§11.9/§11.10 unaffected otherwise — `onPreUpdate()` never built an absolute name at
  all, passing the relative `name` field straight through, which is why only `create` was broken).
- **Redirect control:** GLPI's generic add handler
  (`front/domainrecord.form.php`: `if ($_SESSION['glpibackcreated'] && !isset($_POST['_in_modal']))
  Html::redirect(...); Html::back();`) would otherwise bounce the user to the new record's own
  native edit page after a successful create — jarring right after emphasizing "you're now on a
  Domain-Manager-branded panel, not the native UI." The custom add panel now sets a hidden
  `_in_modal=1` (not an actual modal, just the one existing switch that forces the `Html::back()`
  branch) so submitting stays on the Records tab, regardless of the submitting user's own
  `glpibackcreated` preference.
- **Gap in scope, closed:** GLPI's own generic, blank "New Domain record" form — reachable from the
  global Domains-records list / top-nav "+", entirely independent of a Domain's own Records tab —
  reaches the exact same `onPreAdd()` live push as every other entry point, but Phase 37's original
  `injectDomainRecord()` returned early for any `isNewItem()` record, so this entry point had *no*
  warning at all. Since the domain isn't chosen yet at render time there (still a dropdown), the fix
  is necessarily a generic (not domain-specific) notice: "if the domain you select is write-back
  managed, this pushes live" — `templates/domainrecord_new_notice.html.twig`.

### 11.20 Addendum (2026-08-03): reconciler/write-back feedback loop, plugin-wide duplicate guard, trashed-record edits, locked-name display

A cluster of live bugs, all found via the same Dinahosting testing session that produced §3.8.2, but in the driver-agnostic write-back layer rather than the driver itself:

- **`RecordReconciler`'s own local mutations had no guard against re-triggering `DnsRecordWriteback`'s push-to-provider hooks**, only `Session::isCron()` — which a manually-triggered "Update now" sync (`SyncController`) never is. Every reconciler-driven `add()`/`update()`/`restore()`/`delete()` mirroring a record it had just read *from* the provider fired the matching `onPreAdd()`/`onPreUpdate()`/`onPreRestore()`/`onPreDelete()` hook, which then tried to push that same record straight back *to* the provider it came from — using its already-absolute stored name as if it were a raw user-typed label, doubling the zone (`manel.example.com` → `manel.example.com.example.com`). Fixed with a synthetic `_domainmanager_sync` input flag (same convention as the existing `_domainmanager_proxied`), set on all four of `RecordReconciler`'s native mutation calls; each `DnsRecordWriteback` hook now bails out immediately when it sees that flag — a reconciler-driven change is never a genuine user-initiated write with anything left to push upstream.
- **Plugin-wide, driver-independent duplicate-record guard added** (explicit user request, following the Dinahosting retry-storm bug above leaving real duplicate records both upstream and in GLPI): `DnsRecordWriteback::duplicateNameError()` refuses a second non-trashed `DomainRecord` sharing `domains_id`+`domainrecordtypes_id`+`name` on any Domain Manager-tracked domain, regardless of which (if any) driver is configured — deliberately not scoped to Dinahosting's own driver-specific `assertSingleRecordAtName()` (§3.8.1), which exists only because that one API can't target a single record among same-name siblings. Called from `onPreAdd()`, `onPreUpdate()` (against the update's *effective* domain/type/name, since a plain data/ttl update touches neither), and `onPreRestore()`, checked *before* each method's `_domainmanager_sync` bail-out so it also catches a reconciler-driven sync attempting to mirror a genuine upstream duplicate locally. Verified live twice: once against a genuinely active duplicate (blocked, with a session message), once against a name whose only existing match was already trashed (correctly *not* blocked — the guard only compares against non-trashed rows). `onPreAdd()`'s check needed its own follow-up fix: it initially compared the raw, still-unqualified label a user types (`"manel"`) against stored `DomainRecord.name` values, which are always absolute FQDNs — the two never matched, so a genuine duplicate create sailed past this guard and was only caught deeper in, by Dinahosting's own less-clear driver-specific message. Fixed by qualifying the raw name against the domain's own zone name (the same transformation the actual push already applies) before comparing.
- **`onPreUpdate()` never checked whether the record was trashed before trying to push the edit upstream.** Trashing a write-back-managed record already pushes a real `deleteRecord()` (§11.11) — its remote copy is gone. Saving a data/ttl edit on it while still trashed tried to push an update for a record that no longer exists at the provider. Per explicit user direction ("only 'synced' action should be restore"), added an early `is_deleted` check that returns `false` (local-only edit, no push attempted) for a trashed record — the only write-back action a trashed record can still trigger is `onPreRestore()`.
- **A managed record's locked `name` field displayed the raw stored value** (always an absolute FQDN, e.g. `www.example.com`) **instead of the relative label** GLPI core's own `DomainRecord::getDisplayName($domain, $name)` already computes for the "link a record" dropdown — that helper strips the domain's own canonical name back out of an absolute name, confirming the absolute-storage convention itself is correct and GLPI-native, not a bug to "fix" by changing storage. `DomainForm::injectDomainRecord()` now computes the display form via that same core helper and passes it to the template; the locked `name` input's displayed value is set to it client-side (cosmetic only — the field is locked either way per §11.19, so this can never change what could be posted back).
- **Per explicit user request, the "This updates the record live at the DNS provider. Continue?" confirmation on a plain Save was removed** — only delete/purge still prompts (plus GLPI's own native confirmation on restore, untouched).

---

## 12. Phase 41 — Cloudflare DNS record write support

### 12.1 Scope and positioning

**Extension, not replacement.** IONOS write-back (Phases 31–37, §11) is unchanged in every
respect: unchanged code, unchanged rights model, unchanged UI. This phase adds Cloudflare as a
second implementer of `DnsRecordWriterInterface`, which is the actual test of whether that
interface's design generalizes — it was written for one driver.

**In scope:** the same four record types (A, AAAA, CNAME, TXT), the same read-write direction
(upstream authoritative, local reconciler as backstop), the same soft-delete + upstream-first
delete ordering, the same shared per-type CREATE/UPDATE/DELETE rights (§11.6 — not
driver-specific). Cloudflare's zone-scoped API tokens introduce one genuinely new question, a
per-domain write-editability *state*, covered in §12.3.

**Out of scope:** Dinahosting write support, bulk operations, domain registration/lifecycle
writes — unchanged from §11's own scope statement.

### 12.2 Verified Cloudflare API schemas, and what could not be verified

Endpoints and payload shapes for DNS record mutations were checked against Cloudflare's own
published API v4 developer documentation for `/zones/{zone_id}/dns_records`.

**Verified:**

| Operation | Endpoint | Verb | Response |
|---|---|---|---|
| Create | `/zones/{zone_id}/dns_records` | `POST` | `201` + full record (`id`, `created_on`, `modified_on`, `proxiable`, `proxied`, `meta`) |
| Retrieve (single) | `/zones/{zone_id}/dns_records/{record_id}` | `GET` | `200` + full record, same shape as create |
| Update | `/zones/{zone_id}/dns_records/{record_id}` | `PUT` | `200` + full record |
| Delete | `/zones/{zone_id}/dns_records/{record_id}` | `DELETE` | `200` + `{result: {id: "..."}}` |

**Explicitly unverified — not filled in by analogy with IONOS, and not to be treated as fact until
a live test against a real zone confirms them (§12.8):**

1. **Whether `PUT` is a full replace or preserves unspecified fields.** Cloudflare's docs describe
   `PUT` as "overwrite," which reads as full-replace, but don't state whether omitting `name`/`type`
   on an update is rejected, ignored, or accepted as "leave unchanged." IONOS's own `PUT` (§11.9,
   as shipped — see §12.5 below) turned out to be a *narrow* schema (`content`/`ttl`/`prio`/
   `disabled` only), not the full-replace §11.9 originally assumed. The same mistake is possible
   here in the other direction. **Design stance for Phase 41: send the full known field set
   (`name`, `type`, `content`, `ttl`, `proxied`) on every update rather than assume partial-preserve
   — safer against an unverified full-replace than the reverse, and cheap to narrow later if a live
   test shows fields are rejected.**
2. **`proxied`'s default value on create/update**, and **whether the dashboard's own default
   differs from the API's.** Neither is stated in the docs read. **Design stance: never omit
   `proxied` from a write — always send it explicitly** (`false` unless the record read back on
   sync already reported `true`), so no default, whatever it turns out to be, is ever silently
   relied on.
3. **The relationship between `proxiable` (can this record type be proxied) and `proxied` (is it
   proxied now).** Unverified whether setting `proxied: true` on a non-`proxiable` record (e.g. an
   MX-adjacent type, though MX isn't in the writable set) is rejected or silently coerced to
   `false`. Not expected to matter for A/AAAA/CNAME/TXT specifically, but not confirmed.
4. **TXT content quoting and CNAME trailing-dot conventions on Cloudflare's own wire format** — not
   stated in the docs read, and cannot be settled without reading a real record back from a real
   Cloudflare zone. Left unverified rather than assumed identical to IONOS's convention.

### 12.3 Per-domain write-editability state (settled design — supersedes the draft's "compute live,
never store" proposal)

Cloudflare API tokens can be scoped to specific zones. A 403 while writing to one zone is evidence
about *that zone*, not about the account-wide capability of the token — unlike IONOS, where an API
key is account-wide and a write failure is a fact about the whole credential. This is the one place
Cloudflare's design genuinely differs from IONOS's, and it is tracked as explicit per-domain state,
not inferred live on every write:

- Two new columns on `glpi_plugin_domainmanager_states`: **`dns_write_status`** (enum: `manual` /
  `managed_readonly` / `managed_editable`) and **`dns_write_message`** (nullable string — the
  specific reason when not editable). No new table.
- **Learned from real writes, never probed.** No `dns_write` Check Connection capability, no
  token-policy introspection anywhere. A domain starts `managed_readonly` the moment DNS sync
  recognizes a write-capable driver as authoritative for it; it only becomes `managed_editable`
  after a write actually succeeds there.
- **`dns_write_status` is independent of `dns_status`** (the read-sync status) — a successful
  *read* never changes it. Only a write attempt does.
- **Reset triggers:** credentials edited on the supplier, the detected DNS provider changing, or
  any successful write (which sets `managed_editable`). A successful read is explicitly *not* a
  reset trigger. No user-facing retry control.
- **Only a genuine permission failure flips it to `managed_readonly`.** For Cloudflare that's a
  `403` on a zone-scoped write. Transient network/5xx errors, a `404` on delete (idempotent
  success — the desired end state already holds), and Cloudflare's create-conflict code
  (`81057` — record already exists) do **not** change stored state; each is surfaced as a one-off
  warning only, exactly as §11.10 already does for IONOS's own transient-failure handling.
- **Failure messages always name the missing permission** — e.g. "this API token lacks `DNS:Edit`
  permission for this zone" — never a bare "403"/"forbidden," consistent with the driver-agnostic
  `driverLabel()` messaging already shipped in `DnsRecordWriteback` (§12.5, item 4 below).
- **UI terminology (settled, not to be re-opened):** *Manual* / *Managed — read-only* /
  *Managed — editable*; badge text "Editable from GLPI" only in the `managed_editable` case.
  "Full control" was considered and rejected as badge copy (reads as broader than what the plugin
  actually verifies).

This model is driver-agnostic by construction: any current or future driver can set
`dns_write_status` the same way (`managed_readonly` until its first successful write), whether or
not that driver's tokens are ever zone-scoped. IONOS simply never has a reason to leave
`managed_readonly` once configured correctly, since its credential is account-wide — the state
machine doesn't need to know that difference.

### 12.4 Implementation: `CloudflareDriver` extending to `DnsRecordWriterInterface`

**`CloudflareDriver` currently implements:** `RegistrarDriverInterface`, `DnsPipelineInterface`
(read-only), `ConnectionTestableInterface`, `DomainDiscoveryInterface`.

**Phase 41 adds:** `DnsRecordWriterInterface`.

**Zone resolution:** the existing `findZoneId()` helper (already used by `fetchZoneRecords()`,
scoped by the stored `account_id`) is reused unchanged for all three write methods below — no new
zone-lookup logic.

1. **`createRecord()`** — resolve zone; build the absolute name using the same
   `"$name.$zoneName"` convention `DnsRecordWriterInterface::createRecord()`'s own docblock
   specifies (the one §11.15 addendum found and fixed for IONOS's call site, not the interface
   itself — see §12.5 item 3); `POST /zones/{zoneId}/dns_records` with
   `{name, type, content: data, ttl, proxied}` (proxied always explicit, §12.2 item 2); extract
   `id` from the response into `ZoneRecord.remoteId`, no follow-up read (§11.9's convention, kept).
2. **`updateRecord()`** — resolve zone; `PUT /zones/{zoneId}/dns_records/{remoteId}` with the full
   field set (§12.2 item 1's design stance); on `403`, throw `DriverException` with the
   permission-specific message (§12.3); return the response body as a `ZoneRecord`.
3. **`deleteRecord()`** — resolve zone; `DELETE /zones/{zoneId}/dns_records/{remoteId}`; a `404`
   (already gone) is treated as success (idempotent), not an error; a `403` throws with the
   permission-specific message.

**Error mapping stays inside the driver.** Cloudflare returns errors as
`{errors: [{code, message}]}`; `CloudflareDriver` classifies its own codes into
permission/transient/idempotent buckets and only ever hands `DnsRecordWriteback` an
already-safe-to-persist `DriverException` message — the shared layer never inspects a status code
or error body itself (§12.6).

### 12.5 Divergence report: IONOS as shipped vs. as designed in §11

Checked directly against the current code (`DnsRecordWriteback.php`, `IonosDriver.php`,
`Profile.php`, `DriverRegistry.php`) and against §11's text and its own addenda.

**Confirmed matching, no divergence:**
- Write list is exactly A, AAAA, CNAME, TXT (`DnsRecordWriterInterface::WRITABLE_TYPES`).
- Interface shape is `createRecord`/`updateRecord`/`deleteRecord`/`fetchRecord`, unchanged since
  §11.8.
- One right per type carrying CREATE/UPDATE/DELETE bits, not three separate rights
  (`Profile::getDnsRecordRights()`).
- Delete is a local soft-delete with upstream-first ordering
  (`DnsRecordWriteback::onPreDelete()`); no rollback anywhere (§11.14, unchanged).
- `remote_id` is captured from the create response directly, no follow-up read
  (`DnsRecordWriteback::onPostAdd()`).
- Historical logging via `'[Domain Manager] ' . ...` prefixed lines, `id_search_option = 0`,
  unchanged from §11's original convention.

**Real divergences, both already resolved in the shipped code, neither silently:**

1. **`IonosDriver`'s `PUT` update already sends the narrow `{content, ttl, prio, disabled}`
   schema** (confirmed at `src/Driver/IonosDriver.php:488` and `:522`, `'disabled' => false` sent
   unconditionally) — not the full-record-replace §11.9 originally described. The shipped code is
   correct; §11.9's prose was the stale side of this disagreement and should be read as corrected
   by the implementation, not the other way around.
2. **The absolute-name construction bug** (§11's own "Live-testing addendum, found 2026-07-29":
   `DnsRecordWriteback::onPreAdd()` originally built `"$zoneName.$name"`, backwards from the
   interface's own documented `"$name.$zoneName"` convention) was a real bug in the *call site*,
   not the interface or IonosDriver — already fixed, and directly relevant to Cloudflare's
   `createRecord()` above, which must use the corrected convention from day one.
3. **The hardcoded-`DRIVER_IONOS` coupling in `recheckNameservers()`** — §11.10 described "a live
   NS re-check runs immediately before every push" but the shipped comparison was pinned to the
   literal `DRIVER_IONOS` constant rather than the domain's actual configured driver, which would
   have silently rejected every Cloudflare write once shipped. Fixed on this branch
   (commit "Generalize DnsRecordWriteback beyond IONOS ahead of Cloudflare write support",
   2026-07-30) to compare against the domain's own configured driver instead. The re-check
   *mechanism* (`NsResolver`/`NsProviderRegistry`) is unchanged; only the hardcoded constant was
   removed.
4. **Every "at IONOS" user-facing failure message is now driver-name-generic**, via
   `DriverRegistry::getDriverLabels()[$driver]` (`DnsRecordWriteback.php:159` and siblings) — in
   the same commit as item 3. A Cloudflare write failure will read "at Cloudflare," not "at the
   configured provider" or a stale "at IONOS."

**Conclusion:** IONOS shipped matching §11's design in every rights/interface/lifecycle respect;
the two real gaps found (items 3–4) were provider-coupling bugs in the *shared* orchestration
layer, not in IONOS's own driver — which is exactly the failure mode Phase 41 needs to avoid
repeating for Cloudflare, and the reason §12.3/§12.6 are written the way they are.

### 12.6 Maintainability: keeping provider-specific concerns out of the shared layers

**The `proxied`/`is_proxied` field is not a new coupling risk.** `ImportedRecord.is_proxied`
already exists as a driver-agnostic, nullable column — a driver sets it if the concept applies to
it, leaves it null otherwise. No Cloudflare-specific schema needed here.

**Per-domain write-editability state (§12.3) is deliberately generic, not Cloudflare-specific.**
`dns_write_status`/`dns_write_message` describe *any* driver's write capability for *any* domain;
Cloudflare is simply the first driver where the `managed_readonly` state can actually persist
past initial configuration (because of zone-scoped tokens), rather than resolving to
`managed_editable` on the very first successful write. Nothing about the columns themselves
mentions Cloudflare.

**The real risk — repeating the exact mistake found in §12.5 items 3–4 — is error-message and
error-code handling leaking into the shared layer.** Concretely: a shared table mapping
"HTTP 403 → this exact message," maintained inside `DnsRecordWriteback` or `DomainState` and keyed
by status code alone, would immediately be wrong for the next provider whose 403 means something
narrower or broader than Cloudflare's zone-scoped one. **Prevention, already the pattern in
place:** error classification and message construction happen entirely inside each driver's own
write methods (§12.4); the shared layer (`DnsRecordWriteback`) only ever receives an
already-formatted, already-safe `DriverException` message and passes it through unchanged
(exactly as `$e->getMessage()` is used today). No shared code needs to know what a 403 means to
any particular provider.

### 12.7 Failure modes and recovery

Unchanged pattern from §11.14, extended with Cloudflare's specific codes:

- **Transient** (network, 5xx, timeout): user gets a warning; operation aborts locally; no stored
  state changes; retry is safe.
- **Fatal/permission** (`403`): user sees the permission-specific message (§12.3); `dns_write_status`
  flips to `managed_readonly` with `dns_write_message` set to that reason.
- **Fatal/validation** (malformed record, e.g. invalid CNAME target): user sees the message; no
  state change; user edits the form and retries.
- **Idempotent-already-gone** (`404` on delete, `81057` create-conflict): treated as success, not
  an error, and not a permission signal.
- **Rollback:** none, ever (§11.14 stands). The reconciler remains the sole convergence mechanism.

### 12.8 Verifications required before this phase's code is written

Against a live Cloudflare account and zone, before implementation (not before this document is
approved — the document's job is to state the design and mark what's still open):

1. Whether `PUT` accepts/requires/ignores `name`/`type` on update — one call against a real zone.
2. The exact error shape and code for a zone-scoped token lacking `DNS:Edit` on a zone — drives
   the exact permission-message text in §12.3/§12.4.
3. `proxied`'s actual default on create/update, and whether it matches the dashboard's own default.
4. Whether Cloudflare returns TXT content quoted or unquoted, and CNAME with or without a trailing
   dot, on read.
5. A live round-trip of all four writable types (create → read back → compare against what a
   subsequent sync-read would produce), to catch any wire-format surprise not covered above.

None of these block approving this document — they're implementation-time verifications, not
design questions. If any surfaces a design-relevant surprise (e.g. `PUT` truly rejects an omitted
`name`), that becomes a documented, non-silent correction here, the same way §12.5 items 1–2
corrected §11.9's original IONOS assumptions.

### 12.10 Phase 42 implementation status

Implemented as designed above (`1.3.0-alpha3`, 2026-07-30; Cloudflare write support tracks as its
own `1.3.0` line starting at Phase 40, rather than continuing the `1.2.0` series IONOS write-back
shipped under): `CloudflareDriver` now implements
`DnsRecordWriterInterface` (§12.4), the `dns_write_status`/`dns_write_message` columns and their
reset/recording logic (§12.3) are live in `SyncEngine::sync()` and
`DomainState::recordWriteOutcome()`, and `DriverException::$isPermissionDenied` carries the
403-vs-everything-else classification out of the shared layer per §12.6. Not yet done: the five
live-account verifications listed in §12.8 — the code took the documented safe design stance on
each (full field set on `PUT`, always-explicit `proxied`, TXT/CNAME passed through unchanged) but
none of the five has actually been exercised against a real Cloudflare zone yet. Until that
verification happens, treat those design stances as the current best guess, not confirmed fact,
exactly as §12.8 anticipated.

### 12.9 No rights changes, no new UI surface

The four per-type DNS write-back rights (§11.6) apply to every driver, Cloudflare included — no
Cloudflare-specific right, no per-provider right. No Cloudflare-specific UI beyond what the
editability-state badge (§12.3) already renders generically. The one user-visible change is that
failure messages and the "Editable from GLPI" badge now correctly name whichever driver is
actually configured, which is exactly what the 2026-07-30 generalization (§12.5 item 4) was for.

## §13 Phase 44 — Update-conflict reconciliation (removed 2026-07-31)

Implemented 2026-07-30 as a live re-fetch-and-diff before pushing an edit to an already-managed,
write-back record: if the provider's live value had drifted from GLPI's last-known copy,
`DnsRecordWriteback::onPreUpdate()` refused the edit and created a `RecordConflict` row directing
the user to a resolution screen (`/plugins/domainmanager/recordconflict/{id}`) to pick "keep GLPI"
or "keep provider".

**Removed 2026-07-31, per design clarification:** once a record is under Domain Manager
management/write-back, GLPI's value is always authoritative — editing it in GLPI and pushing to
the provider is the normal, intended workflow, not a conflict. A genuine conflict (ambiguous source
of truth) can only exist for native records that predate management, before a supplier/driver was
ever configured — never for a record GLPI is actively managing, matching how every other
write-back field (name, proxy toggle, comment) already behaves with no conflict step. The live
re-fetch-and-diff/abort/resolution-screen mechanism, the `RecordConflict` model, its controller and
template, and the `glpi_plugin_domainmanager_recordconflicts` table were all removed;
`onPreUpdate()` now pushes the submitted `data`/`ttl` straight to the provider unconditionally.

## §14 Phases 46–48 — manual/import reconciliation, managed-flag import gate, trash/restore duplicate bug (design, 2026-07-30)

Three issues raised together on 2026-07-30, from a real-world observation: most GLPI instances
already have Domains entered manually, long before this plugin's supplier import exists, and the
current import path has no notion of "this Domain already exists and is intentionally unmanaged."

### 14.1 Phase 46 — surface unlinked/manual-domain matches during import instead of skipping them

Today `DomainDiscoveryMatcher` matches purely by normalized name (Punycode/lowercased,
`normalize()`) and `DomainImportController` either creates a new Domain or restores one from
trash — there is no third outcome for "a Domain with this name already exists, has no Infocom
supplier link, and `is_managed=0`" (i.e. plausibly hand-entered, never touched by this plugin).
Today that case is invisible: the importer can't tell "genuinely new" apart from "exists but
manual" from name matching alone, so it either silently creates a duplicate-by-name Domain or
silently claims the existing one, depending on match logic elsewhere.

**Closed as already solved (2026-07-30):** re-investigated before implementing and found this
exact case already handled, by the older Phase 8 "Import Domains" discovery modal
(`DomainDiscoveryController` + `domain_discovery_modal.html.twig`, `DomainDiscoveryMatcher::match()`)
— not by `DomainImportController` alone, which is only the bulk-create half of that same flow.
`DomainDiscoveryMatcher::match()` already matches every discovered registrar-account domain
against every existing GLPI `Domain` by name, globally, independent of Infocom, and already
distinguishes "exists, no supplier link" (`existing_suppliers_id === 0`, renders "Set registrar to
X") from "exists, linked to a *different* supplier" ("Reassign registrar to X") — the exact two
cases this phase set out to add. `DomainRegistrarReassignController`'s one-click action attaches/
updates the Infocom supplier, and `HookHandler::infocomSaved()` (already wired) fixes the state
row/`is_managed` from that alone. No code change made.

The one real gap identified, deliberately left open rather than fixed here (confirmed
out-of-scope with you 2026-07-30): this reconciliation only runs for suppliers whose driver
implements discovery (`DriverFactory::forDiscovery()`) — a driver that can't list account domains
gets no modal at all, so a manual domain under that supplier is never offered this treatment. A
future phase could add a name-only fallback reconciliation path for that case if it turns out to
matter in practice.

### 14.2 Phase 47 — enforce `is_managed` as an import gate, not just a search filter

Confirmed 2026-07-30: boolean is the right shape — `is_managed` already exists at both Domain
(`glpi_plugin_domainmanager_states.is_managed`) and DomainRecord
(`glpi_plugin_domainmanager_records.is_managed`) level, already indexed and exposed as real search
options (`PLUGIN_DOMAINMANAGER_SO_DOMAIN_MANAGED` / `_DOMAINRECORD_MANAGED`). What's missing is
using it as a write gate: nothing today stops an import/sync from overwriting a Domain or
DomainRecord that a *different* driver/source already marked `is_managed=1`. Design: before an
import or sync write touches a matched Domain/DomainRecord, check `is_managed` plus the recorded
source (registrar/DNS driver already resolved via `DomainState`); if it's `1` under a different
source than the one currently writing, block the write and raise the same conflict-flagging path
Phase 44's `RecordConflict` (§13) already established, rather than adding a second conflict
mechanism.

**Implemented (2026-07-30), first slice:** a Domain-level "Native" field, the direct counterpart
to `DomainRecord`'s existing `is_glpi_created` — `glpi_plugin_domainmanager_states.is_glpi_created`
(new column, `Installer::addDomainGlpiCreatedColumn()`, defaults `1`/Native for every pre-existing
row, since a state row alone can't retroactively tell manual creation apart from a pre-Phase-47
import). `SyncEngine::sync()` gained an `$isImport` parameter, set only by
`DomainImportController` (its bulk-import path is the one caller that actually knows a Domain was
supplier-discovered, not hand-entered) and only consulted when the state row is created for the
first time — every other caller (manual creation's first sync, cron, `SyncController`,
`MassiveActionHandler`) leaves it `false`, so a newly-created state row defaults to Native.
Exposed as `PLUGIN_DOMAINMANAGER_SO_DOMAIN_GLPI_CREATED` (id `9431`), same "Native" label and
`bool` datatype as the DomainRecord option.

**Write gate, implemented (2026-07-30):** `SyncEngine::sync()` now compares the DNS leg's
newly-resolved supplier against the domain's *previous* `DomainState.dns_suppliers_id` before
ever calling `RecordReconciler::reconcile()`. If the domain was already `is_managed` under a
different, non-zero supplier, the DNS leg is skipped entirely for this run — no upstream fetch,
no trashing/recreating of the previous supplier's owned `DomainRecord`s — and `dns_status` is set
to the new `DomainState::STATUS_SOURCE_CONFLICT`, with a message naming both supplier ids. The new
supplier id is still persisted on the state row (unconditionally, same as before this change), so
a deliberate second sync run sees no mismatch and proceeds normally — the same "re-sync to
confirm" pattern `STATUS_REASSIGNED` already established for a Registrar change. This transitively
covers the `DomainRecord`-level case too: `RecordReconciler` only ever runs under whichever
supplier this check already cleared, so no separate per-record source-tracking column was needed.
Registrar-import-time Domain conflicts need no equivalent gate: `DomainImportController` already
never touches an existing Domain's Infocom/supplier assignment at all (a name match is unconditionally
skipped, §14.1's own open gap being the *lack* of surfacing that skip, not an unguarded write).

### 14.3 Phase 48 — bug: trashing then restoring a synced DNS record produces a duplicate, not a restore

Root cause (verified against `RecordReconciler::doReconcile()`, ~line 170–177): GLPI's default
`getFromDB()` excludes trashed (`is_deleted=1`) rows. When a synced `DomainRecord` is manually
trashed, the next reconciliation pass reads that as "the owned record vanished," deletes its
`ImportedRecord` ownership row, and creates a **new** `DomainRecord` with identical content
(`createRecord()`). Restoring the original trashed row afterward (via GLPI's native trash UI)
succeeds at the GLPI level, but it's now an orphaned duplicate sitting next to the reconciler's
new record — appearing to the user as "restore did nothing," when actually a duplicate was
silently created before the restore ever happened.

**Fix (preferred):** in `RecordReconciler::doReconcile()`, look up a trashed match by ownership
row *before* concluding a record vanished, and restore-and-reuse it (mirroring the pattern
`DomainImportController` already uses for trashed Domains) instead of deleting ownership and
recreating. **Safety net:** register an `item_restore` hook (none exists today — `setup.php`
741–804 only has `PRE_ITEM_DELETE`/`ITEM_PURGE`/`PRE_ITEM_PURGE`) to detect and clean up any
duplicate created by this race for records already affected before the fix ships.

---

*Open items awaiting your approval: the four deviations in §0.1–§0.4 (Registrar as plugin field, `date_domaincreation` mapping, plugin-owned lock layer replacing native `Lockedfield`, documented `managed_domainrecordtypes` gate on web-triggered record writes), the CREATE TABLE exception in §0.6, and §11 (Phases 31–35 — Manual DNS record write-back to IONOS). The two items that were blocking Phase 32 — the rights-matrix rendering mechanism (§11.6/§11.16) and the §10 changelog-policy amendment for pre-release versions (§11.14) — are both resolved as of 2026-07-29; Phase 32 is unblocked. **§12 (Phase 41 — Cloudflare write support) is a design-only addition pending your approval; §12.8 lists five implementation-time API verifications that are not blocking approval of the design itself. §13 (Phase 44 — update-conflict reconciliation) was implemented 2026-07-30 and removed 2026-07-31 — a managed record's GLPI value is always authoritative, so there was no genuine conflict to reconcile. §14 (Phases 46–48) is fully resolved as of 2026-07-30: Phase 46 closed as already solved (§14.1, no code change), Phase 47's Domain-level "Native" field and DNS source-conflict write gate are implemented (§14.2), and Phase 48's trash/restore bug is fixed (§14.3). This closes out the 1.3.0 line at `1.3.0-beta1`.***

---

# §15 Phases 49–68 — one `1.5.0` release: containment, write safety rails, validation engine, full record-type coverage, NS registry (plan, 2026-08-03)

Design-only. No code is written until this section is approved (Phase 0 discipline, §9).

Five requirement groups were raised together on 2026-08-03. Investigating them before planning
changed three of them materially, and those corrections are recorded here rather than in the phase
bodies so they are not rediscovered later:

- **The "4 items maximum" cap is not a regression.** Core's `DomainRecord::showForDomain()`
  (verified on `11.0/bugfixes`) issues its `$DB->request()` with no `LIMIT` and passes
  `count($entries)` as both `total_number` and `filtered_number` — it cannot cap rows. What was
  actually observed is the plugin's own six-type read whitelist (§5: "unknown record types skipped
  (read-only scope: A, AAAA, NS, TXT, MX, CNAME)"), a deliberate design decision. `SOA`, `SRV`,
  `CAA`, `PTR` and `ALIAS` have never been imported. Nothing broke.
- **`NS` is a separate question.** `NS` *is* in the read whitelist, so if it is genuinely absent the
  cause is downstream of the whitelist — most likely provider-side, since Cloudflare does not expose
  a zone's own apex `NS` records through its DNS records API at all. Phase 50 diagnoses this and is
  permitted to conclude "provider limitation, documented" with no code.
- **Per-profile audit attribution is not achievable natively.** `glpi_logs` (verified against
  `install/mysql/glpi-empty.sql`) has `itemtype`, `items_id`, `itemtype_link`, `linked_action`,
  `user_name`, `date_mod`, `id_search_option`, `old_value`, `new_value`, `old_id`, `new_id`. There is
  no `profiles_id` and no `entities_id`, and `user_name` is a formatted display string from
  `User::getNameForLog()`, not a `users_id` FK — so it cannot even be joined back to `glpi_users`
  reliably. **Rejected**, see §15.6.

---

## 15.1 Version line

**Everything in §15 ships as a single `1.5.0` release.** The five-line split an earlier draft of this
section proposed is superseded.

| Group | Theme | Phases |
|---|---|---|
| A | Containment of the write-duplication class | 49–52, 51b |
| B | Write safety rails, History gaps | 53–57, 57b |
| C | DNS record validation engine | 58–62 |
| D | Full record-type coverage | 63–66 |
| E | NS provider registry (Hostalia, Ascio), takeover detection | 67–68 |

Groups are ordering and review units, not versions. Work proceeds as `1.5.0-alpha1`, `-alpha2`, …
then `-betaN`, each bump landing under the running `## [Unreleased]` section per §10's pre-release
rule, with the accumulated content promoted into one dated `## [1.5.0]` header at release. That is a
long pre-release run — expect the `CHANGELOG.md` derivation at cut time to need real trimming, since
§10 requires one line per item and this is roughly twenty phases of items.

**Group A ordering is load-bearing.** Phases 49–52 address a defect class that has already created
duplicate records at a provider, so they run first inside `1.5.0` rather than being sequenced by
convenience.

**The sequencing question this section previously left open is now settled by the changelog.** `1.4.2`
was cut as a real release on 2026-08-03, carrying the Dinahosting normalization fixes, the
`remote_id` repair migration, the duplicate guard and the `hasPurgeRight()` fix; `[Unreleased]` now
names `1.5.0-beta1`. So the shipped-versus-bundled tension is resolved in the safer direction on its
own: the containment work already in flight reached an instance, and only Phases 49–52 — which are
refinements of it, not the incident fix itself — ride the full `1.5.0`. No `1.4.3` is needed and no
retroactive header is at issue.

One observation on the `1.4.2` entry rather than on the plan: it carries four buckets —
`### Features`, `### Bugs`, `### Bugs (continued)` and `### Added`. §10 mandates exactly two,
`### Features` and `### Bugs`, explicitly not Keep a Changelog's Added/Changed/Removed/Fixed split.
`### Added` should fold into `### Features` and `### Bugs (continued)` into `### Bugs` before this
becomes the pattern the `1.5.0` section inherits.

**Schema-bearing phases:** 54 (`supplierconfigs` circuit-breaker columns) and, conditionally, 63 (MX
renormalization migration). Each must bump `PLUGIN_DOMAINMANAGER_VERSION` in the same commit as its
migration, per §3.7.2's lesson — the pre-release bump sequence gives each one its own gate, and
`Installer::install()`'s chain stays idempotent throughout.

One GitHub Issue per phase, branched from `develop`, per the standing workflow.

**Status update, 2026-08-03 (post-drafting):** Phases 49 and 50 have already been verified/implemented
on this branch (commits `11f381c` "Verify Phase 49 per-type purge right live, check off TESTING.md"
and `73ee509` "Phase 50: doc-verify SRV/SOA/CAA read handling for all three drivers"), on top of the
`1.4.2` release commit. The bodies of §15.2's Phase 49 and Phase 50 below are retained as the design
record of what was verified and why; no further code is pending for either unless a future finding
reopens them.

---

## 15.2 Phases 49–52 and 51b — Group A, containment

These come first because they address a class of defect that has already reached production DNS,
not because they are the smallest.

### Phase 49 — Idempotent pre-create, and a visible outcome when the duplicate guard fires on a sync

**Revised 2026-08-03 against §3.8.2 and §11.20, which confirm the sequence and change what is
missing.** The earlier draft of this phase assumed the duplicate reached GLPI through the add path.
It did not. Confirmed sequence:

1. The upstream create **succeeded**. `findByIdentity()`'s post-create confirmation failed (the
   un-normalized-hostname bug), so the driver reported failure.
2. The user retried, producing a second **real record at the provider** (§3.8.2: "the resulting user
   retries created real duplicate records upstream").
3. The duplicates entered GLPI on the **next sync**, mirrored in by `RecordReconciler` as two local
   `DomainRecord` rows (§3.8.2: "silently mirrored into GLPI as a second local `DomainRecord`" —
   observed live as two duplicate `manel` A records).

So `duplicateNameError()`, checked before each hook's `_domainmanager_sync` bail-out precisely so it
catches a reconciler mirroring an upstream duplicate (§11.20), does now stop step 3. **Neither the
guard nor the `qualifyHostname()` fix stops step 2** — at retry time no local row exists yet, so
there is nothing for the guard to compare against, and a second upstream record is still created.

**And stopping step 3 introduces a worse failure mode than the one it fixes.** Once a genuine
upstream duplicate exists — however it arose, including by an admin editing the zone directly at the
provider — the reconciler can never represent it. `add()` returns `false` on every sync, forever, and
the only signal is a session message, which during a cron run no one is present to read. The result
is a domain reporting a clean sync while silently omitting a live record. That is precisely the
class of divergence §5.4's trash-bin design and §5.6's fetch-succeeded audit line exist to make
impossible, reintroduced through a different door.

**Two-part fix.**

- **Upstream (step 2): idempotent pre-create.** Before any create, look the record up upstream by
  identity; if an identical record already exists, **adopt** it — write the local `DomainRecord` and
  `ImportedRecord` ownership rows against the existing `remoteId` — rather than issuing a create.
  This is the `findByIdentity()` call the drivers already make, moved from after the create to before
  it, which also makes the confirmation lookup redundant on the happy path. Driver-agnostic: lives in
  `DnsRecordWriteback`, keyed off `DnsRecordWriterInterface`, no driver named. Dinahosting's synthetic
  `type|name` `remoteId` is unaffected, since the lookup is by identity rather than by id.
- **Local (step 3): make the guard's refusal visible and persistent.** When `duplicateNameError()`
  fires on a reconciler-driven path, it must set a distinct `DomainState` status naming the
  unrepresented record and log to `domainmanager-errors.log` via `SyncLogger` — not queue a session
  message. A refusal that only surfaces in a message nobody reads is indistinguishable from a
  successful sync, and this is the one case where GLPI knowingly holds a different view of the zone
  than the provider does.

**Verifications required before code:**
1. Confirm `findByIdentity()` is safe to call pre-create on all three write-capable drivers when the
   record does not exist — returns null rather than throwing.
2. Confirm whether `onPreAdd()` aborts the local add or commits it unowned when the driver reports a
   failed create. §3.8.2 establishes the misreport but not this detail, and it determines whether the
   adopt path also needs to repair rows left behind by the old behaviour.
3. Confirm the guard's reconciler-path refusal currently produces no persisted state — §11.20 records
   a session message and an `add() === false`, and nothing else.

### Phase 50 — Diagnose the absent `NS` records

Determine whether `NS` records are missing because of the plugin or because the provider does not
return them. Deliverable is a finding, not necessarily code.

**Verifications required before code:**
1. For each of `CloudflareDriver`, `IonosDriver`, `DinahostingDriver`: does the zone-records fetch
   return the zone's own apex `NS` records? Cloudflare is expected not to (it owns them).
2. Whether the affected domain in the live report uses a supplier whose driver does return them.
3. Whether `RecordReconciler` filters `NS` anywhere beyond the whitelist.

If the cause is provider behaviour, the outcome is a documented limitation in §4 plus a `TESTING.md`
note — and, if this proves confusing in practice, a UI line stating that apex `NS` is provider-managed.
No fabricated `NS` rows.

### Phase 51 — Credential-leak audit and a `PluginLogger` scrubber

`PluginLogger::activity()`/`error()` are the single funnel for both log files. Add a scrubber there
— a key allow-list for structured context plus a bearer/token regex for free text — so no future
call site can leak a secret by accident. Defense in depth: §3.7.2 already establishes that
`SupplierConfig` logs field labels and never values, and this does not replace that discipline.

**Verifications required before code:** grep every `PluginLogger` call site and every
`DriverException` message construction for a path that can carry a decrypted credential, an
`Authorization` header, or a full request body. The audit is the phase; the scrubber is the residual
guarantee.

### Phase 51b — Per-command error mapping in `DinahostingDriver::request()`

§3.8.2 leaves this explicitly open: `request()`'s shared handler maps `2303`/`CODE_OBJECT_NOT_EXISTS`
to "Domain is not managed by this Dinahosting account" regardless of which command produced it. That
reading was only ever confirmed for domain-level commands (`Domain_Zone_GetAll`,
`Services_GetDomains`). For `Domain_Zone_DeleteType*` the same code means "this hostname/value
combination doesn't exist" — the raw text is literally `Param "hostname"/"ip" value doesn't exist`.

The `relativeHostname()` fix stopped the delete calls that were *triggering* the wrong message, so the
symptom is gone, but the mapping is still wrong and will resurface the moment a delete legitimately
targets an absent record. It also collides directly with the project's standing requirement that an
operator-facing message name the specific thing to fix: "domain not managed by this account" sends an
admin to check credentials and supplier assignment for what is actually a missing record.

Make the mapping command-aware. Note §3.8's open gap remains: there is no documented record-not-found
`responseCode`, so a delete against a genuinely absent record still cannot be told apart from other
`2303` failures with certainty — the honest outcome is a message that states both possibilities for
record-level commands rather than asserting the wrong one.

**Verifications required before code:** whether any command other than `Domain_Zone_DeleteType*`
returns `2303` with record-level rather than domain-level meaning; §3.8.2 confirms only the delete
family.

### Phase 52 — Audit of the per-type write-right helpers (rights resolution, not only entity scope)

**Widened 2026-08-03.** The original scope was entity-awareness alone. `1.4.2` then shipped a fix for
`DnsRecordWriteback::hasPurgeRight()` reading `$item->fields['type']` — not a real `DomainRecord`
field, where every other type lookup in the class reads `domainrecordtypes_id` — so it resolved to no
type and returned `false` **unconditionally**, hiding the Purge button even from a super-admin holding
every right. That bug lived in the same helpers this phase audits, and it was invisible for as long as
it existed because it failed *closed*. A symmetric bug that failed *open* would be a silent privilege
escalation and equally invisible. That asymmetry is the argument for auditing the whole family rather
than one property of it.

Scope: every field lookup, type resolution and right check in `DnsRecordWriteback::hasTypeRight()`,
`hasPurgeRight()`, `writableTypes()`, `creatableTypesForDomain()` and `writableSupplierName()`.

Entity-awareness remains part of it: §11.6's per-type rights are profile bits, and if they are checked
with a bare `Session::haveRight()` rather than an entity-aware `can()` against the target `Domain`,
then a profile granted `Domain Record: TXT` for one entity holds it over every entity's zones. §8
establishes entity-aware `can()` as the convention for every other action in this plugin.

**Verifications required before code:** read every call site of the five helpers above; confirm each
reads `domainrecordtypes_id` (never a `type` field), and whether the target domain's entity is
consulted. If both are already correct, the phase concludes with `TESTING.md` regression checks and no
code change — but per the `hasPurgeRight()` precedent, "looks right" is not sufficient here.

**Outcome, 2026-08-03 (TESTING.md Phase 52):** `writableTypes()`/`writableSupplierName()` had
nothing to fix (no field lookup and no right check respectively). `hasTypeRight()`,
`hasPurgeRight()` and `creatableTypesForDomain()` all route through the private `hasRight()`,
which had exactly the asymmetric bug this phase's docblock predicted: a bare
`Session::haveRight()` with no entity check at all, so a per-type right granted for one entity
held over every entity's zones — failing *open*, the mirror image of the `hasPurgeRight()`
field-name bug that failed *closed*. Fixed by threading `$domains_id` through `hasRight()` and
gating on `Session::haveAccessToEntity()` in addition to the existing profile-bit check, matching
the primitive `Domain::can()` uses internally elsewhere in the plugin (§8).

---

## 15.3 Phases 53–57b — Group B, write safety rails and History gaps

### Phase 53 — Global write kill switch (read-only mode)

One `config`-gated boolean (§6.6's `Config\Config` tab, `config` UPDATE right per §8) that hard-
disables every outbound mutation across every driver, independent of per-type rights. Enforced at a
single point in `DnsRecordWriteback` and asserted again in each driver's writer methods, so a future
call path cannot bypass it. Deactivating a supplier is not a substitute — that also kills reads.

Surfaced in the UI wherever a write control appears, with an actionable message naming the setting.

**Outcome, 2026-08-03 (TESTING.md Phase 53):** Implemented as `Config::isReadOnlyMode()`/
`setReadOnlyMode()`, stored as the existing `plugin:domainmanager` config context's `read_only_mode`
key (explicit `1`/`0` int, §0.10). Single choke point: `DnsRecordWriteback::readOnlyModeError()`,
checked in `onPreAdd()`/`onPreUpdate()`/`onPreDelete()`/`onPreRestore()` before the per-type right
check, so it is never second-guessed by a user's own rights, with `abort()`'s existing session-message
convention naming the setting and its location. Defense in depth: `Config::assertWritesAllowed()`
(throws `DriverException`) asserted again at the top of every driver's `createRecord()`/
`updateRecord()`/`deleteRecord()` (all three drivers) and `setProxied()`/`pushComment()`
(Cloudflare only, the sole implementer of those interfaces) — this actually matters, not just
belt-and-braces: `RecordReconciler::reconcileComment()` calls `pushComment()` directly during a cron
sync, entirely outside `DnsRecordWriteback`'s call path, so only the driver-level assertion catches
that one. UI surfaced in both write-control entry points: `DomainForm::injectDomainRecord()`'s
Save/Delete gating and `renderRecordWritePanel()`'s add-form, each replacing its normal
rights/type-based empty-state message with one naming read-only mode specifically when that's the
actual reason.

### Phase 54 — Per-supplier write rate limit and circuit breaker

Cap outbound mutations per supplier per rolling window. After N consecutive provider write errors,
open the circuit for a cooldown and refuse further writes with a message naming the supplier, the
failure count and when it reopens. This is the direct structural answer to a retry storm: it bounds
damage even when the underlying defect is unknown.

State persists on `glpi_plugin_domainmanager_supplierconfigs` via `Migration::addField()` —
consecutive-failure count and circuit-open-until. Per §0.10, any boolean-ish column is written as an
explicit `1`/`0`/`null` int, never a raw PHP bool.

**Verifications required before code:** each provider's own documented write rate limits, to set
defaults that are conservative rather than invented.

### Phase 55 — Blast-radius guard on reconciliation

§5.6 establishes that a fetch *failure* throws before `reconcile()` runs. A *successful* fetch of
the wrong or empty zone does not — a token scoped to a different account, or a provider returning an
empty page mid-pagination, parses as a valid empty snapshot, which `RecordReconciler` correctly reads
as "every record vanished" and soft-deletes the entire zone. That path is unguarded today.

Abort the run and set a distinct `DomainState` status when a single reconciliation would trash more
than N records or more than X% of a domain's owned records, whichever is hit first. Requires explicit
operator action to proceed. A genuinely emptied zone is rare; a wrongly-scoped credential is not.

**Outcome, 2026-08-03 (TESTING.md Phase 55):** `RecordReconciler::doReconcile()` counts, after its
existing match/claim pass but before the trash loop runs, how many currently-owned (non-deleted)
records this run would newly trash. Refuses (throws `Exception\BlastRadiusExceededException`, before
any trash-bin mutation) once that count exceeds `Config::getBlastRadiusMaxCount()` (default 20) or
exceeds `Config::getBlastRadiusMaxPercent()` (default 50) of the domain's owned records — an OR, either
threshold alone trips it. Both configurable on the Setup tab, same `plugin:domainmanager` config
context as Phase 53's kill switch. `SyncEngine::syncDnsLeg()` catches this exception distinctly and
sets the new `DomainState::STATUS_BLAST_RADIUS_GUARD` rather than `STATUS_ERROR` — a guard doing its
job, not a failure, mirroring how `STATUS_SOURCE_CONFLICT` (Phase 47) already treats a deliberate pause
as its own status rather than an error. "Requires explicit operator action to proceed": `reconcile()`
and `sync()` gained a `$force` parameter (default `false`, every existing caller unaffected); the
`POST /plugins/domainmanager/sync/{id}` endpoint accepts a `force` field, and the domain panel's
"Update Now" button offers a `window.confirm()` naming the exact counts and re-issues the request with
`force=1` only if the operator confirms — nothing forces automatically.

### Phase 56 — Typed confirmation for destructive writes

Replace `window.confirm()` (§11.19 surface 3) with a typed confirmation — the user enters the record
name — for delete/purge of a write-back-managed record. `window.confirm()` loses to muscle memory,
and these actions reach production DNS with no rollback (§11.14). Server-side enforcement in
`LockEnforcer::blockRecordRemoval()` is unchanged and remains authoritative.

**Deliberate asymmetry, recorded because it looks like a reversal and isn't.** `1.4.2` removed the
"This updates the record live at the DNS provider. Continue?" prompt on a plain Save, per explicit
request, as noise. This phase *adds* friction to delete/purge. The two are consistent on one axis: a
mistaken Save is repairable by another Save, and a mistaken delete is not — §11.14 rules out rollback
anywhere, and §11.11 makes a trash a real upstream `deleteRecord()`. If that reasoning is not accepted,
drop this phase rather than reintroducing prompts on the save path.

### Phase 57 — Provider writes into native History

Today only supplier credential changes, registrar assignment and sync milestones reach `glpi_logs`
(§3.7.2). The event "GLPI changed a live record at a provider on behalf of user X" exists only in
`domainmanager.log`.

Add one `Log::history()` line per attempted provider write, on the `Domain`, following §3.7.1's
established convention exactly: `id_search_option = 0` and a `"[Domain Manager] "` prefix, so **no
new search options are registered**.

**The line is the event, not the payload.** `Log::history()` truncates `old_value`/`new_value` at 255
chars via `mb_substr()`, which would silently mangle a DKIM `p=` value. So History carries type,
name, provider, operation and outcome; the full RDATA and request/response detail stay in
`domainmanager.log`. Truncation stops being a concern rather than being worked around.

### Phase 57b — Domain Manager right changes in Profile history

**Changing a Domain Manager right on a profile currently writes no history entry at all.** Not a
blank-field entry — no row. Verified on `11.0/bugfixes`:

- `ProfileRight` declares `$dohistory = true` and overrides `getLogTypeID()` to return
  `['Profile', $this->fields['profiles_id']]`, which is why right changes appear on the **Profile's**
  Historical tab.
- `Log::constructHistory()` carries a hardcoded `ProfileRight` special case: for the `rights` field it
  scans `SearchOption::getOptionsForItemtype('Profile')` for an option whose `'rightname'` equals the
  `glpi_profilerights.name` value being changed. On a match it builds
  `[$id_search_option, $oldval, $newval]`; **on no match `$changes` stays empty and nothing is
  inserted.**
- No option declares `'rightname' => 'domainmanager:unlock_imported'` (or the per-type write-back
  rights, or `domainmanager:purge_records`), so every Domain Manager right change is invisible.

The save path is already correct — `ProfileRight::updateProfileRights()` goes through
`ProfileRight::update()` → `updateInDB()` → `Log::constructHistory()`. Only the search options are
missing.

**Fix:** register one search option on `Profile` per plugin right via the existing
`plugin_domainmanager_getAddSearchOptionsNew()` hook, matching core's own shape (option id 1896,
`'rightclass' => Domain::class`, is the direct precedent):

```
'table'      => 'glpi_profilerights',
'field'      => 'rights',
'name'       => <label>,
'datatype'   => 'right',
'rightclass' => <class implementing getRights()>,
'rightname'  => '<the glpi_profilerights.name value>',
'joinparams' => ['jointype' => 'child', 'condition' => ['NEWTABLE.name' => '<same value>']],
```

`rightclass` is load-bearing for the rendering, not decoration: `ProfileRight::getSpecificValueToDisplay()`
resolves it via `getItemForItemtype()` and walks `getRights()` to produce the comma-joined bit labels
("Create, View all, Update all, …"). It must therefore point at a plugin class that both resolves
through the autoloader (§0.5) and exposes the plugin's bit→label map — most likely `src/Profile.php`,
which already holds that map for its `displayRightsChoiceMatrix` tab.

**This is not a reversal of §3.7.1.** That section dropped three search options for being placeholders
or duplicates of native ones, cluttering the Search UI with no filtering value. These are the
opposite: they expose real values available nowhere else, they are the only mechanism core provides
for this, and core registers 101 of them. Recorded here so the two decisions are not read as
contradictory.

**Blocking prerequisite — the search-option ID ceiling has drifted across three statements.** §3.7.4
says the reserved block is `9400-9429`; §9 item 22 (Phase 32) assigns `9430` and says the block "widens
by one"; §14.2 then assigns `9431` with nothing widening the block to cover it. No ID has been *reused*
— the never-reassign rule held, and `9425`/`9426`/`9429` remain correct permanent gaps — so nothing is
broken. Only the bookkeeping diverged, and it diverged because the number is written in prose in two
sections as well as in the JSON file.

Resolution, decided 2026-08-03:

- **`resources/search-options-registry.json` is the single source of truth** for the current ceiling and
  for retired IDs. §3.7.4 and §14.2 keep the rule and a pointer to that file; neither restates the
  number. Adding a fourth prose copy — including in this section — would recreate the drift.
- **Fold an enforcement check into this phase.** Assert at install (or in a dev-only check) that every
  ID returned by `plugin_domainmanager_getAddSearchOptionsNew()` appears in the registry and falls
  inside the reserved block. `NsProviderRegistry`'s validated-JSON-resource pattern is the precedent, so
  this is idiomatic rather than new machinery — and it converts a documentation-discipline problem into
  one the code catches, which is the only kind that survives twenty phases.
- **IDs needed here: five.** §11.6 gives four per-type rights rows (`domainmanager:dns_records_a`,
  `_aaaa`, `_cname`, `_txt`) plus `domainmanager:unlock_imported`. Search options are per right *name*,
  one per `glpi_profilerights` row — the `CREATE`/`UPDATE`/`DELETE`/`PURGE` bits are rendered by
  `getRights()` and need no IDs of their own. Presumed `9432`–`9436`, pending the registry's actual
  highest value.

**Note on §11.6 and Phase 52:** §11.6 states that "every entry point checks rights server-side,
entity-aware." That reduces Phase 52 to confirming the code matches its own documentation — still worth
doing, since `hasPurgeRight()` reading a nonexistent `type` field proves this class has diverged from
its stated conventions before.

**Verifications required before code:**
1. Confirm the plugin's Profile tab saves through `ProfileRight::updateProfileRights()` (or another
   path that reaches `ProfileRight::update()`) rather than writing `glpi_profilerights` directly — if
   it bypasses the model, no search option will produce history.
2. Confirm `getItemForItemtype()` instantiates the chosen namespaced plugin class in this context.
3. Confirm the per-type write-back rights are individually named rows in `glpi_profilerights` rather
   than packed bits on one row, since that determines how many options are needed.

---

## 15.4 Phases 58–62 — Group C, DNS record validation engine

Two rules govern the whole group.

**Write-path authoritative, read-path advisory.** Validation is enforced in
`DnsRecordWriteback::onPreAdd()`/`onPreUpdate()` — the one choke point that already covers the native
UI, the custom write panel, massive actions, the HL API and cron. On the **read/import** path it may
only flag, never reject: refusing a provider-returned record you consider malformed hides a live
record from the operator, the same failure class §11.4's "write scope ⊆ read scope" invariant exists
to prevent. Client-side checks in the add/edit panels are a UX echo of the server rules, never the
authority.

**Every message names the fix**, per the standing operator-facing-error requirement.

### Phase 58 — Explicit name-form boundary (prerequisite)

**Reframed 2026-08-03 against §3.8.2.** The earlier draft called for one canonicalizer that every
driver and write path routes through. That is now known to be wrong, because the wire form is
genuinely per-command, not per-plugin: `DinahostingDriver` alone needs the absolute form on
`Domain_Zone_AddType*`, the **relative** label on `Domain_Zone_DeleteType*` (confirmed live —
absolute is rejected outright with `2303`), `@` for an A/AAAA/CNAME apex, and the bare zone name for
a TXT/MX apex. Four conventions inside one driver, all legitimate.

So what this phase owns is the **boundary**, not a single form:

- **The internal canonical form is the absolute FQDN**, and that is settled, not up for revisiting.
  §11.20 corroborates it from the core side: `DomainRecord::getDisplayName($domain, $name)` exists
  precisely to strip the domain suffix back out for display, which confirms absolute storage is the
  GLPI-native convention rather than a plugin artefact.
- **Every wire transformation is explicit, named and paired.** `qualifyHostname()` /
  `relativeHostname()` is the precedent to generalize: an inbound normalizer applied once in
  `fetchZoneRecords()` before anything else in the driver sees a name, and an outbound denormalizer
  applied only at the specific parameter that needs it. No name transformation anywhere else, and none
  implicit.
- **Nothing outside a driver constructs a name by string concatenation.** The three name-doubling bugs
  (`dev.gal.dev.gal`, `manel.example.com.example.com`, `beiro.net.dnss`) were all one call site
  building a name inline. `absoluteRecordName()` already consolidated this for `onPreAdd()`; this phase
  makes it the only path and adds the shared apex-aware helper the other drivers currently lack.

Distinct from Phase 63's trailing dot: that concerns core's `is_fqdn` convention on the RDATA
**target** inside `data`, a different field with a different rule. The two must not be conflated —
record names carry no trailing dot internally, RDATA targets do.

Must land before Phases 59–62 and before Phase 63.

**Verifications required before code:**
1. The exact name form each provider accepts on write and returns on read, per record type **and per
   command** — the Dinahosting add/delete asymmetry proves per-type is not a fine enough grain.
2. Apex delete for TXT/MX on Dinahosting, recorded in §3.8.2 as still unconfirmed (no apex record was
   safe to test-delete against a live zone). The current code assumes the `@` convention observed for
   A/AAAA/CNAME apex. Confirm against a disposable zone rather than a production one.

**Audited 2026-08-03 (code audit only, no live account access):**
- **Dinahosting already conforms.** `qualifyHostname()`/`relativeHostname()` are exactly the
  inbound-normalizer/outbound-denormalizer pair this phase asks for: `fetchZoneRecords()` calls the
  former, `deleteByIdentity()` the latter, `createRecordRaw()` sends the absolute form untouched
  (matching the add/delete asymmetry). No inline name concatenation remains outside them.
- **Cloudflare already conforms**, trivially — the wire form is absolute FQDN on both read and write,
  so no transform is needed anywhere, and none exists.
- **IONOS had one inline transform**, `rtrim($name, '.')` at the `createRecord()` call site, which
  violated the "named, not implicit" rule even though it's a single line. Extracted into
  `IonosDriver::wireHostname()`. No inbound transform was needed or added — IONOS already returns
  absolute names as-is in `fetchZoneRecords()`, and that's correct, not a gap (see corrected §11.9
  point 4: the read-path "relativises" claim there was stale, predating this phase's canonical-form
  decision, and has been fixed to describe the actual, correct behaviour).
- **`absoluteRecordName()` in `DnsRecordWriteback` remains the single construction point** feeding
  all three drivers — `onPreAdd()`, `onPreUpdate()`, `onPreRestore()` all route through it, no bypass
  found.
- Item 2 above (Dinahosting TXT/MX apex delete) remains unconfirmed; still needs a disposable-zone
  test before it can be treated as settled.

### Phase 59 — A / AAAA validation and canonicalization

`filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4|FILTER_FLAG_IPV6)` covers standard, compressed
and loopback forms; no library needed. Pair it with `inet_pton`/`inet_ntop` canonicalization —
otherwise `2001:0db8::1` and `2001:db8::1` read as a diff on every sync and the reconciler churns
indefinitely.

Warn (not block) on an A/AAAA value in RFC1918, `127/8`, `0.0.0.0`, `169.254/16` or IPv4-mapped IPv6
space on a public zone. Legitimate in split-horizon setups, so this cannot be a hard refusal.

**Implemented 2026-08-03** as `RecordValidator::validateAddress()` (`src/Service/RecordValidator.php`),
covering both the strict validation and the canonicalization/warning behaviour above. Not yet wired
into `DnsRecordWriteback` — that's Phase 62's job, once Phases 60-61 exist too.

### Phase 60 — CNAME validation, including the relational rule

Block: not a valid FQDN, self-reference, label >63 octets, name >253 octets, and — the rule that
matters — **a CNAME may not coexist with any other record type at the same owner name** (RFC 1034).
`duplicateNameError()` keys on `domains_id`+`domainrecordtypes_id`+`name`, so CNAME-plus-A at one
name passes it today and produces a broken zone.

Apex CNAME is refused unless the driver declares support (Phase 66).

**Implemented 2026-08-03**: FQDN shape, self-reference and apex-refusal live in
`RecordValidator::validateCnameTarget()` (pure, no DB access). The relational rule itself — the
part this phase is actually named for — needs a DB query across all types at a name, which that
class deliberately has none of, so it's `DnsRecordWriteback::cnameCoexistenceError()` instead,
alongside the existing `duplicateNameError()`. Neither is wired into `onPreAdd()`/`onPreUpdate()`
yet — Phase 62, same as Phase 59.

### Phase 61 — TXT validation: block mechanics, warn semantics

**Block** what is unambiguous: any single character-string exceeding 255 octets (a long DKIM key must
be split into multiple strings, and providers differ on whether they chunk for you), quoting
inconsistency against core's `quote_value` convention, and more than one `v=spf1` record at a single
name (RFC 7208 forbids it and it is a common outage).

**Warn** on semantics: SPF exceeding 10 DNS-lookup mechanisms, DMARC not at `_dmarc` or missing `p=`,
DKIM missing `p=`. A strict SPF parser that blocks a valid-but-unusual record is worse than no
parser.

**Verifications required before code:** whether each provider accepts a single long TXT string and
chunks it server-side, or requires pre-chunked strings. §11.5 records that IONOS returns TXT quoted
and agrees with core; the other two are unverified on this specific point.

**Implemented 2026-08-03** as `RecordValidator::validateTxtContent()` (blocks: >255-octet content,
since this plugin has no chunking of its own so the pre-chunking verification above stays open but
doesn't block landing this; and a value already wrapped in a matching quote pair, which conflicts
with this plugin's own always-unquoted internal convention — see §11.5 — rather than being unwrapped
by guesswork) plus `DnsRecordWriteback::spfDuplicateError()` (blocks a second `v=spf1` TXT at one
name — needs a DB query across all TXT records at the name, same reason `cnameCoexistenceError()`
lives outside `RecordValidator`). Warns: SPF over 10 DNS-lookup mechanisms (regex-counted, not a full
parser), DMARC content not at a `_dmarc` label or missing `p=`, DKIM content missing `p=`. Not yet
wired into `DnsRecordWriteback::onPreAdd()`/`onPreUpdate()` — Phase 62, same as Phases 59-60. Not yet
verified live.

### Phase 62 — Wire-up and TTL guardrails

Register the validators at the hook layer, echo them client-side, and add a TTL floor per provider
minimum rather than a hardcoded number.

**Verifications required before code:** each provider's documented minimum TTL and its behaviour on
TTL 0 (§11.9 notes the reference client omits the field entirely at 0).

---

## 15.5 Phases 63–66 — Group D, full record-type coverage

### Phase 63 — Per-type RDATA codec

Core defines the canonical `data` representation and the plugin follows it (§11.5's decision,
unchanged). Verified in `DomainRecordType::$knowtypes` and
`templates/pages/management/domainrecordtype_helper.html.twig` on `11.0/bugfixes`: field values are
**joined with a single space in declaration order**, `is_fqdn` fields get a **trailing dot**, and
`quote_value` fields are **wrapped in double quotes** with inner quotes escaped as `\"`.

| Type | Canonical `data` |
|---|---|
| MX | `10 mail.example.com.` |
| SRV | `0 10 5060 sip.example.com.` |
| CAA | `0 issue "letsencrypt.org"` (`value` is `quote_value`) |
| SOA | seven space-joined tokens, four of them `is_fqdn` |

A pipe or other custom separator was **considered and rejected**: the edit form's helper modal
reconstructs its per-field inputs by parsing `data` client-side on space and quote boundaries, so a
non-canonical string mis-parses in the UI, and a plugin-imported record would render differently from
a hand-created one in the same form. Following core means there is no second convention to define,
document or maintain.

`data_obj` is populated in the **same input array** as `data`. Core's `pre_updateInDB()` nulls
`data_obj` whenever `data` changes and `data_obj` is absent from the input, so the two can never be
written separately. The importer has the fields decomposed at the moment it composes the string, so
this is nearly free — and it is what lets the helper modal pre-fill reliably instead of depending on
that parser.

**Verifications required before code:** whether imported `MX` records are *currently* stored in core-
canonical form or as the provider's raw value plus a separate `prio`. §11.5 scoped the convention to
the four writable types and states that multi-field RDATA is outside it, but MX has always been in
the *read* whitelist — so a hand-created and a plugin-imported MX for the same target may already
differ by a few characters, which is exactly the `record_hash` churn §11.5 was written to prevent.
If so, this phase carries a one-time renormalization migration, same idempotent pattern as
`Installer::renormalizeDinahostingRemoteIds()`.

### Phase 64 — Widen the read whitelist to all 11 types

Import whatever the provider returns, per type, skipping gracefully what it does not.
`Installer`'s existing by-name type assertion widens to the full seeded set.

The write scope stays at A/AAAA/CNAME/TXT. §11.4's invariant — write ⊆ read — is preserved and in
fact strengthened, since read is now the full set. NS and MX remain deliberately non-writable for
the reasons recorded there; widening read does not reopen that.

### Phase 65 — SOA / PTR / ALIAS reality check

Expected outcome: mostly absent. SOA is zone metadata that most providers do not return in a records
list; PTR lives in reverse zones these accounts do not own; ALIAS is §11.4's documented dead end on
IONOS Hosting. Phase 64 imports them if they arrive. This phase records per provider what actually
arrives, so the gap is documented rather than looking like a defect later. No write support, ever.

### Phase 66 — Apex-CNAME capability flag (the real shape of the ALIAS request)

The ALIAS/ANAME evaluation was already completed and closed negative for IONOS on evidence (§11.4).
What remains is Cloudflare-specific, and there it needs no new record type at all: Cloudflare flattens
a CNAME at the zone apex. So the feature reduces to a **driver capability flag** — a driver declares
apex-CNAME support, and Phase 60's apex refusal defers to it. No new itemtype, no new UI.

**Verifications required before code:** whether Cloudflare's DNS records API accepts a CNAME at the
zone apex and flattens it as documented; whether Dinahosting supports any apex-alias behaviour.

---

## 15.6 Phases 67–68 — Group E, NS registry and takeover detection

### Phase 67 — Hostalia and Ascio detection entries (Ubilibet dropped)

Detection-only entries (no `driver` key ⇒ the existing "API integration not currently supported"
banner plus contribution link).

**Ubilibet is dropped, and the reason generalizes.** `dig NS ubilibet.com` returns
`ns1`–`ns4.ascio.com`, so Ubilibet is a **reseller on Ascio's wholesale registrar platform**, not an
operator of branded nameservers. NS-based detection therefore cannot identify Ubilibet, and no amount
of pattern work will change that. This is not the `ui-dns` situation: there, each sibling brand had a
distinguishable NS label (`ns-strato.`, `ns-arsys.`, numeric `ns[0-9]*` for IONOS itself), so
*narrowing* separated them. Here every Ascio reseller's customers land on the same four hostnames with
no per-reseller label, so narrowing is not merely unnecessary — it is impossible. `Ubilibet` would be
a name the registry can never legitimately return.

**Ascio replaces it.** `ns1`–`ns4.ascio.com` resolve (`ns5`/`ns6` do not; a bare `ns.ascio.com` does),
so `*.ascio.com` is the correct pattern shape and needs no narrowing — unlike `ui-dns.*`, `ascio.com`
is a single brand's namespace. Two observations from probing worth carrying into the entry:

- `ns1.ascio.net` also resolves, into `156.154.130.100` (UltraDNS space), while `ns3`/`ns4.ascio.com`
  sit in `64.98.148.x` / `216.40.47.x` (Tucows). Ascio appears to layer over other platforms and may
  have more than one delegation set. Include `*.ascio.net` as a second pattern on the same entry —
  same precedent as folding Dinahosting's undocumented `gestiondecuenta.com` into its existing entry
  rather than creating a new provider.
- Hostalia's range is wider than first probed: `ns1`, `ns4` and `ns5.hostalia.com` all resolve in
  `82.194.x`, so `ns[0-9]*.hostalia.com` rather than an enumerated `ns1`–`ns3`.

**This makes §0.1's separation concrete, and it should be recorded as such.** For a wholesale platform
the NS answers "which DNS platform serves this zone" (Ascio) and cannot answer "who do we pay and
contact at renewal" (Ubilibet). The registry has one `name` per entry and no reseller concept — and
needs none, because §0.1 already establishes Registrar as a separate, manually-assigned field mirroring
Infocom's native Supplier. Detection populates the DNS platform; the Supplier field carries the
commercial relationship. Worth stating explicitly in §4, because the natural follow-up request is
"make it say Ubilibet" and the honest answer is that it structurally cannot.

**Verifications required before code:**
1. Live `dig NS` against a **real Ubilibet-managed customer domain**, not `ubilibet.com` itself. The
   probe so far is Ubilibet's own corporate domain, which is one data point and not the one §4's
   standing rule asks for. This also settles whether customer zones land on `ascio.com` or `ascio.net`.
2. Live `dig NS` against a real Hostalia customer domain. Hostalia runs on Acens/Telefónica
   infrastructure, so confirm customers delegate to `*.hostalia.com` and not to a sibling brand's label
   on a shared host — the `ui-dns` check, which is still needed here even though it turned out moot for
   Ascio.
3. Registry file order: `*.ascio.com` is specific enough not to collide with existing entries, but
   §4's "first matching entry wins, checked in file order" makes that worth confirming rather than
   assuming.

### Phase 68 — Dangling-CNAME / subdomain-takeover flag

Cron-based, advisory. Flag a managed CNAME whose target does not resolve — the precondition for
subdomain takeover. Fits the plugin's inventory purpose and is genuinely a security finding rather
than a hygiene one. Advisory status only; never auto-deletes anything.

Rate-limit posture follows §9 Phase 22's precedent: tick spacing is the defense, and nothing outside
the cron may trigger these lookups.

---

## 15.7 Deferred and rejected, recorded so they are not silently revisited

- **Per-profile *attribution* of item changes — rejected.** Asking "which profile made this change to
  this domain" is not answerable: `glpi_logs` has no `profiles_id` and its `user_name` is a formatted
  display string, not an FK. Delivering it would require a plugin-owned audit table duplicating what
  native History already covers for `Domain`/`DomainRecord` (both declare `$dohistory = true`, and
  `DomainRecord` is a `CommonDBChild` inheriting `$logs_for_parent = true`, so record add/update/delete
  already produce both per-record history and `HISTORY_ADD_SUBITEM`/`UPDATE_SUBITEM`/`DELETE_SUBITEM`
  entries on the parent Domain).
  **Distinct from, and not to be confused with, Phase 57b** — logging *changes to a profile's own
  rights* on that Profile's Historical tab. That one is natively supported, currently broken for this
  plugin, and in scope. The two were conflated in the original requirement; keeping them separate
  matters because one is impossible and the other is cheap.
- **Dry-run / preview diff before sync and write — deferred**, tracked as its own GitHub Issue.
- **Custom RDATA separator (pipe or other) — rejected**, Phase 63.
- **Write support for the seven newly-readable types — rejected**, Phase 64. §11.4's reasoning for
  excluding NS and MX is unchanged by their becoming readable.
- **ALIAS as a record type — closed negative** (§11.4), superseded by Phase 66's capability flag.
- **Ubilibet as a registry entry — rejected 2026-08-03.** `dig NS ubilibet.com` returns
  `ns1`–`ns4.ascio.com`: Ubilibet is a reseller on Ascio's wholesale platform, and every Ascio
  reseller's customers share those hostnames with no distinguishing label. NS-based detection cannot
  identify a reseller on a shared wholesale platform — not a pattern-tuning problem, a structural one.
  Replaced by an `Ascio` entry (Phase 67). Recorded because the same reasoning will apply to the next
  reseller brand someone asks for, and because it is the concrete case that separates §4's DNS-platform
  detection from §0.1's manually-assigned Registrar field.

---

## 15.8 Open items awaiting approval

1. This section as a whole, before any code (Phase 0).
2. **Resolved** — `1.4.2` shipped 2026-08-03 and `[Unreleased]` is `1.5.0-beta1`; see §15.1. Retained
   here only so the earlier open item is visibly closed rather than dropped.
2b. Phase 49's second half — whether a reconciler-path duplicate refusal should set a `DomainState`
   status, or whether an unrepresentable upstream duplicate should instead be imported and flagged.
   The plan proposes the former; the latter is arguably more honest about what the zone contains.
3. Phase 51b — whether the `request()` error-mapping correction belongs in Group A at all, or is
   small enough to fold into whichever phase next touches `DinahostingDriver`.
4. Phase 52's outcome is unknown by design — it may be a bug fix or a no-op with a regression test.
5. Phase 57b's ID allocation — the registry file is the last thing blocking that phase. The plan
   presumes `9432`–`9436` and makes `search-options-registry.json` the single source of truth for the
   ceiling, with the prose in §3.7.4/§14.2 reduced to a pointer. Confirm the file's actual highest
   value, and confirm the enforcement check is wanted rather than just the corrected number.
