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
use GlpiPlugin\Domainmanager\Dto\ConnectionTestResult;
use Log;
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
     * Historical-tab lines queued by prepareDriverAndCredentials(), flushed
     * to the owning Supplier's native History tab in post_addItem()/
     * post_updateItem() (§3.7)
     *
     * @var string[]
     */
    private array $pendingHistoryLines = [];

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
     * Whether $suppliers_id's native "Active" field is set — an inactive
     * supplier is retired and its Domain Manager credentials must never be
     * used for an outbound API call (connection test, sync, cron, massive
     * action). A supplier that can't be loaded is treated as active: this
     * check exists to gate active-but-retired suppliers, not dangling FKs.
     *
     * @param  int $suppliers_id
     * @return bool
     */
    public static function isSupplierActive(int $suppliers_id): bool
    {
        $supplier = new Supplier();
        if ($suppliers_id <= 0 || !$supplier->getFromDB($suppliers_id)) {
            return true;
        }

        return (bool) $supplier->fields['is_active'];
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
     * Driver keys already claimed by a supplier other than $suppliers_id —
     * each driver may only ever be assigned to one supplier at a time.
     * 'none' is never "claimed" (it's not a real driver, §addendum).
     *
     * @param  int $suppliers_id supplier to exclude from the check (0 = none)
     * @return string[]
     */
    public static function getDriversClaimedByOtherSuppliers(int $suppliers_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'SELECT' => 'api_driver',
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'NOT'        => ['suppliers_id' => $suppliers_id],
                'api_driver' => ['<>', DriverRegistry::DRIVER_NONE],
            ],
        ]);

        $claimed = [];
        foreach ($iterator as $row) {
            $claimed[] = (string) $row['api_driver'];
        }

        return $claimed;
    }

    /**
     * @param  string $driver
     * @param  int    $suppliers_id supplier to exclude from the check (0 = none)
     * @return bool
     */
    public static function isDriverClaimedByOtherSupplier(string $driver, int $suppliers_id): bool
    {
        if ($driver === DriverRegistry::DRIVER_NONE) {
            return false;
        }

        return in_array($driver, self::getDriversClaimedByOtherSuppliers($suppliers_id), true);
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

        return $this->prepareDriverAndCredentials(
            $input,
            [],
            DriverRegistry::DRIVER_NONE,
            [],
            (int) $input['suppliers_id']
        );
    }

    /**
     * {@inheritDoc}
     */
    public function prepareInputForUpdate($input)
    {
        $suppliers_id = (int) ($this->fields['suppliers_id'] ?? 0);

        // The supplier link is immutable
        unset($input['suppliers_id']);

        // Only a real credentials-form submission includes `api_driver`
        // (supplier_tab.html.twig always sends it). Any other partial
        // update — e.g. recordConnectionTestResults(), which only touches
        // the *_test_* columns — must leave driver/credentials completely
        // untouched. Without this guard, `$input['api_driver'] ?? ''`
        // silently evaluated to '' below, which never matched the stored
        // driver, so every such update was misread as "switch to no
        // driver" and wiped the encrypted credentials as a side effect.
        if (!array_key_exists('api_driver', $input)) {
            return $input;
        }

        $old_driver      = (string) ($this->fields['api_driver'] ?? DriverRegistry::DRIVER_NONE);
        $old_credentials = $this->getDecryptedCredentials();

        $stored = [];
        if ($old_driver === $input['api_driver']) {
            // Same driver: empty submitted secrets keep their stored value
            $stored = $old_credentials;
        }

        return $this->prepareDriverAndCredentials($input, $stored, $old_driver, $old_credentials, $suppliers_id);
    }

    /**
     * Validate the driver and turn submitted credential fields into the
     * encrypted api_credentials JSON payload; also queues secret-free
     * Historical-tab lines describing what changed (§3.7)
     *
     * @param  array                $input
     * @param  array                $stored          decrypted credentials to fall back to on empty submit
     * @param  string               $old_driver      driver before this save ('none' on add)
     * @param  array<string,string> $old_credentials decrypted credentials before this save ([] on add)
     * @param  int                  $suppliers_id    supplier this config row belongs/will belong to
     * @return array|false
     */
    private function prepareDriverAndCredentials(
        array $input,
        array $stored,
        string $old_driver,
        array $old_credentials,
        int $suppliers_id
    ): array|false {
        $driver = (string) ($input['api_driver'] ?? DriverRegistry::DRIVER_NONE);
        if (!DriverRegistry::isValidDriver($driver)) {
            Session::addMessageAfterRedirect(
                __s('Invalid API driver', 'domainmanager'),
                false,
                ERROR
            );
            return false;
        }

        // A driver may only ever be assigned to one supplier at a time —
        // re-checked here server-side (not just hidden from the dropdown,
        // SupplierTab::getDriverOptions()) since a direct POST could still
        // submit a driver claimed elsewhere.
        if (self::isDriverClaimedByOtherSupplier($driver, $suppliers_id)) {
            Session::addMessageAfterRedirect(
                sprintf(
                    __s('%s is already assigned to another supplier', 'domainmanager'),
                    DriverRegistry::getDriverLabels()[$driver] ?? $driver
                ),
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

        $this->pendingHistoryLines = $this->buildHistoryLines($old_driver, $old_credentials, $driver, $credentials);

        return $input;
    }

    /**
     * Build human-readable, secret-free Historical-tab lines describing what
     * changed between the old and new driver/credentials state. Never
     * includes actual credential values — only which named field changed.
     *
     * @param  string               $old_driver
     * @param  array<string,string> $old_credentials
     * @param  string               $new_driver
     * @param  array<string,string> $new_credentials
     * @return string[]
     */
    private function buildHistoryLines(string $old_driver, array $old_credentials, string $new_driver, array $new_credentials): array
    {
        $labels = DriverRegistry::getDriverLabels();
        $lines  = [];

        if ($old_driver !== $new_driver) {
            if ($old_driver === DriverRegistry::DRIVER_NONE) {
                $lines[] = sprintf(__('API driver set to %s', 'domainmanager'), $labels[$new_driver] ?? $new_driver);
            } else {
                $lines[] = sprintf(
                    __('API driver changed from %1$s to %2$s', 'domainmanager'),
                    $labels[$old_driver] ?? $old_driver,
                    $labels[$new_driver] ?? $new_driver
                );
            }
            foreach (DriverRegistry::getCredentialFields($new_driver) as $name => $meta) {
                if (($new_credentials[$name] ?? '') !== '') {
                    $lines[] = sprintf(__('%s set', 'domainmanager'), $meta['label']);
                }
            }

            return $lines;
        }

        foreach (DriverRegistry::getCredentialFields($new_driver) as $name => $meta) {
            $old = $old_credentials[$name] ?? '';
            $new = $new_credentials[$name] ?? '';
            if ($old === $new) {
                continue;
            }
            if ($old === '' && $new !== '') {
                $lines[] = sprintf(__('%s set', 'domainmanager'), $meta['label']);
            } elseif ($old !== '' && $new === '') {
                $lines[] = sprintf(__('%s cleared', 'domainmanager'), $meta['label']);
            } else {
                $lines[] = sprintf(__('%s updated', 'domainmanager'), $meta['label']);
            }
        }

        return $lines;
    }

    /**
     * Flush any Historical-tab lines queued by buildHistoryLines() to the
     * owning Supplier's native History tab (Log::history, glpi_logs) —
     * SupplierConfig has no visible tab of its own, so entries are attributed
     * to the Supplier the same way SyncLogger attributes sync outcomes to
     * Domain (§3.7)
     *
     * @return void
     */
    private function flushHistoryLines(): void
    {
        $suppliers_id = (int) ($this->fields['suppliers_id'] ?? 0);
        if ($suppliers_id <= 0 || $this->pendingHistoryLines === []) {
            $this->pendingHistoryLines = [];
            return;
        }

        foreach ($this->pendingHistoryLines as $line) {
            Log::history($suppliers_id, Supplier::class, [PLUGIN_DOMAINMANAGER_SO_SUPPLIER, '', $line]);
        }
        $this->pendingHistoryLines = [];
    }

    /**
     * {@inheritDoc}
     */
    public function post_addItem()
    {
        parent::post_addItem();
        $this->flushHistoryLines();
    }

    /**
     * {@inheritDoc}
     */
    public function post_updateItem($history = true)
    {
        parent::post_updateItem($history);
        $this->flushHistoryLines();
    }

    /**
     * {@inheritDoc}
     */
    public function post_purgeItem()
    {
        $driver       = (string) ($this->fields['api_driver'] ?? DriverRegistry::DRIVER_NONE);
        $suppliers_id = (int) ($this->fields['suppliers_id'] ?? 0);
        if ($suppliers_id > 0) {
            Log::history(
                $suppliers_id,
                Supplier::class,
                [
                    PLUGIN_DOMAINMANAGER_SO_SUPPLIER,
                    '',
                    sprintf(__('API configuration removed (was %s)', 'domainmanager'), DriverRegistry::getDriverLabels()[$driver] ?? $driver),
                ]
            );
        }
        parent::post_purgeItem();
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

    /**
     * Persist connection-test results (§3.5); no-op when this config row
     * isn't saved yet (unsaved/new credentials are tested but never stored)
     *
     * @param  array<string, ConnectionTestResult> $resultsByCapability keyed by 'registrar'/'dns'
     * @return void
     */
    public function recordConnectionTestResults(array $resultsByCapability): void
    {
        if ((int) $this->getID() <= 0) {
            return;
        }

        $input = ['id' => $this->getID()];
        foreach ($resultsByCapability as $capability => $result) {
            if (!in_array($capability, ['registrar', 'dns'], true)) {
                continue;
            }

            $input["{$capability}_test_status"]    = $result->status->value;
            $input["{$capability}_test_message"]   = $result->userMessage;
            $input["{$capability}_test_http_code"] = $result->httpStatusCode;
            $input["{$capability}_test_date"]      = $result->checkedAt->format('Y-m-d H:i:s');
        }

        if (count($input) > 1) {
            $this->update($input);
        }
    }

    /**
     * Connection-test summary for the supplier tab detail panel (§3.5);
     * status defaults to 'never' when no test has run yet or the row
     * doesn't exist
     *
     * @return array<string, array{status: string, message: string, http_code: ?int, date: ?string}>
     */
    public function getConnectionTestSummary(): array
    {
        $summary = [];
        foreach (['registrar', 'dns'] as $capability) {
            $status = (string) ($this->fields["{$capability}_test_status"] ?? '');
            $summary[$capability] = [
                'status'    => $status !== '' ? $status : 'never',
                'message'   => (string) ($this->fields["{$capability}_test_message"] ?? ''),
                'http_code' => isset($this->fields["{$capability}_test_http_code"])
                    ? (int) $this->fields["{$capability}_test_http_code"]
                    : null,
                'date'      => $this->fields["{$capability}_test_date"] ?? null,
            ];
        }

        return $summary;
    }
}
