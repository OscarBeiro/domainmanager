# Changelog for Domain Manager

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
### Fixed
- The native History ("Historical" tab) entries added by Phase 3.7 rendered with a blank "field" column: `Log::history()`'s generic `id_search_option = 0` never matches a real search option (verified against `src/Log.php` on `11.0/bugfixes`). Fixed by registering a real, non-functional "Domain Manager" search option per itemtype (`plugin_domainmanager_getAddSearchOptionsNew()`, `PLUGIN_DOMAINMANAGER_SO_SUPPLIER`/`_SO_DOMAIN` in `setup.php`) bound to each itemtype's own `name` column — used only as `id_search_option` in every `Log::history()` call, so the field column now reads "Domain Manager" and message text no longer needs its own `"[Domain Manager] "` prefix. Accepted side effect: "Domain Manager" also appears as a normal, selectable (non-functional) column/filter in Supplier's and Domain's Search UI; the chosen IDs still need a collision check against the target instance via `tools/getsearchoptions.php`.

### Added
- `search-options-registry.json` (repo root): the TICGAL-wide ledger of search-option IDs any TICGAL plugin registers, keyed by plugin then itemtype, to keep collisions mechanically checkable between TICGAL's own plugins — seeded with Domain Manager's two entries (`9401`/`9402`, reserved block `9400-9409`). Does not and cannot cover third-party/public plugins; that still requires the live-instance `tools/getsearchoptions.php` check.

