# Changelog for Domain Manager

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
### Added
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
