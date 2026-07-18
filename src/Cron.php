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

use CronTask;
use Domain;
use GlpiPlugin\Domainmanager\Service\SyncEngine;
use GlpiPlugin\Domainmanager\Service\SyncLogger;
use Throwable;

class Cron
{
    /**
     * Describe the plugin automatic actions
     *
     * @param  string $name
     * @return array
     */
    public static function cronInfo(string $name): array
    {
        switch ($name) {
            case 'DomainSync':
                return [
                    'description' => __('Synchronize domain lifecycle and DNS zone records from provider APIs', 'domainmanager'),
                    'parameter'   => __('Number of domains to process per run', 'domainmanager'),
                ];
        }

        return [];
    }

    /**
     * Daily domain synchronization batch (§5): active, non-deleted,
     * non-template domains, least-recently-synced first, batch size from
     * the task parameter, per-domain isolation
     *
     * @param  CronTask $task
     * @return int >0 done with actions, 0 nothing to do, <0 to be run again
     */
    public static function cronDomainSync(CronTask $task): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $batch_size = max(1, (int) ($task->fields['param'] ?? 20));

        $iterator = $DB->request([
            'SELECT'    => 'glpi_domains.id',
            'FROM'      => 'glpi_domains',
            'LEFT JOIN' => [
                DomainState::getTable() => [
                    'ON' => [
                        DomainState::getTable() => 'domains_id',
                        'glpi_domains'          => 'id',
                    ],
                ],
            ],
            'WHERE'     => [
                'glpi_domains.is_deleted'  => 0,
                'glpi_domains.is_template' => 0,
                'glpi_domains.is_active'   => 1,
            ],
            'ORDER'     => DomainState::getTable() . '.last_sync_date ASC',
            'LIMIT'     => $batch_size,
        ]);

        $engine    = new SyncEngine();
        $logger    = new SyncLogger();
        $processed = 0;
        $errors    = 0;

        foreach ($iterator as $row) {
            try {
                $domain = new Domain();
                if (!$domain->getFromDB((int) $row['id'])) {
                    continue;
                }
                $result = $engine->sync($domain);
                if (
                    $result['registrar_status'] === DomainState::STATUS_ERROR
                    || $result['dns_status'] === DomainState::STATUS_ERROR
                ) {
                    $errors++;
                }
            } catch (Throwable $e) {
                $errors++;
                $logger->detail(
                    'Cron sync failed for domain #' . $row['id'] . ': ' . $e::class . ': ' . $e->getMessage()
                );
            }
            $processed++;
            $task->addVolume(1);
        }

        if ($processed > 0) {
            $task->log(sprintf('Synchronized %d domain(s), %d with errors', $processed, $errors));
            return 1;
        }

        $task->log('No active domain to synchronize');
        return 0;
    }
}
