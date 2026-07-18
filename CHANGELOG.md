# Changelog for Domain Manager

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
### Added
- Phase 1 foundation: plugin skeleton for GLPI 11.0.x (`setup.php`, `hook.php`, `GlpiPlugin\Domainmanager` PSR-4 namespace under `src/`).
- Installer creating the plugin tables (`supplierconfigs`, `states`, `records`, `locks`), seeding the "Internet Domain" domain type and the A/AAAA/CNAME/MX/NS/TXT record types.
- `domainmanager:unlock_imported` profile right with a rights matrix in a "Domain Manager" profile tab (granted by default to profiles with config UPDATE).
- `DomainSync` automatic action shell (daily, batch size 20, tunable in Setup → Automatic actions).
- Residue-free uninstall (drops plugin tables, unregisters the automatic action, deletes profile rights and display preferences).
