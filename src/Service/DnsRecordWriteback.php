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

namespace GlpiPlugin\Domainmanager\Service;

use Domain;
use DomainRecord;
use DomainRecordType;
use GlpiPlugin\Domainmanager\Contract\DnsRecordWriterInterface;
use GlpiPlugin\Domainmanager\Dto\ZoneRecord;
use GlpiPlugin\Domainmanager\DomainState;
use GlpiPlugin\Domainmanager\DriverFactory;
use GlpiPlugin\Domainmanager\DriverRegistry;
use GlpiPlugin\Domainmanager\Exception\DriverException;
use GlpiPlugin\Domainmanager\ImportedRecord;
use GlpiPlugin\Domainmanager\ImportLock;
use GlpiPlugin\Domainmanager\NsProviderRegistry;
use GlpiPlugin\Domainmanager\Profile;
use GlpiPlugin\Domainmanager\SupplierConfig;
use Log;
use Session;
use Throwable;

/**
 * Native-tab write-back to IONOS (ARCHITECTURE.md §11.7, §11.10, Phase 34b),
 * superseding the removed `DnsRecordWriteController` + confirmation-modal
 * design. Called from `hook.php`'s `pre_item_add`/`item_add`/
 * `pre_item_update`/`pre_item_delete` entries on `DomainRecord` — all logic
 * here is a straight port of what the removed controller did per-action,
 * now running inline in GLPI's own native add/edit/delete flow instead of a
 * plugin-rendered panel.
 */
class DnsRecordWriteback
{
    /**
     * Types this feature may ever write. Same subset as
     * DnsRecordWriterInterface::WRITABLE_TYPES (§11.4) — NS/MX excluded by
     * design.
     */
    private const WRITABLE_TYPES = DnsRecordWriterInterface::WRITABLE_TYPES;

    /**
     * ZoneRecord created by pre_item_add's driver call, stashed for the
     * paired item_add (post) hook to attach ImportedRecord/ImportLock/
     * history once the local row has an id. Keyed by spl_object_id() of the
     * same DomainRecord instance GLPI reuses across both hook calls within
     * one add() — never persisted, never touches a second request.
     *
     * @var array<int, ZoneRecord>
     */
    private static array $pendingCreated = [];

    /**
     * pre_item_add on DomainRecord: push createRecord() to IONOS before the
     * local row exists, aborting the local add on failure so there is never
     * a local row with no corresponding provider record.
     *
     * @param  DomainRecord $item
     * @return void
     */
    public static function onPreAdd(DomainRecord $item): void
    {
        if (!is_array($item->input)) {
            return;
        }

        $type = self::typeName((int) ($item->input['domainrecordtypes_id'] ?? 0));
        if ($type === null || !in_array($type, self::WRITABLE_TYPES, true)) {
            return;
        }

        $domains_id = (int) ($item->input['domains_id'] ?? 0);
        $state = DomainState::getForDomain($domains_id);
        if ($state === null || !self::isDnsEditable($state)) {
            return;
        }

        if (!self::hasRight($type, CREATE) || Session::isCron()) {
            // Not eligible for write-back: either the profile lacks the
            // per-type right, or this is a cron-driven/sync add, which is
            // never a manual write-back push. Native add proceeds untouched.
            return;
        }

        $domain = new Domain();
        if (!$domain->getFromDB($domains_id)) {
            return;
        }

        $preflightError = self::managedTypesPreflight($type);
        if ($preflightError !== null) {
            self::abort($item, $preflightError);
            return;
        }

        $nsError = self::recheckNameservers($domain);
        if ($nsError !== null) {
            self::abort($item, $nsError);
            return;
        }

        $name = (string) ($item->input['name'] ?? '@');
        $data = (string) ($item->input['data'] ?? '');
        $ttl  = (int) ($item->input['ttl'] ?? 3600);
        self::sanitizeInputs($name, $data, $ttl);
        if (!self::validateInputs($data, $ttl)) {
            self::abort($item, __('Invalid record data', 'domainmanager'));
            return;
        }

        try {
            $driver = self::getWritableDriver($state);
            $zoneName = $domain->fields['name'];
            $absoluteName = $zoneName . '.' . (($name !== '' && $name !== '@') ? $name . '.' : '');
            $created = $driver->createRecord($zoneName, $type, $absoluteName, $data, $ttl);
            self::$pendingCreated[spl_object_id($item)] = $created;
        } catch (Throwable $e) {
            $message = $e instanceof DriverException ? $e->getMessage() : __('An error occurred while creating the record at the provider', 'domainmanager');
            PluginLogger::error("Failed to push new DNS record for domain #$domains_id", $e::class . ': ' . $e->getMessage());
            self::abort($item, sprintf(__('Could not create this record at IONOS: %s', 'domainmanager'), $message));
        }
    }

