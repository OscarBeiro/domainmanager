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

namespace GlpiPlugin\Domainmanager;

use CommonDBTM;
use GLPIKey;
use Session;
use Supplier;

/**
 * Per-supplier API driver and encrypted credentials
 * (glpi_plugin_domainmanager_supplierconfigs, one row per supplier)
 */
class SupplierConfig extends CommonDBTM
{
    // Supplier::$rightname — API access is part of managing the supplier itself
    public static $rightname = 'contact_enterprise';

    /**
     * {@inheritDoc}
     */
    public static function getTypeName($nb = 0)
    {
        return __('Domain Manager supplier configuration', 'domainmanager');
    }

    /**
     * {@inheritDoc}
     */
    public static function getIcon()
    {
        return 'ti ti-world-cog';
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(self::$rightname, UPDATE);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(self::$rightname, UPDATE);
    }

    public static function canPurge(): bool
    {
        return Session::haveRight(self::$rightname, UPDATE);
    }

    /**
     * Writing a supplier's API access requires UPDATE on that supplier
     * (entity-aware), not only the global right
     *
     * @param  int $suppliers_id
     * @return bool
     */
    private static function canEditSupplier(int $suppliers_id): bool
    {
        $supplier = new Supplier();

        return $suppliers_id > 0 && $supplier->can($suppliers_id, UPDATE);
    }

    /**
     * {@inheritDoc}
     */
    public function canCreateItem(): bool
    {
        return self::canEditSupplier((int) ($this->input['suppliers_id'] ?? 0));
    }

    /**
     * {@inheritDoc}
     */
    public function canUpdateItem(): bool
    {
        return self::canEditSupplier((int) $this->fields['suppliers_id']);
    }

    /**
     * {@inheritDoc}
     */
    public function canPurgeItem(): bool
    {
        return self::canEditSupplier((int) $this->fields['suppliers_id']);
    }

    /**
     * Always return to the referring page (the supplier tab) after add/update
     *
     * {@inheritDoc}
     */
    public static function getPostFormAction(string $form_action, bool $action_success): ?string
    {
        if (in_array($form_action, ['add', 'update'], true)) {
            return 'back';
        }

        return parent::getPostFormAction($form_action, $action_success);
    }

    /**
     * Get the configuration row of a supplier
     *
     * @param  int $suppliers_id
     * @return self|null
     */
    public static function getForSupplier(int $suppliers_id): ?self
    {
        $config = new self();
        if ($suppliers_id > 0 && $config->getFromDBByCrit(['suppliers_id' => $suppliers_id])) {
            return $config;
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function prepareInputForAdd($input)
    {
        if (empty($input['suppliers_id']) || (int) $input['suppliers_id'] <= 0) {
            Session::addMessageAfterRedirect(
                __s('A supplier is mandatory', 'domainmanager'),
                false,
                ERROR
            );
            return false;
        }

        return $this->prepareDriverAndCredentials($input, []);
    }

    /**
     * {@inheritDoc}
     */
    public function prepareInputForUpdate($input)
    {
        // The supplier link is immutable
        unset($input['suppliers_id']);

        $stored = [];
        if (($this->fields['api_driver'] ?? '') === ($input['api_driver'] ?? '')) {
            // Same driver: empty submitted secrets keep their stored value
            $stored = $this->getDecryptedCredentials();
        }

        return $this->prepareDriverAndCredentials($input, $stored);
    }

    /**
     * Validate the driver and turn submitted credential fields into the
     * encrypted api_credentials JSON payload
     *
     * @param  array $input
     * @param  array $stored decrypted credentials to fall back to on empty submit
     * @return array|false
     */
    private function prepareDriverAndCredentials(array $input, array $stored): array|false
    {
        $driver = (string) ($input['api_driver'] ?? DriverRegistry::DRIVER_NONE);
        if (!DriverRegistry::isValidDriver($driver)) {
            Session::addMessageAfterRedirect(
                __s('Invalid API driver', 'domainmanager'),
                false,
                ERROR
            );
            return false;
        }

        $submitted = $input['_credentials'] ?? [];
        if (!is_array($submitted)) {
            $submitted = [];
        }
        unset($input['_credentials']);

        $credentials = [];
        foreach (array_keys(DriverRegistry::getCredentialFields($driver)) as $name) {
            $value = trim((string) ($submitted[$name] ?? ''));
            if ($value !== '') {
                $credentials[$name] = $value;
            } elseif (isset($stored[$name]) && $stored[$name] !== '') {
                $credentials[$name] = $stored[$name];
            }
        }

        $input['api_credentials'] = $credentials === []
            ? ''
            : (new GLPIKey())->encrypt(json_encode($credentials));

        return $input;
    }

    /**
     * Decrypted credentials of the stored driver
     *
     * @return array<string, string>
     */
    public function getDecryptedCredentials(): array
    {
        $encrypted = (string) ($this->fields['api_credentials'] ?? '');
        if ($encrypted === '') {
            return [];
        }

        $decrypted = (new GLPIKey())->decrypt($encrypted);
        if ($decrypted === null || $decrypted === '') {
            return [];
        }

        $credentials = json_decode($decrypted, true);

        return is_array($credentials) ? $credentials : [];
    }

    /**
     * Which credential fields of the stored driver have a saved value
     * (used by the tab to show "saved" placeholders without echoing secrets)
     *
     * @return array<string, bool>
     */
    public function getSavedCredentialFlags(): array
    {
        $credentials = $this->getDecryptedCredentials();

        $flags = [];
        foreach (array_keys(DriverRegistry::getCredentialFields((string) $this->fields['api_driver'])) as $name) {
            $flags[$name] = isset($credentials[$name]) && $credentials[$name] !== '';
        }

        return $flags;
    }
}
