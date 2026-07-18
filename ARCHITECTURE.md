# Domain Manager — Architecture (GLPI 11)

Plugin key: `domainmanager` · Namespace: `GlpiPlugin\Domainmanager\` · Target: **GLPI 11.0.x only** (verified against `glpi-project/glpi` branch `11.0/bugfixes`, commit `9c59c56c`).

Read-only inventory of domain lifecycles (Registrar pipeline) and DNS zone records (DNS Provider pipeline), with hardcoded drivers: `none`, `cloudflare`, `ionos`, `dinahosting`.

---

## 0. Verified GLPI 11 internals & deviations from the brief

Everything below was checked on `11.0/bugfixes` source. **Four assumptions in the brief do not hold on GLPI 11 and require the adjustments described — please approve them explicitly.**

### 0.1 `glpi_domains` has no `suppliers_id` column → "Registrar" is a plugin-owned field
The native Domain table (verified in `install/mysql/glpi-empty.sql`) contains no supplier link at all (only `users_id`, `users_id_tech`). There is nothing to relabel.
**Adjustment:** the plugin injects its own **"Registrar"** supplier dropdown into the Domain form (via the `post_item_form` hook) and persists it in the plugin state table (`registrar_suppliers_id`). The registrar pipeline resolves credentials from that supplier's `supplierconfigs` row. No core table is altered.

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
│   │   └── DnsPipelineInterface.php     NEW  fetchZoneRecords(string $domain): ZoneRecord[]
│   ├── Dto/
│   │   ├── DomainLifecycle.php          NEW  value object: creation date, expiration date, status enum
│   │   └── ZoneRecord.php               NEW  value object: type, name, data, ttl, remote id (sanitised)
│   ├── Driver/
│   │   ├── CloudflareDriver.php         NEW  full implementation (both interfaces)
│   │   ├── IonosDriver.php              NEW  stub, clearly-marked TODO (both interfaces)
│   │   └── DinahostingDriver.php        NEW  stub, clearly-marked TODO (both interfaces)
│   ├── Service/
│   │   ├── SyncEngine.php               NEW  orchestrates the split pipeline for one domain (§5)
│   │   ├── NsResolver.php               NEW  dns_get_record(DNS_NS) wrapper (testable seam)
│   │   ├── RecordReconciler.php         NEW  idempotent create/update/flag-removed into glpi_domainrecords
│   │   └── SyncLogger.php               NEW  Log::history milestones + Toolbox::logInFile channel
│   └── Controller/
│       └── SyncController.php           NEW  POST /plugins/domainmanager/sync/{domains_id} (§6)
│
└── templates/
    ├── domain_panel.html.twig           NEW  status card + DNS provider row + Update Now + unsupported/
    │                                         unknown warning block (contribution link) + lock JS
    └── supplier_tab.html.twig           NEW  driver select + per-driver credential fields + JS toggle
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
| `date_mod` / `date_creation` | timestamp NULL | GLPI convention |

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
| `registrar_status` | varchar(50) NOT NULL DEFAULT 'never' | `never` \| `ok` \| `error` \| `unconfigured` |
| `registrar_message` | text | last human-readable outcome (sanitised, no secrets) |
| `dns_status` | varchar(50) NOT NULL DEFAULT 'never' | `never` \| `ok` \| `error` \| `unsupported` \| `unknown` \| `unconfigured` |
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
│ + testConnection(): void     │        │ + testConnection(): void        │
└──────────────△──────────────┘        └───────────────△────────────────┘
               │  implements                            │  implements
     ┌─────────┴──────────────┬──────────────────┬─────┴────────┐
     │ CloudflareDriver (full)│ IonosDriver(TODO)│ Dinahosting  │
     │  - token               │  - key + secret  │ Driver(TODO) │
     │  - Toolbox::getGuzzle… │                  │  - user+pass │
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

`CloudflareDriver` implements **both** interfaces (Registrar API + DNS records API). IONOS/Dinahosting stubs declare both and throw a typed `NotImplementedException` with an i18n message ("driver not yet implemented").

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
   │       registrar supplier = states.registrar_suppliers_id
   │       ├─ none/driver 'none'/no creds → registrar_status = 'unconfigured'
   │       └─ DriverFactory→RegistrarDriverInterface::fetchLifecycle()
   │             ├─ ok  → map DTO → Domain->update([date_domaincreation, date_expiration,
   │             │        is_active(1=OK / 0=Suspended|Expired)])  [+ name confirmation]
   │             │        → refresh ImportLock rows (name, date_domaincreation,
   │             │          date_expiration, is_active) → Log::history changes
   │             │        → registrar_status = 'ok'
   │             └─ DriverException → registrar_status = 'error', message persisted,
   │                                  payload → Toolbox::logInFile('plugin_domainmanager')
   │
   │ 4. DNS LEG (isolated try/catch — never aborts registrar results)
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
- All API payloads validated before persisting: type whitelist, hostname/content length caps, TTL int-range, UTF-8 scrub; unknown record types skipped (read-only scope: A, AAAA, NS, TXT, MX, CNAME).
- Cron loops **active, non-deleted, non-template** domains in batches (default 20/run, `CronTask` param-tunable) with per-domain try/catch isolation; each processed domain increments the task counter.

---

## 6. Endpoints, hooks & cron registration map

### 6.1 Supplier tab (credentials)
`SupplierTab` registered via `Plugin::registerClass(SupplierTab::class, ['addtabon' => Supplier::class])`. Tab "Domain Manager" renders `supplier_tab.html.twig`: driver `<select>` from `DriverRegistry::getAvailableDrivers()` + per-driver fields toggled by small inline JS:
- cloudflare → API Token
- ionos → API Key + Secret
- dinahosting → Username + Password

Save posts to the standard tab form path handled by `SupplierConfig` (CommonDBTM add/update); payload assembled to JSON and encrypted with `GLPIKey::encrypt()`. Existing secrets are never echoed back (placeholder "●●● saved"); empty submit keeps the stored secret. Access gated by `config` UPDATE (§8).

### 6.2 Domain form injection
| Hook | Itemtype | Handler | Purpose |
|---|---|---|---|
| `Hooks::POST_ITEM_FORM` | `Domain` | `DomainForm::inject()` | Renders `domain_panel.html.twig` inside the form: **Registrar** supplier dropdown (named `_domainmanager_registrar`), **DNS Provider** read-only display (detected name, linked to supplier when resolved), **status card** (last sync date + green/red Registrar/DNS badges), **Update Now** button, unsupported/unknown warning + "Help us support this provider" link, and the lock-disabling JS. Rendered only with `domain` READ; dropdown/button only with `domain` UPDATE. |
| `Hooks::ITEM_ADD` / `ITEM_UPDATE` | `Domain` | `HookHandler` | Persist `_domainmanager_registrar` into `states.registrar_suppliers_id`. |
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
| Set the Registrar field on a Domain | native `domain` UPDATE | `HookHandler` (input persisted only if `can()`) |
| Configure supplier credentials (tab visible + save) | native `config` UPDATE | `SupplierTab` + `SupplierConfig::can*` |
| Edit/unlock sync-locked Domain fields | **`domainmanager:unlock_imported`** (single bit, value 1) | `LockEnforcer` (server-side, every entry point incl. massive actions & API since hooks fire on model update) |
| Edit/delete/purge plugin-imported DomainRecords | **`domainmanager:unlock_imported`** (+ native `managed_domainrecordtypes` gate still applies, §0.4) | `LockEnforcer` |
| Grant the plugin right | native `profile` UPDATE | `src/Profile.php` tab (`displayRightsChoiceMatrix` + `ProfileRight`) |

Right registered per-profile via `Migration::addRight` at install and manageable afterwards in a "Domain Manager" section of the Profile form (dedicated Profile tab). Right name is the literal string `domainmanager:unlock_imported` (a `glpi_profilerights.name` value; GLPI accepts arbitrary strings — verified `varchar(255)` + `Session::haveRight` bitmask check).

---

## 9. Phase plan (unchanged from the brief)

1. **Phase 1** — `setup.php`, `hook.php`, `Installer`, `Profile` right, cron shell: installs/uninstalls cleanly (UI + `bin/console glpi:plugin:install/uninstall`).
2. **Phase 2** — itemtypes (`SupplierConfig`, `DomainState`, `ImportedRecord`, `ImportLock`), Supplier tab + encryption, `ns-providers.json` (3 supported providers, sourced) + `NsProviderRegistry`.
3. **Phase 3** — contracts, DTOs, `DriverFactory`, `CloudflareDriver` (full), IONOS/Dinahosting stubs, `SyncEngine`, `RecordReconciler`, `LockEnforcer`, history/logging.
4. **Phase 4** — `DomainForm` injections, status card, `SyncController` + Update Now JS, cron batching loop.
5. **Phase 5** — registry sweep of popular DNS providers (researched patterns + sources documented in the JSON).

Every phase leaves install → uninstall residue-free.

---

*Open items awaiting your approval: the four deviations in §0.1–§0.4 (Registrar as plugin field, `date_domaincreation` mapping, plugin-owned lock layer replacing native `Lockedfield`, documented `managed_domainrecordtypes` gate on web-triggered record writes) and the CREATE TABLE exception in §0.6.*
