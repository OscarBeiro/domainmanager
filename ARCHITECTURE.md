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
- **`testConnection()`**: Dinahosting authentication is a single account-wide username/password, not scoped per capability, so one lightweight, side-effect-free probe (`System_GetRequestTypes` — confirmed domain-independent from the docs' own example request, which omits a `domain` parameter) classifies both `'registrar'` and `'dns'` from the same outcome. `responseCode` → `ConnectionTestStatus`: `2200` (`AUTH_ERROR_USER`) → `AuthFailed`, `2201` (`AUTH_ERROR_OBJECT`) → `Forbidden`, `2501` (`COMMAND_TIMEOUT`) → `Timeout`, anything else non-success → `UnknownError`. `httpStatusCode` is left `null` for these envelope-classified outcomes (always ~200 regardless of the real result — showing "HTTP 200" next to an "auth failed" badge would be misleading); it's only populated for genuine transport-level 5xx.
- **`fetchLifecycle()`**: `Domain_GetExpirationDate` / `Domain_GetRegistrationDate` (each confirmed: single `domain` parameter, returns a string). **Known gap, flagged rather than guessed**: no documented response shape for a registrar-hold/suspended status command (`Domain_Status_Get`) was found anywhere searched, so `LifecycleStatus` here only distinguishes `Ok`/`Expired` (via the expiration date) — never `Suspended`. Revisit if Dinahosting's docs (or a support ticket) ever surface that command's real shape.
  - **`Domain_GetRegistrationDate`'s exact semantics are unconfirmed** (checked directly against the live doc page, not from memory: the entire description is "Returns registration date of domain." — no elaboration). Two readings are possible: (a) the domain's real/original registry creation date (a WHOIS/RDAP-style "Creation Date", invariant across registrar transfers per ICANN's Transfer Policy), or (b) the date the domain was added to/transferred into this Dinahosting account specifically. **Assumed to be (a)**, based on the command taking only `domain` (no account-scoping hint) and Dinahosting modeling inbound transfers as their own separate command family (`Domain_CheckForTransfer`, `Domain_Transfer_GetStatus`, `Billing_Transfer_Domain`) rather than folding "transfer date" into this general domain-info command — but this is inference from API shape and EPP/registry convention, not a confirmed fact. **Only verifiable against a domain known to have been transferred in from another registrar** (compare this command's returned date to that domain's actual WHOIS/RDAP creation date) — genuinely hard to test opportunistically since transferring a domain isn't a routine, frequent event; revisit whenever such a domain becomes available to check.
- **`fetchZoneRecords()`**: `Domain_Zone_GetAll`. Field names for **A/AAAA (`ip`), CNAME (`destinationHostname`), TXT (`text`)** are confirmed against the reference client. **Known gap**: that client only ever *writes* A/AAAA/TXT/CNAME, so it never modeled MX/NS fields specifically — `extractContent()` falls back to the same generic field set the reference client uses for record types it doesn't recognize either. MX priority ordering in particular is unconfirmed; verify against a real account with real MX/NS records before relying on it.
- Both pipeline methods route API errors through a shared `request()` helper mirroring `CloudflareDriver::request()`'s shape (`DriverException` with a safe message, technical detail to `PluginLogger::error()` — including the object-not-found code `2303` mapped to a clear "domain not managed by this account" message).
  - **Fixed a real bug (found live, §addendum "Debug: Dinahosting Registrar Auth Failure"): `request()` originally collapsed `2200` (`AUTH_ERROR_USER`, the account's own credentials rejected) and `2201` (`AUTH_ERROR_OBJECT`, credentials fine but *this specific domain* isn't authorized under the account) into the identical "Dinahosting authentication failed, check the username/password" message** — even though `testConnection()`'s `classifyEnvelope()` (just above) already correctly treats them as distinct (`AuthFailed` vs `Forbidden`). Reported live as domain `pontecm.com` (supplier: Dinahosting) showing registrar = "authentication failed", while a different domain (`tic.gal`) under the *same* supplier/credentials synced `ok` and Check Connection reported success — ruling out a real account-level credential problem. `2201` now gets its own message ("Dinahosting authentication succeeded, but this account is not authorized to manage this domain"), so a per-domain authorization gap is never misreported as a credentials problem.
- `PluginLogger::redact()` (§3.6) was widened to also catch Dinahosting's non-standard `AUTH_PWD`/`pwd=` credential naming (the existing pattern only matched the literal word "password") — defense in depth, since this driver's own code never logs a raw request URI in the first place.
- **§9 Phase 7 (2026-07-21)**: `fetchLifecycle()` now also fetches `authInfo` via `Domain_GetAuthcode` (real command, confirmed by live probe; see §9 for the full per-driver support matrix). `privacyEnabled`/`domainLock`/`transferLock`/`autoRenew` each have a real, confirmed-existing Dinahosting command too, but no confirmed response shape for any of them — left `null` rather than guessed.

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

Every phase leaves install → uninstall residue-free.

---

## 10. Versioning & changelog policy

`PLUGIN_DOMAINMANAGER_VERSION` in `setup.php` is the single source of truth for the plugin's version (no `composer.json` version field is used). **Every bump of that constant must land in the same commit as a matching `CHANGELOG.md` entry** — a new `## [x.y.z] - YYYY-MM-DD` section (Keep a Changelog format) containing whatever `### Added`/`### Changed`/`### Fixed` bullets accumulated under `[Unreleased]` since the previous version section, moved (not duplicated) out of `[Unreleased]` into the new version's section. A bump with no shipped content yet (e.g. a bare version-number increment immediately superseded by a later bump before anything else changed) still gets its own one-line section noting "version bump only — no functional changes", so the version history stays honest and every constant value that ever existed is traceable in the changelog. `[Unreleased]` itself is kept present but empty between releases, ready to accumulate the next round of bullets.

**Pre-release amendment (resolved 2026-07-29, for §11.15's five-phase `-alphaN`/`-betaN` sequence):**
each pre-release bump (`1.2.0-alpha1`, `-alpha2`, `-alpha3`, `-beta1`) still gets its own dated
`## [1.2.0-alphaN] - YYYY-MM-DD` section at the time it lands, same as any other bump — nothing
changes about *when* changelog entries are written. The amendment is about the eventual `1.2.0`
release section: **it consolidates.** When the plain `## [1.2.0] - YYYY-MM-DD` section is written
at release, its `### Added`/`### Changed`/`### Fixed` bullets are a clean rewrite of everything
that shipped across `-alpha1` through `-beta1` — not a duplicate list, not a bare "see above"
pointer. The four pre-release sections themselves are **not deleted**; they stay in the file as
the honest historical record of how the feature actually landed session-by-session, but a reader
who only cares about released versions gets one coherent `1.2.0` entry without needing to read
the pre-release trail.

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

**One right, three native bits:** `domainmanager:dns_records`, carrying core `CREATE`, `UPDATE`
and `DELETE`.

Rendered as a single right row using core's own action-label checkboxes, so it reads like every
other GLPI right rather than a bespoke checkbox set, via `Profile::displayRightsChoiceMatrix()`
(`src/Profile.php:3565` on `11.0/bugfixes` — confirmed to exist; an earlier draft's claim that it
didn't was checked against the wrong branch, see §11.16). Since `Profile::getRightsForForm()` is
core-only, the plugin's own `Profile`-tab hook builds a small `$rights` array itself and calls
`displayRightsChoiceMatrix()` directly (see §11.16 for the exact call). Registered with one
`Migration::addRight()` at install and removed with one `ProfileRight::deleteProfileRights()` at
uninstall, following the existing `domainmanager:unlock_imported` plumbing.

Distinct from `domainmanager:unlock_imported`, which continues to govern editing plugin-tracked
records through the *native* form and emptying the trash. The two do not overlap: this right
authorises writes *through the plugin panel, to the provider*; that one authorises local
overrides of plugin locks.

**Rejected alternatives, recorded:** a two-tier split (`…:dns_records` +
`…:dns_records_critical`) became pointless once `NS`/`MX` were excluded — there is no critical
tier left to gate. Six flat single-bit rights would work but produce an uglier profile UI and
lose core's action-label grid. A single right with six custom bits keeps one row but discards
the matrix helper's semantics.

**Every entry point checks rights server-side**, entity-aware, as with every existing controller
(§6.3). UI gating is cosmetic only.

### 11.7 Interaction with existing enforcement

**`LockEnforcer` is not inverted, relaxed, or special-cased.** Native edit, delete and purge of
plugin-tracked records remain blocked exactly as today, uniformly for every provider — IONOS and
Cloudflare alike. There is no scenario where the same record is editable in one surface and
locked in another, because the native surface stays locked for all of them.

**The plugin panel becomes the only write surface**, gated by `domainmanager:dns_records` plus a
write-capable driver. This is the simpler invariant to hold: *plugin-tracked records are edited
through the plugin, or not at all.*

Two mechanical requirements follow:

1. **A record-level blanket guard** distinct in name from both native `Lockedfield` and the
   plugin's own per-field `ImportLock`, so the three are not confused in code or logs.
2. **The delete path needs the existing `LockEnforcer::$sync_in_progress` bypass**, the same one
   `RecordReconciler::reconcile()` already sets around reconciliation, so the plugin's own
   authorised delete is not blocked by the plugin's own guard.

**Native `managed_domainrecordtypes` gate (§0.4) — pre-flight, not workaround.**
`DomainRecord::prepareInput()` blocks add/update when the acting profile's manageable record
types exclude the record's type, *unless* `Session::isCron()`. Full-control writes run under a
web session, so core gates the **local** write independently of the new right — which could push
successfully to IONOS and then fail to record it locally.

Handling: **pre-flight the type against the acting profile's manageable types and refuse before
any driver call**, naming that specific profile setting in the message. No API request is made
if the local write cannot succeed. Because the four writable types are all within the set
profiles already need for "Update Now" today, **this requirement does not widen** — no new
admin action, no new documentation burden.

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
in use by `fetchZoneRecords()` (§3.9). Endpoints and payload shape below are taken from the
maintained `libdns/ionos` reference client, the same source §3.9 used to establish the read wire
format, because IONOS's docs portal is a JS-rendered SPA with nothing scrapable.

| Operation | Request | Notes |
|---|---|---|
| create | `POST /zones/{zoneId}/records` | Body is a JSON **array**; response is an array of created records **including their ids** |
| update | `PUT /zones/{zoneId}/records/{recordId}` | **No response body** |
| delete | `DELETE /zones/{zoneId}/records/{recordId}` | |

Record payload: `{name, type, content, ttl, prio, disabled}`.

**Five wire-level traps, each with a required response:**

1. **`PUT` is a full replace, not a `PATCH`.** Every field must be sent on every edit. This makes
   the live re-fetch in §11.10 *structurally required* to build a valid body — not merely a
   safety nicety.
2. **TTL below 60 is rejected with HTTP 400.** The reference client omits the field entirely
   when TTL is 0. Both belong in modal validation, client- and server-side.
3. **`disabled` must be sent explicitly as `false`.** The reference client marks it `omitempty`
   with an unresolved comment about the default being `true`; omitting it risks creating a
   disabled record that resolves nowhere while looking correct in GLPI.
4. **Names are absolute at IONOS and carry no trailing dot.** The read path relativises against
   the zone name; the write path must re-absolutise and strip any trailing dot.
5. **The create response carries the provider id**, so `remote_id` is captured at push time from
   the response itself — no follow-up read, no reliance on the next sync. (This is all that
   remains of an earlier "store the provider id" work item: the `remote_id` column already
   exists and is indexed, and `RecordReconciler` already matches on it first, falling back to
   `record_hash`.)

**Zone resolution** reuses `findZoneId()` unchanged, including its client-side case-insensitive
match — the API has no filter-by-name parameter for zones.

### 11.10 Immediate push, with three purpose-built confirmations

**No staging, no pending state, no Apply step.** A staged model (queue changes locally, review,
apply as a batch, allow cancellation) was designed and **rejected**: it required new pending-state
columns, cancelable state transitions, batch-apply semantics and dual purge logging, all to
prevent accidental changes. The same protection is achieved by making each individual action
explicitly confirmed, at a fraction of the mechanism.

**Three modals, each purpose-built rather than one generic prompt:**

- **Create** — shows the record about to be created: type, name, data, TTL, target domain and
  provider.
- **Edit** — shows **previous versus new, field by field**.
- **Delete** — shows the record and states plainly that it cannot be undone.

**The edit modal's "previous" side is a live re-fetch from IONOS**, via `fetchRecord()`, not the
local mirror. The mirror is only as fresh as the last sync, so someone editing in IONOS's own
panel since then would have their change silently overwritten by a GLPI edit built on stale
values. If the live values disagree with the local mirror, **surface the disagreement in the
modal.** If the fetch fails, fall back to local values with a visible note that they could not be
verified against the provider — never silently.

**A live NS re-check runs immediately before the push.** The modal's re-fetch proves the *record*
still exists; it does not prove IONOS is still authoritative for the zone. Nameservers can have
moved to another provider since the last sync while the zone remains present in the IONOS
account, in which case the API accepts a write that changes nothing anyone resolves. Re-checking
the domain's NS immediately before pushing closes that window (§11.3's volatility).

**Confirmation-modal implementation follows GLPI 11's own convention.** §11.15 records this as a
required verification against `11.0/bugfixes` before hand-rolling anything.

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

Five phases. Each is one Claude Code session, committed and pushed before context is cleared.

| Phase | Version | Content |
|---|---|---|
| **31** | — | This architecture section. **No code.** Stop for approval. |
| **32** | `1.2.0-alpha1` | Schema (`is_glpi_created`), one right, one search option |
| **33** | `1.2.0-alpha2` | `DnsRecordWriterInterface` + IONOS implementation. Testable via throwaway harness, no UI |
| **34** | `1.2.0-alpha3` | Controllers, three modals, rights gating, §0.4 pre-flight, live NS re-check, `ImportedRecord` row, Historical lines |
| **35** | `1.2.0-beta1` | End-to-end verification; finalise `ARCHITECTURE.md`, `CHANGELOG.md`, `TESTING.md`. Refining only, nothing new built |
| release | `1.2.0` | |

**Alpha means "still assembling"; beta means "complete and hardening"** — an honest signal if a
client is to test before release.

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

**Before Phase 33:**
- Fetch and read `https://developer.hosting.ionos.de/assets/kms-swagger-specs/dns.yaml` — the DNS
  counterpart of the `domains.yaml` spec §3.9 already uses. It is served as
  `application/octet-stream`, so it must be pulled with `curl` rather than a browser-oriented
  fetcher. Read the **record-type enum** and required fields from it. This is the authoritative
  answer to two open questions: whether `ALIAS` exists on the Hosting API at all (§11.4), and
  whether TTL/priority constraints match the reference client's behaviour.
- Confirm the create/update/delete paths and payload schema against that spec rather than
  relying solely on the reference client.

**Before Phase 34:**
- GLPI 11's own confirmation-modal convention, before hand-rolling one.

### 11.17 Deferred and rejected, recorded so they are not silently revisited

| Item | Disposition |
|---|---|
| Read-scope expansion to all 11 GLPI types | **Deferred as a standalone feature.** Independently valuable (better mirror fidelity for zones with SRV/CAA/SOA) but unrelated to write-back once write scope became a subset of read scope. Carries the real cost: multi-field `data` serialization across three drivers, a one-time record influx on existing installs at first sync, and a widened `managed_domainrecordtypes` requirement. Schedule against a client who needs SRV or CAA. |
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

---

*Open items awaiting your approval: the four deviations in §0.1–§0.4 (Registrar as plugin field, `date_domaincreation` mapping, plugin-owned lock layer replacing native `Lockedfield`, documented `managed_domainrecordtypes` gate on web-triggered record writes), the CREATE TABLE exception in §0.6, and §11 (Phases 31–35 — Manual DNS record write-back to IONOS). The two items that were blocking Phase 32 — the rights-matrix rendering mechanism (§11.6/§11.16) and the §10 changelog-policy amendment for pre-release versions (§11.14) — are both resolved as of 2026-07-29; Phase 32 is unblocked.*
