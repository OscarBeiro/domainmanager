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

namespace GlpiPlugin\Domainmanager\Controller;

use Domain;
use DomainRecord;
use DomainRecordType;
use Glpi\Application\View\TemplateRenderer;
use Glpi\Controller\AbstractController;
use GlpiPlugin\Domainmanager\Contract\DnsRecordWriterInterface;
use GlpiPlugin\Domainmanager\DomainState;
use GlpiPlugin\Domainmanager\DriverFactory;
use GlpiPlugin\Domainmanager\DriverRegistry;
use GlpiPlugin\Domainmanager\Exception\DriverException;
use GlpiPlugin\Domainmanager\ImportedRecord;
use GlpiPlugin\Domainmanager\ImportLock;
use GlpiPlugin\Domainmanager\LockEnforcer;
use GlpiPlugin\Domainmanager\NsProviderRegistry;
use GlpiPlugin\Domainmanager\Service\NsResolver;
use GlpiPlugin\Domainmanager\Service\PluginLogger;
use GlpiPlugin\Domainmanager\SupplierConfig;
use GlpiPlugin\Domainmanager\Dto\ZoneRecord;
use Log;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * DNS record create/update/delete endpoints for IONOS driver (§11, Phases 34–35).
 * URL: POST /plugins/domainmanager/dnsrecord/{domains_id}/create/modal
 *      POST /plugins/domainmanager/dnsrecord/{domains_id}/create
 *      POST /plugins/domainmanager/dnsrecord/{domains_id}/edit/modal/{domainrecords_id}
 *      POST /plugins/domainmanager/dnsrecord/{domains_id}/edit/{domainrecords_id}
 *      POST /plugins/domainmanager/dnsrecord/{domains_id}/delete/modal/{domainrecords_id}
 *      POST /plugins/domainmanager/dnsrecord/{domains_id}/delete/{domainrecords_id}
 * CSRF enforced by core CheckCsrfListener (X-Glpi-Csrf-Token header).
 */
class DnsRecordWriteController extends AbstractController
{
    private const WRITABLE_TYPES = ['A', 'AAAA', 'CNAME', 'TXT'];

    #[Route('/dnsrecord/{domains_id}/create/modal', name: 'domainmanager_dnsrecord_create_modal', methods: ['POST'], requirements: ['domains_id' => '\d+'])]
    public function createModal(int $domains_id, Request $request): Response
    {
        $domain = new Domain();
        if (!$domain->getFromDB($domains_id)) {
            return new JsonResponse(['ok' => false, 'message' => __('Domain not found', 'domainmanager')], 404);
        }

        if (!$domain->can($domains_id, READ)) {
            return new JsonResponse(['ok' => false, 'message' => __('You do not have permission to read this domain', 'domainmanager')], 403);
        }

        if (!Session::haveRight('domain', CREATE) || !Session::haveRight('domainmanager:dns_records', CREATE)) {
            return new JsonResponse(['ok' => false, 'message' => __('You do not have permission to create DNS records', 'domainmanager')], 403);
        }

        $state = DomainState::getForDomain($domains_id);
        if ($state === null || !$this->isDnsEditable($state)) {
            return new JsonResponse(['ok' => false, 'message' => __('This domain\'s DNS provider does not support write-back', 'domainmanager')], 409);
        }

        $type = (string) $request->request->get('type', '');
        $name = (string) $request->request->get('name', '');
        $data = (string) $request->request->get('data', '');
        $ttl = (int) $request->request->get('ttl', 3600);

        $preflightError = $this->preflight($type);
        if ($preflightError !== null) {
            return new JsonResponse(['ok' => false, 'message' => $preflightError], 400);
        }

        return new Response($this->renderCreateModal(
            $domain,
            $type,
            $name,
            $data,
            $ttl,
        ));
    }

