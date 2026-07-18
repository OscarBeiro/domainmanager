# Domain Manager

GLPI plugin providing a read-only inventory of domain lifecycles (registrar pipeline) and DNS zone records (DNS provider pipeline), with hardcoded drivers for Cloudflare, IONOS and Dinahosting.

![Version](https://img.shields.io/github/v/release/TICGAL-GLPI-Plugins/domainmanager)
![License](https://img.shields.io/github/license/TICGAL-GLPI-Plugins/domainmanager)
![Issues](https://img.shields.io/github/issues/TICGAL-GLPI-Plugins/domainmanager)
![Pull Requests](https://img.shields.io/github/issues-pr/TICGAL-GLPI-Plugins/domainmanager)
![Last Commit](https://img.shields.io/github/last-commit/TICGAL-GLPI-Plugins/domainmanager)
![Project Status](https://img.shields.io/badge/status-active-brightgreen)

Requires **GLPI 11.0.x**.

## Features
- Daily automatic action (`DomainSync`, tunable in *Setup → Automatic actions*) that synchronizes domain lifecycle data and DNS zone records — engine arrives in upcoming phases.
- Dedicated profile right (`domainmanager:unlock_imported`) controlling who may edit data imported by synchronization, manageable from a **Domain Manager** tab on each profile.
- Clean install/uninstall from the GLPI UI or `bin/console glpi:plugin:install|uninstall` — uninstall leaves no plugin residue while keeping native inventory data.

## Installation
1. Copy this directory to `plugins/domainmanager` of your GLPI 11 instance.
2. Install and enable it from *Setup → Plugins* (or `php bin/console glpi:plugin:install domainmanager && php bin/console glpi:plugin:activate domainmanager`).

## Configuration
- Grant or revoke the *Unlock imported domain data* right per profile in the **Domain Manager** tab of each profile (granted by default to profiles with *config* UPDATE).
- Supplier API credentials and provider drivers arrive in the next phases (see `ARCHITECTURE.md`).

## Use
Nothing user-facing yet beyond rights and the scheduled action shell; the sync pipelines, supplier credentials tab and domain form panel are delivered in later phases described in `ARCHITECTURE.md`.