    /**
     * item_add (post) on DomainRecord: the local row now has an id — attach
     * the ImportedRecord ownership row, field locks and Historical line
     * using the ZoneRecord stashed by onPreAdd().
     *
     * @param  DomainRecord $item
     * @return void
     */
    public static function onPostAdd(DomainRecord $item): void
    {
        $key = spl_object_id($item);
        if (!isset(self::$pendingCreated[$key])) {
            return;
        }
        $created = self::$pendingCreated[$key];
        unset(self::$pendingCreated[$key]);

        $records_id = (int) $item->getID();
        $domains_id = (int) $item->fields['domains_id'];
        $type = (int) $item->fields['domainrecordtypes_id'];

        $imported = new ImportedRecord();
        $imported->add([
            'domainrecords_id' => $records_id,
            'domains_id'       => $domains_id,
            'remote_id'        => $created->remoteId,
            'record_hash'      => $created->getHash(),
            'last_seen'        => date('Y-m-d H:i:s'),
            'is_managed'       => 1,
            'is_glpi_created'  => 1,
            'is_proxied'       => $created->isProxied !== null ? (int) $created->isProxied : null,
        ]);

        ImportLock::replaceLocks(DomainRecord::class, $records_id, [
            'name'                 => $item->fields['name'],
            'data'                 => $item->fields['data'],
            'ttl'                  => $item->fields['ttl'],
            'domainrecordtypes_id' => $type,
        ]);

        Log::history($domains_id, Domain::class, [0, '', '[Domain Manager] ' . sprintf(
            __('Record created from GLPI: %s %s → %s (TTL %d)', 'domainmanager'),
            self::typeName($type) ?? '?',
            $item->fields['name'] ?: '@',
            $item->fields['data'],
            (int) $item->fields['ttl'],
        )
        ]);
    }

    /**
     * pre_item_update on DomainRecord: called from
     * `LockEnforcer::domainRecordPreUpdate()` before its own unconditional
     * lock check. Returns true when this record/edit was handled here (the
     * write-back was attempted, whether it aborted the save or is letting
     * it proceed) — the caller must then skip its own lock logic. Returns
     * false when not eligible at all, so the caller falls through to its
     * existing plugin-owned-record lock.
     *
     * @param  DomainRecord $item
     * @return bool
     */
    public static function onPreUpdate(DomainRecord $item): bool
    {
        if (!is_array($item->input)) {
            return false;
        }

        $type = self::typeName((int) $item->fields['domainrecordtypes_id']);
        if ($type === null || !in_array($type, self::WRITABLE_TYPES, true)) {
            return false;
        }

        $domains_id = (int) $item->fields['domains_id'];
        $state = DomainState::getForDomain($domains_id);
        if ($state === null || !self::isDnsEditable($state)) {
            return false;
        }

        if (!self::hasRight($type, UPDATE) || Session::isCron()) {
            return false;
        }

        // Only a data/ttl change is actually pushed; a no-op update (e.g.
        // only unrelated fields submitted) shouldn't hit the provider.
        if (
            !array_key_exists('data', $item->input)
            && !array_key_exists('ttl', $item->input)
        ) {
            return false;
        }

        $domain = new Domain();
        if (!$domain->getFromDB($domains_id)) {
            return false;
        }

        $preflightError = self::managedTypesPreflight($type);
        if ($preflightError !== null) {
            self::abort($item, $preflightError);
            return true;
        }

        $nsError = self::recheckNameservers($domain);
        if ($nsError !== null) {
            self::abort($item, $nsError);
            return true;
        }

        $data = (string) ($item->input['data'] ?? $item->fields['data']);
        $ttl  = (int) ($item->input['ttl'] ?? $item->fields['ttl']);
        $name = (string) $item->fields['name'];
        self::sanitizeInputs($name, $data, $ttl);
        if (!self::validateInputs($data, $ttl)) {
            self::abort($item, __('Invalid record data', 'domainmanager'));
            return true;
        }

        $imported = ImportedRecord::getForDomainRecord((int) $item->getID());
        if ($imported === null || $imported->fields['remote_id'] === '') {
            self::abort($item, __('Record has no remote ID; cannot push to IONOS', 'domainmanager'));
            return true;
        }

        try {
            $driver = self::getWritableDriver($state);

            // Live re-fetch-and-diff before update (§11.10): the local
            // mirror is only as fresh as the last sync.
            try {
                $live = $driver->fetchRecord($domain->fields['name'], $imported->fields['remote_id']);
                if ($live->data !== $item->fields['data'] || $live->ttl !== (int) $item->fields['ttl']) {
                    self::abort($item, __('The record at IONOS has changed since the last sync; refusing to overwrite with stale data. Re-sync and try again.', 'domainmanager'));
                    return true;
                }
            } catch (Throwable) {
                Session::addMessageAfterRedirect(
                    '[Domain Manager] ' . __('Could not verify the current value at IONOS before this update; proceeding with local values.', 'domainmanager'),
                    false,
                    WARNING,
                );
            }

            $driver->updateRecord($domain->fields['name'], $imported->fields['remote_id'], $type, $name, $data, $ttl);

            ImportLock::replaceLocks(DomainRecord::class, (int) $item->getID(), [
                'name'                 => $name,
                'data'                 => $data,
                'ttl'                  => $ttl,
                'domainrecordtypes_id' => (int) $item->fields['domainrecordtypes_id'],
            ]);

            Log::history($domains_id, Domain::class, [0, '', '[Domain Manager] ' . sprintf(
                __('Record updated from GLPI: %s %s — data %s → %s', 'domainmanager'),
                $type,
                $name,
                $item->fields['data'],
                $data,
            )
            ]);

            return true;
        } catch (Throwable $e) {
            $message = $e instanceof DriverException ? $e->getMessage() : __('An error occurred while updating the record at the provider', 'domainmanager');
            PluginLogger::error("Failed to push updated DNS record #{$item->getID()} for domain #$domains_id", $e::class . ': ' . $e->getMessage());
            self::abort($item, sprintf(__('Could not update this record at IONOS: %s', 'domainmanager'), $message));
            return true;
        }
    }