## [0.1.2] - 2026-07-19
### Fixed
- `PluginLogger` (`domainmanager.log`/`domainmanager-errors.log`) silently wrote nothing on a stock install: `Toolbox::logInFile()` only writes when `$CFG_GLPI['use_log_in_files']` is set (verified absent from this GLPI 11 branch's default config) or `$force=true` is passed — both calls now pass `true` explicitly.
- A failure while persisting connection-test results (`SupplierConfig::recordConnectionTestResults()`) could break the whole `/connectiontest` response with no client-side detail; the call is now wrapped so a persistence failure only gets logged, never blocks the user from seeing their pass/fail result. The Check Connection button's fetch handling was rewritten to always surface the actual HTTP status and error detail instead of a bare generic "Request failed" message.

### Added
- Phase 3.7: native GLPI History audit trail. `SupplierConfig` now logs driver/credential changes to the owning **Supplier's** own Historical tab (it has no visible tab of its own) — "API driver set/changed", "<Field> set/updated/cleared" per credential field, "API configuration removed" on purge — always secret-free (field names only, never values). `HookHandler::persistRegistrar()` now logs Domain registrar-supplier assignment changes ("set"/"changed"/"cleared") to the Domain's own Historical tab, only when the value actually changes.
- Phase 3.5: on-demand connection diagnostics. New `ConnectionTestableInterface`/`ConnectionTestResult`/`ConnectionTestStatus` (per-capability status, HTTP code, safe user message; `rawDetail` is log-only and never serialized) and `ConnectionTestController` (`POST /plugins/domainmanager/connectiontest/{suppliers_id}`, supplier UPDATE entity-aware + core CSRF) which tests the **current live form values** (not just saved credentials) — empty secret fields fall back to the stored value for the same driver. Cloudflare reports only its `dns` capability (no domain-independent registrar endpoint exists to probe); IONOS/Dinahosting stubs report both capabilities as not-implemented. Results persist to 8 new `supplierconfigs` columns (`{registrar,dns}_test_{status,message,http_code,date}`, added via `Migration::addField()`) only when the supplier config is already saved — testing brand-new unsaved credentials never creates a row. The supplier tab now shows a two-column layout: the credentials form plus an always-rendered connection-diagnostics detail panel (color-coded per-capability badges, message, HTTP code, last-checked timestamp), with a "Check Connection" button firing native GLPI toasts (`glpi_toast_success`/`_warning`/`_error`, verified against `js/glpi_dialog.js` on `11.0/bugfixes`). This replaces the earlier flat, stored-credentials-only "Test credentials" button/`SupplierConfigTestController`, which never shipped.
- All plugin logging (sync engine, cron, NS registry, drivers, connection tests) consolidated onto two files via a new `PluginLogger` service: `domainmanager.log` (activity trail, every attempt) and `domainmanager-errors.log` (errors only, with redacted technical detail) — replacing the previous single `plugin_domainmanager` log channel. Both are automatically visible in Setup → Logs with no registration step (`Glpi\System\Log\LogParser::getLogsFilesList()` enumerates `GLPI_LOG_DIR` generically by `*.log`, verified on `11.0/bugfixes`).

## [0.1.1] - 2026-07-19
Version bump only — no functional changes; superseded by 0.1.2 before any separate release.

## [0.1.0] - 2026-07-19
### Fixed
- The supplier "Domain Manager" tab was gated by the `config` right, which the profile UI does not expose as READ, so the tab never appeared for regular profiles: gating realigned to native supplier rights — tab visible with supplier READ, credentials editable/savable only with entity-aware supplier UPDATE (`SupplierTab` + `SupplierConfig::can*`/`can*Item`).
- Registrar supplier changes on an existing domain were lost when no other field was modified: persistence moved from the `item_update` hook (which core skips when no `glpi_domains` column changed) to `pre_item_update`.
- The domain panel now builds the "Update Now" URL from the named Symfony route (Twig `path()`) instead of the deprecated `Plugin::getWebDir()`, which logged a deprecation on every form render and produced a wrong base path on marketplace installs.

### Changed
- Every Domain Manager mention now carries the Tabler *world-cog* icon: `getIcon()` (`ti ti-world-cog`) added to the Supplier tab, Profile tab and the four plugin itemtypes (picked up by `createTabEntry()`), the domain panel header switched from `ti-world-www`, and the supplier "Domain Manager API access" card title gained the icon.

### Added
- Phase 5 (batch 1): eight detection-only providers appended to the NS registry — AWS Route 53, Google Cloud DNS, Azure DNS, GoDaddy, OVHcloud, DigitalOcean, Linode (Akamai), Vercel — each with narrow nameserver patterns and the official vendor documentation URL as source; matched domains render the "not currently supported" warning with the contribution link.
- Phase 4: Domain form panel (`post_item_form`): Registrar supplier dropdown persisted to the plugin state table, detected DNS provider display, status card with per-pipeline badges and messages, unsupported/unknown provider warning with contribution link, and cosmetic lock JS disabling synced fields for users without the unlock right.
- Phase 4: "Update Now" — `POST /plugins/domainmanager/sync/{id}` Symfony controller (domain UPDATE + entity-aware check, core CSRF enforcement) running the sync synchronously and refreshing the panel badges in place.
- Phase 4: `DomainSync` automatic action now loops active, non-deleted, non-template domains (least-recently-synced first, batch size from the task parameter) with per-domain error isolation.
- Phase 3: driver contracts (`RegistrarDriverInterface`, `DnsPipelineInterface`), validated DTOs (`DomainLifecycle`, `ZoneRecord`), `DriverFactory`, full `CloudflareDriver` (Registrar lifecycle + paginated DNS records via GLPI's proxied HTTP client) and IONOS/Dinahosting stubs.
- Phase 3: `SyncEngine` split pipeline (isolated registrar/DNS legs, per-domain state upsert, §0.4 manageable-record-types gate detection), `NsResolver`, `RecordReconciler` (idempotent import, in-place updates, stale flagging with comment marker, restore on reappearance) and `SyncLogger` (history milestones + plugin log channel).
- Phase 3: `LockEnforcer` — synced domain fields and imported records are shielded server-side from users without `domainmanager:unlock_imported` (forms, massive actions and API alike); domain/record purge cascades.
- Phase 2: itemtypes for the plugin tables (`SupplierConfig`, `DomainState`, `ImportedRecord`, `ImportLock`).
- Phase 2: "Domain Manager" tab on suppliers with API driver select (none/Cloudflare/IONOS/Dinahosting) and per-driver credential fields; credentials stored GLPIKey-encrypted, secrets never echoed back, empty submit keeps the stored secret; gated by config READ/UPDATE.
- Phase 2: `resources/ns-providers.json` NS host → DNS provider registry (sourced patterns for the three supported providers) with `NsProviderRegistry` wildcard matcher.
- Phase 2: supplier purge cascade (configuration row removed, sync states detached) and `TESTING.md` regression checklist.
- Phase 1 foundation: plugin skeleton for GLPI 11.0.x (`setup.php`, `hook.php`, `GlpiPlugin\Domainmanager` PSR-4 namespace under `src/`).
- Installer creating the plugin tables (`supplierconfigs`, `states`, `records`, `locks`), seeding the "Internet Domain" domain type and the A/AAAA/CNAME/MX/NS/TXT record types.
- `domainmanager:unlock_imported` profile right with a rights matrix in a "Domain Manager" profile tab (granted by default to profiles with config UPDATE).
- `DomainSync` automatic action shell (daily, batch size 20, tunable in Setup → Automatic actions).
- Residue-free uninstall (drops plugin tables, unregisters the automatic action, deletes profile rights and display preferences).