    #[Route('/dnsrecord/{domains_id}/create', name: 'domainmanager_dnsrecord_create', methods: ['POST'], requirements: ['domains_id' => '\d+'])]
    public function create(int $domains_id, Request $request): Response
    {
        $domain = new Domain();
        if (!$domain->getFromDB($domains_id)) {
            return new JsonResponse(['ok' => false, 'message' => __('Domain not found', 'domainmanager')], 404);
        }

        if (!$domain->can($domains_id, READ)) {
            return new JsonResponse(['ok' => false, 'message' => __('You do not have permission to read this domain', 'domainmanager')], 403);
        }

        if (!Session::haveRight('domain', CREATE) || !Session::haveRight('domainmanager:dns_records', CREATE)) {
            return new JsonResponse(['ok' => false, 'message' => __('You do not have permission to create DNS records', 'domainmanager')], 403);
        }

        $state = DomainState::getForDomain($domains_id);
        if ($state === null || !$this->isDnsEditable($state)) {
            return new JsonResponse(['ok' => false, 'message' => __('This domain\'s DNS provider does not support write-back', 'domainmanager')], 409);
        }

        $type = (string) $request->request->get('type', '');
        $name = (string) $request->request->get('name', '');
        $data = (string) $request->request->get('data', '');
        $ttl = (int) $request->request->get('ttl', 3600);

        $preflightError = $this->preflight($type);
        if ($preflightError !== null) {
            return new JsonResponse(['ok' => false, 'message' => $preflightError], 400);
        }

        $this->sanitizeInputs($name, $data, $ttl);
        if (!$this->validateInputs($type, $name, $data, $ttl)) {
            return new JsonResponse(['ok' => false, 'message' => __('Invalid record data', 'domainmanager')], 400);
        }

        $nsRecheck = $this->recheckNameservers($domain);
        if ($nsRecheck !== null) {
            return new JsonResponse(['ok' => false, 'message' => $nsRecheck], 409);
        }

        try {
            $driver = $this->getWritableDriver($state);
            $absoluteName = $domain->fields['name'] . '.' . (strlen($name) > 0 && $name !== '@' ? $name . '.' : '');
            $zoneName = $domain->fields['name'];

            $createdRecord = $driver->createRecord($zoneName, $type, $absoluteName, $data, $ttl);

            $native = new DomainRecord();
            $records_id = $native->add([
                'domains_id'           => $domains_id,
                'name'                 => $name ?: '@',
                'data'                 => $data,
                'ttl'                  => $ttl,
                'domainrecordtypes_id' => $this->getTypeId($type),
                'entities_id'          => $domain->fields['entities_id'],
                'is_recursive'         => $domain->fields['is_recursive'],
            ]);

            if (!$records_id) {
                Log::history($domains_id, Domain::class, [0, '', '[Domain Manager] ' . __('Record push to IONOS failed: Could not save record locally', 'domainmanager')]);
                return new JsonResponse(['ok' => false, 'message' => __('Failed to save record locally', 'domainmanager')], 500);
            }

            $imported = new ImportedRecord();
            $imported->add([
                'domainrecords_id' => $records_id,
                'domains_id'       => $domains_id,
                'remote_id'        => $createdRecord->remoteId,
                'record_hash'      => $createdRecord->getHash(),
                'last_seen'        => date('Y-m-d H:i:s'),
                'is_managed'       => 1,
                'is_glpi_created'  => 1,
                'is_proxied'       => $createdRecord->isProxied !== null ? (int) $createdRecord->isProxied : null,
            ]);

            ImportLock::replaceLocks(
                DomainRecord::class,
                (int) $records_id,
                [
                    'name'                 => $name ?: '@',
                    'data'                 => $data,
                    'ttl'                  => $ttl,
                    'domainrecordtypes_id' => $this->getTypeId($type),
                ],
            );

            Log::history($domains_id, Domain::class, [0, '', '[Domain Manager] ' . sprintf(
                __('Record created from GLPI: %s %s → %s (TTL %d)', 'domainmanager'),
                $type,
                $name ?: '@',
                $data,
                $ttl,
            )
            ]);

            return new JsonResponse([
                'ok'      => true,
                'message' => __('Record created successfully', 'domainmanager'),
                'records_id' => $records_id,
            ]);
        } catch (Throwable $e) {
            $message = $e instanceof DriverException ? $e->getMessage() : __('An error occurred while creating the record', 'domainmanager');
            Log::history($domains_id, Domain::class, [0, '', '[Domain Manager] ' . __('Record push to IONOS failed: ', 'domainmanager') . $message]);
            PluginLogger::error("Failed to create DNS record for domain #$domains_id", $e::class . ': ' . $e->getMessage());
            return new JsonResponse(['ok' => false, 'message' => $message], 500);
        }
    }

