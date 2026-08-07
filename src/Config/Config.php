<?php

/**
 * -------------------------------------------------------------------------
 * Domain Manager plugin for GLPI
 * Copyright (C) 2026 by the TICGAL Team.
 * https://www.tic.gal
 * -------------------------------------------------------------------------
 * LICENSE
 * This file is part of the Domain Manager plugin.
 * Domain Manager plugin is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 * Domain Manager plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with Domain Manager. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @package   domainmanager
 * @author    the TICGAL team
 * @copyright Copyright (c) 2026 TICGAL team
 * @license   AGPL License 3.0 or (at your option) any later version
 *            http://www.gnu.org/licenses/agpl-3.0-standalone.html
 * @link      https://www.tic.gal
 * @since     2026
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Domainmanager\Config;

use CommonGLPI;
use Config as CoreConfig;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Domainmanager\DomainState;
use Session;

/**
 * Plugin-wide configuration (§9 Phase 12), stored in GLPI's native config
 * store under the `plugin:domainmanager` context — no custom table.
 * Registered as a tab on core's `Config` itemtype (Setup > General), same
 * pattern as the sibling `uxia` plugin's `Config\Config`.
 */
final class Config extends CommonGLPI
{
    public const CONTEXT = 'plugin:domainmanager';

    public static $rightname = 'config';

    /**
     * {@inheritDoc}
     */
    public static function getTypeName($nb = 0): string
    {
        return __('Domain Manager', 'domainmanager');
    }

    /**
     * {@inheritDoc}
     */
    public static function getIcon()
    {
        return 'ti ti-world-cog';
    }

    /**
     * @return array<string, int>
     */
    public static function getDefaults(): array
    {
        return [
            // 0 = unset ("-----"), same convention as the native dropdown.
            'domaintypes_id' => 0,
            // §0.10: explicit int, never a raw PHP bool. 0 = writes allowed
            // (default) — a fresh install must never come up read-only.
            'read_only_mode' => 0,
            // ARCHITECTURE.md §15.3 Phase 55: sync safety guard on
            // reconciliation. A single sync run is refused (and the domain
            // left untouched) once it would trash more than this many
            // records, OR more than sync_safety_max_percent of the
            // domain's currently-owned records — whichever trips first.
            // Conservative defaults: a genuinely emptied zone is rare, a
            // wrongly-scoped credential or truncated upstream page is not.
            'sync_safety_max_count'   => 20,
            'sync_safety_max_percent' => 50,
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function getConfig(): array
    {
        return array_merge(
            self::getDefaults(),
            CoreConfig::getConfigurationValues(self::CONTEXT),
        );
    }

    public static function getDomainTypeId(): int
    {
        return (int) self::getConfig()['domaintypes_id'];
    }

    public static function setDomainTypeId(int $domaintypes_id): void
    {
        CoreConfig::setConfigurationValues(self::CONTEXT, ['domaintypes_id' => $domaintypes_id]);
    }

    /**
     * Global write kill switch (ARCHITECTURE.md §15.3 Phase 53): when
     * enabled, every outbound DNS record mutation is refused, independent of
     * any per-type write-back right (§11.6) — checked at a single point in
     * `DnsRecordWriteback` and asserted again in each driver's own writer
     * methods, so a future call path into a driver can never bypass it.
     * Deactivating a supplier is not a substitute for this — that also kills
     * reads/sync, not just writes.
     *
     * @return bool
     */
    public static function isReadOnlyMode(): bool
    {
        return (bool) (int) self::getConfig()['read_only_mode'];
    }

    public static function setReadOnlyMode(bool $enabled): void
    {
        CoreConfig::setConfigurationValues(self::CONTEXT, ['read_only_mode' => $enabled ? 1 : 0]);
    }

    /**
     * @return int absolute record count above which the Phase 55 sync safety
     *             guard refuses a reconciliation run
     */
    public static function getSyncSafetyMaxCount(): int
    {
        return (int) self::getConfig()['sync_safety_max_count'];
    }

    public static function setSyncSafetyMaxCount(int $max_count): void
    {
        CoreConfig::setConfigurationValues(self::CONTEXT, ['sync_safety_max_count' => max(0, $max_count)]);
    }

    /**
     * @return int percentage (0-100) of a domain's owned records above which
     *             the Phase 55 sync safety guard refuses a reconciliation run
     */
    public static function getSyncSafetyMaxPercent(): int
    {
        return (int) self::getConfig()['sync_safety_max_percent'];
    }

    public static function setSyncSafetyMaxPercent(int $max_percent): void
    {
        CoreConfig::setConfigurationValues(self::CONTEXT, ['sync_safety_max_percent' => max(0, min(100, $max_percent))]);
    }

    /**
     * Defense in depth for Phase 53's kill switch: every driver's own
     * createRecord()/updateRecord()/deleteRecord()/setProxied()/
     * pushComment() calls this first, so a future call path into a driver
     * that bypasses `DnsRecordWriteback`'s own check entirely (e.g. a new
     * controller) still can't push a live mutation while read-only mode is
     * on.
     *
     * @throws \GlpiPlugin\Domainmanager\Exception\DriverException
     */
    public static function assertWritesAllowed(): void
    {
        if (self::isReadOnlyMode()) {
            throw new \GlpiPlugin\Domainmanager\Exception\DriverException(
                __('Domain Manager is in read-only mode (Setup > General > Domain Manager); no DNS record change can be pushed to the provider', 'domainmanager'),
            );
        }
    }

    /**
     * @return bool whether an explicit value has ever been stored (a fresh,
     *              never-configured install has none — distinct from a
     *              stored, explicit `0`/"unset")
     */
    public static function isConfigured(): bool
    {
        return array_key_exists('domaintypes_id', CoreConfig::getConfigurationValues(self::CONTEXT));
    }

    /**
     * Seed the config value once, at install (idempotent — never overwrites
     * an admin's later choice, including an explicit "unset").
     *
     * @param  int $domaintypes_id
     * @return void
     */
    public static function seedDefault(int $domaintypes_id): void
    {
        if (!self::isConfigured()) {
            self::setDomainTypeId($domaintypes_id);
        }
    }

    /**
     * Purge the config context (uninstall)
     *
     * @return void
     */
    public static function uninstall(): void
    {
        $values = CoreConfig::getConfigurationValues(self::CONTEXT);
        if ($values !== []) {
            CoreConfig::deleteConfigurationValues(self::CONTEXT, array_keys($values));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof CoreConfig && Session::haveRight(self::$rightname, UPDATE)) {
            return self::createTabEntry(self::getTypeName());
        }

        return '';
    }

    /**
     * {@inheritDoc}
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof CoreConfig) {
            return self::showConfigForm();
        }

        return false;
    }

    /**
     * Render the configuration form.
     *
     * @return bool
     */
    public static function showConfigForm(): bool
    {
        TemplateRenderer::getInstance()->display('@domainmanager/config.html.twig', [
            'domaintypes_id'           => self::getDomainTypeId(),
            'read_only_mode'           => self::isReadOnlyMode(),
            'sync_safety_max_count'   => self::getSyncSafetyMaxCount(),
            'sync_safety_max_percent' => self::getSyncSafetyMaxPercent(),
            'can_edit'             => Session::haveRight(self::$rightname, UPDATE),
            'rdap_enrichment'      => DomainState::getRdapEnrichmentStatus(),
        ]);

        return true;
    }
}