    /**
     * pre_item_delete on DomainRecord (soft-delete, §11.11 — this is the
     * action that pushes the upstream deletion, not the later hard purge).
     * Same return-value convention as onPreUpdate().
     *
     * @param  DomainRecord $item
     * @return bool
     */
    public static function onPreDelete(DomainRecord $item): bool
    {
        $type = self::typeName((int) $item->fields['domainrecordtypes_id']);
        if ($type === null || !in_array($type, self::WRITABLE_TYPES, true)) {
            return false;
        }

        $domains_id = (int) $item->fields['domains_id'];
        $state = DomainState::getForDomain($domains_id);
        if ($state === null || !self::isDnsEditable($state)) {
            return false;
        }

        if (!self::hasRight($type, DELETE) || Session::isCron()) {
            return false;
        }

        $domain = new Domain();
        if (!$domain->getFromDB($domains_id)) {
            return false;
        }

        $nsError = self::recheckNameservers($domain);
        if ($nsError !== null) {
            self::abort($item, $nsError);
            return true;
        }

        $imported = ImportedRecord::getForDomainRecord((int) $item->getID());
        if ($imported === null || $imported->fields['remote_id'] === '') {
            // Nothing to push upstream (never synced/created through the
            // plugin) — let the native soft-delete proceed unmodified.
            return false;
        }

        try {
            $driver = self::getWritableDriver($state);
            $driver->deleteRecord($domain->fields['name'], $imported->fields['remote_id']);

            Log::history($domains_id, Domain::class, [0, '', '[Domain Manager] ' . sprintf(
                __('Record deleted from GLPI: %s %s', 'domainmanager'),
                $type,
                $item->fields['name'],
            )
            ]);

            return true;
        } catch (Throwable $e) {
            $message = $e instanceof DriverException ? $e->getMessage() : __('An error occurred while deleting the record at the provider', 'domainmanager');
            PluginLogger::error("Failed to push deletion of DNS record #{$item->getID()} for domain #$domains_id", $e::class . ': ' . $e->getMessage());
            self::abort($item, sprintf(__('Could not delete this record at IONOS: %s', 'domainmanager'), $message));
            return true;
        }
    }

    /**
     * Cancel the in-progress native operation, mirroring
     * `LockEnforcer::blockRecordRemoval()`'s own convention.
     *
     * @param  DomainRecord $item
     * @param  string       $reason
     * @return void
     */
    private static function abort(DomainRecord $item, string $reason): void
    {
        $item->input = false;
        Session::addMessageAfterRedirect('[Domain Manager] ' . $reason, false, ERROR);
    }

    /**
     * @param  string $type
     * @param  int    $bit CREATE|UPDATE|DELETE
     * @return bool
     */
    private static function hasRight(string $type, int $bit): bool
    {
        $rights = Profile::getDnsRecordRights();
        if (!isset($rights[$type])) {
            return false;
        }
        return Session::haveRight($rights[$type], $bit);
    }

