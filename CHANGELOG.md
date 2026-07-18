# Changelog for Domain Manager

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
### Added
- Phase 2: itemtypes for the plugin tables (`SupplierConfig`, `DomainState`, `ImportedRecord`, `ImportLock`).
- Phase 2: "Domain Manager" tab on suppliers with API driver select (none/Cloudflare/IONOS/Dinahosting) and per-driver credential fields; credentials stored GLPIKey-encrypted, secrets never echoed back, empty submit keeps the stored secret; gated by config READ/UPDATE.
- Phase 2: `resources/ns-providers.json` NS host → DNS provider registry (sourced patterns for the three supported providers) with `NsProviderRegistry` wildcard matcher.
- Phase 2: supplier purge cascade (configuration row removed, sync states detached) and `TESTING.md` regression checklist.
- Phase 1 foundation: plugin skeleton for GLPI 11.0.x (`setup.php`, `hook.php`, `GlpiPlugin\Domainmanager` PSR-4 namespace under `src/`).
- Installer creating the plugin tables (`supplierconfigs`, `states`, `records`, `locks`), seeding the "Internet Domain" domain type and the A/AAAA/CNAME/MX/NS/TXT record types.
- `domainmanager:unlock_imported` profile right with a rights matrix in a "Domain Manager" profile tab (granted by default to profiles with config UPDATE).
- `DomainSync` automatic action shell (daily, batch size 20, tunable in Setup → Automatic actions).
- Residue-free uninstall (drops plugin tables, unregisters the automatic action, deletes profile rights and display preferences).
