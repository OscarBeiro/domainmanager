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
     * Daily domain synchronization batch
     *
     * Phase 1 shell: the sync engine arrives in a later phase.
     *
     * @param  CronTask $task
     * @return int >0 done with actions, 0 nothing to do, <0 to be run again
     */
    public static function cronDomainSync(CronTask $task): int
    {
        $task->log('Domain Manager sync engine is not implemented yet; nothing to do.');

        return 0;
    }
}