    #[Route('/dnsrecord/{domains_id}/edit/modal/{domainrecords_id}', name: 'domainmanager_dnsrecord_edit_modal', methods: ['POST'], requirements: ['domains_id' => '\d+', 'domainrecords_id' => '\d+'])]
    public function editModal(int $domains_id, int $domainrecords_id, Request $request): Response
    {
        $domain = new Domain();
        if (!$domain->getFromDB($domains_id)) {
            return new JsonResponse(['ok' => false, 'message' => __('Domain not found', 'domainmanager')], 404);
        }

        if (!$domain->can($domains_id, READ)) {
            return new JsonResponse(['ok' => false, 'message' => __('You do not have permission to read this domain', 'domainmanager')], 403);
        }

        if (!Session::haveRight('domainmanager:dns_records', UPDATE)) {
            return new JsonResponse(['ok' => false, 'message' => __('You do not have permission to update DNS records', 'domainmanager')], 403);
        }

        $record = new DomainRecord();
        if (!$record->getFromDB($domainrecords_id) || $record->fields['domains_id'] != $domains_id) {
            return new JsonResponse(['ok' => false, 'message' => __('Record not found', 'domainmanager')], 404);
        }

        $state = DomainState::getForDomain($domains_id);
        if ($state === null || !$this->isDnsEditable($state)) {
            return new JsonResponse(['ok' => false, 'message' => __('This domain\'s DNS provider does not support write-back', 'domainmanager')], 409);
        }

        $type = (string) $request->request->get('type', '');
        $name = (string) $request->request->get('name', '');
        $data = (string) $request->request->get('data', '');
        $ttl = (int) $request->request->get('ttl', 3600);

        $preflightError = $this->preflight($type);
        if ($preflightError !== null) {
            return new JsonResponse(['ok' => false, 'message' => $preflightError], 400);
        }

        $liveFetch = null;
        $liveFetchFailed = false;
        $imported = ImportedRecord::getForDomainRecord($domainrecords_id);
        if ($imported !== null && $imported->fields['remote_id'] !== '') {
            try {
                $driver = $this->getWritableDriver($state);
                $liveFetch = $driver->fetchRecord($domain->fields['name'], $imported->fields['remote_id']);
            } catch (Throwable $e) {
                $liveFetchFailed = true;
            }
        }

        return new Response($this->renderEditModal(
            $domain,
            $record,
            $type,
            $name,
            $data,
            $ttl,
            $liveFetch,
            $liveFetchFailed,
        ));
    }