    /**
     * Whether the current user holds the CREATE bit on at least one
     * per-type DNS write-back right (§11.6/§11.7) — used by
     * `DomainForm::onShowTab()` to decide whether the native "Link a
     * record"/"New Domain record for this item" controls are worth
     * showing on an IONOS-managed domain's Records tab: a user who can't
     * create any type via write-back would only get a confusing local-only
     * add that `DnsRecordWriteback::onPreAdd()` may reject outright.
     *
     * @return bool
     */
    public static function userMayCreateAnyType(): bool
    {
        foreach (Profile::getDnsRecordRights() as $type => $right) {
            if (self::hasRight($type, CREATE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Public wrapper around `isDnsEditable()` for callers outside this
     * class (§11.6 addendum) — e.g. `DomainForm::onShowTab()`, deciding
     * whether the domain's DNS is under IONOS write-back at all before
     * even considering hiding the native add controls.
     *
     * @param  DomainState $state
     * @return bool
     */
    public static function isDomainDnsEditable(DomainState $state): bool
    {
        return self::isDnsEditable($state);
    }

    /**
     * Ported from the removed DnsRecordWriteController::isDnsEditable().
     *
     * @param  DomainState $state
     * @return bool
     */
    private static function isDnsEditable(DomainState $state): bool
    {
        $supplier_id = $state->fields['dns_suppliers_id'] ?? 0;
        if ($supplier_id <= 0) {
            return false;
        }

        try {
            $config = SupplierConfig::getForSupplier($supplier_id);
            if ($config === null) {
                return false;
            }
            $driver = DriverFactory::forDns($config);
            return $driver instanceof DnsRecordWriterInterface;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Ported from the removed DnsRecordWriteController::getWritableDriver().
     *
     * @param  DomainState $state
     * @return DnsRecordWriterInterface
     * @throws DriverException
     */
    private static function getWritableDriver(DomainState $state): DnsRecordWriterInterface
    {
        $supplier_id = $state->fields['dns_suppliers_id'] ?? 0;
        $config = SupplierConfig::getForSupplier($supplier_id);
        if ($config === null) {
            throw new DriverException(__('Supplier configuration not found', 'domainmanager'));
        }
        $driver = DriverFactory::forDns($config);
        if (!$driver instanceof DnsRecordWriterInterface) {
            throw new DriverException(__('Driver does not support DNS record write-back', 'domainmanager'));
        }
        return $driver;
    }

    /**
     * §0.4 pre-flight: the acting profile's "manageable domain record
     * types" must include this type, or core's own
     * `DomainRecord::prepareInput()` would silently drop the local write
     * even after a successful provider push. Ported from the removed
     * controller's preflight().
     *
     * @param  string $type
     * @return string|null error message, or null when clear
     */
    private static function managedTypesPreflight(string $type): ?string
    {
        $manageable = $_SESSION['glpiactiveprofile']['managed_domainrecordtypes'] ?? [];
        if (!is_array($manageable)) {
            $manageable = [];
        }

        if ($manageable === [-1]) {
            return null;
        }

        $typeObj = new DomainRecordType();
        if (!$typeObj->getFromDBByCrit(['name' => $type])) {
            return __('Unable to resolve record type', 'domainmanager');
        }

        if (!in_array($typeObj->getID(), $manageable, true)) {
            return sprintf(
                __('Your profile\'s "Manageable domain record types" setting does not include %s', 'domainmanager'),
                $type,
            );
        }

        return null;
    }

    /**
     * Ported from the removed controller's recheckNameservers().
     *
     * @param  Domain $domain
     * @return string|null
     */
    private static function recheckNameservers(Domain $domain): ?string
    {
        try {
            $resolver = new NsResolver();
            $hosts = $resolver->getNameservers($domain->fields['name']);
            $provider = NsProviderRegistry::match($hosts);
            if ($provider === null || ($provider['driver'] ?? null) !== DriverRegistry::DRIVER_IONOS) {
                return __('Domain nameservers have changed since last sync; cannot verify IONOS is still authoritative', 'domainmanager');
            }
        } catch (Throwable) {
            return __('Unable to re-check domain nameservers', 'domainmanager');
        }
        return null;
    }

    /**
     * Ported from the removed controller's sanitizeInputs().
     *
     * @param  string $name
     * @param  string $data
     * @param  int    $ttl
     * @return void
     */
    private static function sanitizeInputs(string &$name, string &$data, int &$ttl): void
    {
        $name = trim($name);
        $data = trim($data);
        $ttl = max(60, min($ttl, 2147483647));
    }

    /**
     * Ported from the removed controller's validateInputs() (the
     * type/name-specific branch was a no-op there — data emptiness alone
     * governed every type — kept identical here).
     *
     * @param  string $data
     * @param  int    $ttl
     * @return bool
     */
    private static function validateInputs(string $data, int $ttl): bool
    {
        return $data !== '' && $ttl >= 60;
    }

    /**
     * @param  int $typeId
     * @return string|null
     */
    private static function typeName(int $typeId): ?string
    {
        if ($typeId <= 0) {
            return null;
        }
        $typeObj = new DomainRecordType();
        if ($typeObj->getFromDB($typeId)) {
            return $typeObj->fields['name'] ?? null;
        }
        return null;
    }
}
