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
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string|array
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
            'domaintypes_id'       => self::getDomainTypeId(),
            'can_edit'             => Session::haveRight(self::$rightname, UPDATE),
            'rdap_enrichment'      => DomainState::getRdapEnrichmentStatus(),
        ]);

        return true;
    }
}