    #[Route('/dnsrecord/{domains_id}/edit/{domainrecords_id}', name: 'domainmanager_dnsrecord_edit', methods: ['POST'], requirements: ['domains_id' => '\d+', 'domainrecords_id' => '\d+'])]
    public function edit(int $domains_id, int $domainrecords_id, Request $request): Response
    {
        $domain = new Domain();
        if (!$domain->getFromDB($domains_id)) {
            return new JsonResponse(['ok' => false, 'message' => __('Domain not found', 'domainmanager')], 404);
        }

        if (!$domain->can($domains_id, READ)) {
            return new JsonResponse(['ok' => false, 'message' => __('You do not have permission to read this domain', 'domainmanager')], 403);
        }

        if (!Session::haveRight('domainmanager:dns_records', UPDATE)) {
            return new JsonResponse(['ok' => false, 'message' => __('You do not have permission to update DNS records', 'domainmanager')], 403);
        }

        $record = new DomainRecord();
        if (!$record->getFromDB($domainrecords_id) || $record->fields['domains_id'] != $domains_id) {
            return new JsonResponse(['ok' => false, 'message' => __('Record not found', 'domainmanager')], 404);
        }

        $state = DomainState::getForDomain($domains_id);
        if ($state === null || !$this->isDnsEditable($state)) {
            return new JsonResponse(['ok' => false, 'message' => __('This domain\'s DNS provider does not support write-back', 'domainmanager')], 409);
        }

        $type = (string) $request->request->get('type', '');
        $data = (string) $request->request->get('data', '');
        $ttl = (int) $request->request->get('ttl', 3600);

        $preflightError = $this->preflight($type);
        if ($preflightError !== null) {
            return new JsonResponse(['ok' => false, 'message' => $preflightError], 400);
        }

        $this->sanitizeInputs($record->fields['name'], $data, $ttl);
        if (!$this->validateInputs($type, $record->fields['name'], $data, $ttl)) {
            return new JsonResponse(['ok' => false, 'message' => __('Invalid record data', 'domainmanager')], 400);
        }

        $nsRecheck = $this->recheckNameservers($domain);
        if ($nsRecheck !== null) {
            return new JsonResponse(['ok' => false, 'message' => $nsRecheck], 409);
        }

        try {
            $driver = $this->getWritableDriver($state);
            $imported = ImportedRecord::getForDomainRecord($domainrecords_id);
            if ($imported === null || $imported->fields['remote_id'] === '') {
                return new JsonResponse(['ok' => false, 'message' => __('Record has no remote ID', 'domainmanager')], 400);
            }

            $zoneName = $domain->fields['name'];
            $driver->updateRecord($zoneName, $imported->fields['remote_id'], $type, $record->fields['name'], $data, $ttl);

            $record->update([
                'id'   => $domainrecords_id,
                'data' => $data,
                'ttl'  => $ttl,
            ]);

            ImportLock::replaceLocks(
                DomainRecord::class,
                (int) $domainrecords_id,
                [
                    'name'                 => $record->fields['name'],
                    'data'                 => $data,
                    'ttl'                  => $ttl,
                    'domainrecordtypes_id' => $this->getTypeId($type),
                ],
            );

            Log::history($domains_id, Domain::class, [0, '', '[Domain Manager] ' . sprintf(
                __('Record updated from GLPI: %s %s — data %s → %s', 'domainmanager'),
                $type,
                $record->fields['name'],
                $record->fields['data'],
                $data,
            )
            ]);

            return new JsonResponse([
                'ok'      => true,
                'message' => __('Record updated successfully', 'domainmanager'),
            ]);
        } catch (Throwable $e) {
            $message = $e instanceof DriverException ? $e->getMessage() : __('An error occurred while updating the record', 'domainmanager');
            Log::history($domains_id, Domain::class, [0, '', '[Domain Manager] ' . __('Record push to IONOS failed: ', 'domainmanager') . $message]);
            PluginLogger::error("Failed to update DNS record #$domainrecords_id for domain #$domains_id", $e::class . ': ' . $e->getMessage());
            return new JsonResponse(['ok' => false, 'message' => $message], 500);
        }
    }

    #[Route('/dnsrecord/{domains_id}/delete/modal/{domainrecords_id}', name: 'domainmanager_dnsrecord_delete_modal', methods: ['POST'], requirements: ['domains_id' => '\d+', 'domainrecords_id' => '\d+'])]
    public function deleteModal(int $domains_id, int $domainrecords_id, Request $request): Response
    {
        $domain = new Domain();
        if (!$domain->getFromDB($domains_id)) {
            return new JsonResponse(['ok' => false, 'message' => __('Domain not found', 'domainmanager')], 404);
        }

        if (!$domain->can($domains_id, READ)) {
            return new JsonResponse(['ok' => false, 'message' => __('You do not have permission to read this domain', 'domainmanager')], 403);
        }

        if (!Session::haveRight('domainmanager:dns_records', DELETE)) {
            return new JsonResponse(['ok' => false, 'message' => __('You do not have permission to delete DNS records', 'domainmanager')], 403);
        }

        $record = new DomainRecord();
        if (!$record->getFromDB($domainrecords_id) || $record->fields['domains_id'] != $domains_id) {
            return new JsonResponse(['ok' => false, 'message' => __('Record not found', 'domainmanager')], 404);
        }

        $state = DomainState::getForDomain($domains_id);
        if ($state === null || !$this->isDnsEditable($state)) {
            return new JsonResponse(['ok' => false, 'message' => __('This domain\'s DNS provider does not support write-back', 'domainmanager')], 409);
        }

        return new Response($this->renderDeleteModal($domain, $record));
    }

    #[Route('/dnsrecord/{domains_id}/delete/{domainrecords_id}', name: 'domainmanager_dnsrecord_delete', methods: ['POST'], requirements: ['domains_id' => '\d+', 'domainrecords_id' => '\d+'])]
    public function delete(int $domains_id, int $domainrecords_id, Request $request): Response
    {
        $domain = new Domain();
        if (!$domain->getFromDB($domains_id)) {
            return new JsonResponse(['ok' => false, 'message' => __('Domain not found', 'domainmanager')], 404);
        }

        if (!$domain->can($domains_id, READ)) {
            return new JsonResponse(['ok' => false, 'message' => __('You do not have permission to read this domain', 'domainmanager')], 403);
        }

        if (!Session::haveRight('domainmanager:dns_records', DELETE)) {
            return new JsonResponse(['ok' => false, 'message' => __('You do not have permission to delete DNS records', 'domainmanager')], 403);
        }

        $record = new DomainRecord();
        if (!$record->getFromDB($domainrecords_id) || $record->fields['domains_id'] != $domains_id) {
            return new JsonResponse(['ok' => false, 'message' => __('Record not found', 'domainmanager')], 404);
        }

        $state = DomainState::getForDomain($domains_id);
        if ($state === null || !$this->isDnsEditable($state)) {
            return new JsonResponse(['ok' => false, 'message' => __('This domain\'s DNS provider does not support write-back', 'domainmanager')], 409);
        }

        $nsRecheck = $this->recheckNameservers($domain);
        if ($nsRecheck !== null) {
            return new JsonResponse(['ok' => false, 'message' => $nsRecheck], 409);
        }

        try {
            $driver = $this->getWritableDriver($state);
            $imported = ImportedRecord::getForDomainRecord($domainrecords_id);
            if ($imported !== null && $imported->fields['remote_id'] !== '') {
                $driver->deleteRecord($domain->fields['name'], $imported->fields['remote_id']);
            }

            LockEnforcer::$sync_in_progress = true;
            $record->delete(['id' => $domainrecords_id]);
            LockEnforcer::$sync_in_progress = false;

            Log::history($domains_id, Domain::class, [0, '', '[Domain Manager] ' . sprintf(
                __('Record deleted from GLPI: %s %s', 'domainmanager'),
                $this->getTypeName($record->fields['domainrecordtypes_id']),
                $record->fields['name'],
            )
            ]);

            return new JsonResponse([
                'ok'      => true,
                'message' => __('Record deleted successfully', 'domainmanager'),
            ]);
        } catch (Throwable $e) {
            $message = $e instanceof DriverException ? $e->getMessage() : __('An error occurred while deleting the record', 'domainmanager');
            Log::history($domains_id, Domain::class, [0, '', '[Domain Manager] ' . __('Record push to IONOS failed: ', 'domainmanager') . $message]);
            PluginLogger::error("Failed to delete DNS record #$domainrecords_id for domain #$domains_id", $e::class . ': ' . $e->getMessage());
            return new JsonResponse(['ok' => false, 'message' => $message], 500);
        }
    }

    private function isDnsEditable(DomainState $state): bool
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

    private function getWritableDriver(DomainState $state): DnsRecordWriterInterface
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

    private function preflight(string $type): ?string
    {
        if (!in_array($type, self::WRITABLE_TYPES, true)) {
            return sprintf(
                __('Record type %s is not writable; only %s are supported', 'domainmanager'),
                $type,
                implode(', ', self::WRITABLE_TYPES),
            );
        }

        if (Session::isCron()) {
            return null;
        }

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

    private function sanitizeInputs(string &$name, string &$data, int &$ttl): void
    {
        $name = trim($name);
        $data = trim($data);
        $ttl = max(60, min($ttl, 2147483647));
    }

    private function validateInputs(string $type, string $name, string $data, int $ttl): bool
    {
        if (empty($data)) {
            return false;
        }
        if ($ttl < 60) {
            return false;
        }
        if ($type === 'CNAME' && empty($data)) {
            return false;
        }
        return true;
    }

    private function recheckNameservers(Domain $domain): ?string
    {
        try {
            $resolver = new NsResolver();
            $hosts = $resolver->getNameservers($domain->fields['name']);
            $provider = NsProviderRegistry::match($hosts);
            if ($provider === null || ($provider['driver'] ?? null) !== DriverRegistry::DRIVER_IONOS) {
                return __('Domain nameservers have changed since last sync; cannot verify IONOS is still authoritative', 'domainmanager');
            }
        } catch (Throwable $e) {
            return __('Unable to re-check domain nameservers', 'domainmanager');
        }
        return null;
    }

    private function getTypeId(string $type): int
    {
        $typeObj = new DomainRecordType();
        if ($typeObj->getFromDBByCrit(['name' => $type])) {
            return (int) $typeObj->getID();
        }
        throw new DriverException(sprintf(__('Record type %s not found', 'domainmanager'), $type));
    }

    private function getTypeName(int $typeId): string
    {
        $typeObj = new DomainRecordType();
        if ($typeObj->getFromDB($typeId)) {
            return $typeObj->fields['name'] ?? 'Unknown';
        }
        return 'Unknown';
    }

    private function renderCreateModal(Domain $domain, string $type, string $name, string $data, int $ttl): string
    {
        return $this->renderTemplate('dns_record_create_modal', [
            'domain'  => $domain,
            'type'    => $type,
            'name'    => $name,
            'data'    => $data,
            'ttl'     => $ttl,
        ]);
    }

    private function renderEditModal(Domain $domain, DomainRecord $record, string $type, string $name, string $data, int $ttl, ?ZoneRecord $liveFetch, bool $liveFetchFailed): string
    {
        return $this->renderTemplate('dns_record_edit_modal', [
            'domain'          => $domain,
            'record'          => $record,
            'type'            => $type,
            'name'            => $name,
            'data'            => $data,
            'ttl'             => $ttl,
            'live_fetch'      => $liveFetch,
            'live_fetch_failed' => $liveFetchFailed,
        ]);
    }

    private function renderDeleteModal(Domain $domain, DomainRecord $record): string
    {
        return $this->renderTemplate('dns_record_delete_modal', [
            'domain' => $domain,
            'record' => $record,
        ]);
    }

    private function renderTemplate(string $template, array $context): string
    {
        return TemplateRenderer::getInstance()->render("@domainmanager/$template.html.twig", $context);
    }
}
