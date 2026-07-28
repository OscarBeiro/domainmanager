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
use Domain;
use GlpiPlugin\Domainmanager\Service\SyncEngine;
use MassiveAction;
use Session;
use Throwable;

/**
 * Native GLPI Massive Action on Domain ("Sync now (Domain Manager)", §9
 * Phase 5.5) — lets an admin batch-sync every domain a "Domains" list
 * recommendation banner flagged as never-checked, without opening each one
 * individually. Registered via Hooks::USE_MASSIVE_ACTION in setup.php.
 */
class MassiveActionHandler
{
    public const ACTION_SYNC = 'sync';

    /**
     * plugin_domainmanager_MassiveActions() callback (hook.php)
     *
     * @param  string $itemtype
     * @return array<string, string>
     */
    public static function getActions(string $itemtype): array
    {
        if ($itemtype !== Domain::class || !Session::haveRight('domain', UPDATE)) {
            return [];
        }

        return [
            self::class . MassiveAction::CLASS_ACTION_SEPARATOR . self::ACTION_SYNC
                => __('Sync now (Domain Manager)', 'domainmanager'),
        ];
    }

    /**
     * `MassiveAction::showSubForm()` requires this on every processor class
     * (verified live: an unimplemented method here is a fatal
     * `UndefinedMethodError`, not a soft no-op) — this action needs no
     * extra fields, so returning false makes it fall back to
     * `showDefaultSubForm()`'s plain "Post" submit button.
     *
     * @param  MassiveAction $ma
     * @return bool
     */
    public static function showMassiveActionsSubForm(MassiveAction $ma): bool
    {
        return false;
    }

    /**
     * Batches SyncEngine::sync() over the selected Domain ids. Each item is
     * independent — server-side `can()` and driver/API failures for one
     * domain never abort the rest of the batch (§5's per-leg isolation
     * already keeps a single domain's own registrar/DNS failures from
     * aborting each other; this applies the same principle across domains).
     *
     * @param  MassiveAction $ma
     * @param  CommonDBTM    $item
     * @param  int[]         $ids
     * @return void
     */
    public static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item, array $ids): void
    {
        if ($ma->getAction() !== self::ACTION_SYNC || !$item instanceof Domain) {
            return;
        }

        $engine = new SyncEngine();

        foreach ($ids as $id) {
            $domain = new Domain();
            if (!$domain->getFromDB($id) || !$domain->can($id, UPDATE)) {
                $ma->itemDone(Domain::class, $id, MassiveAction::ACTION_NORIGHT);
                continue;
            }

            try {
                $result = $engine->sync($domain);

                $has_error = $result['registrar_status'] === DomainState::STATUS_ERROR
                    || $result['dns_status'] === DomainState::STATUS_ERROR;
                $has_inactive_supplier = $result['registrar_status'] === DomainState::STATUS_SUPPLIER_INACTIVE
                    || $result['dns_status'] === DomainState::STATUS_SUPPLIER_INACTIVE;

                if ($has_error) {
                    $ma->itemDone(Domain::class, $id, MassiveAction::ACTION_KO);
                    $ma->addMessage(sprintf(
                        __('Sync reported an error for %s — see the plugin error log', 'domainmanager'),
                        $domain->getName(),
                    ));
                } elseif ($has_inactive_supplier) {
                    // Not a failure — a deliberate skip (§addendum "Skip
                    // Inactive Suppliers"), reported with its own reason
                    // rather than folded into the generic error message.
                    $ma->itemDone(Domain::class, $id, MassiveAction::ACTION_KO);
                    $ma->addMessage(sprintf(
                        __('Skipped %s — resolved supplier is inactive', 'domainmanager'),
                        $domain->getName(),
                    ));
                } else {
                    $ma->itemDone(Domain::class, $id, MassiveAction::ACTION_OK);
                }
            } catch (Throwable $e) {
                $ma->itemDone(Domain::class, $id, MassiveAction::ACTION_KO);
                $ma->addMessage(sprintf(
                    __('Sync failed unexpectedly for %s — see the plugin error log', 'domainmanager'),
                    $domain->getName(),
                ));
            }
        }
    }
}
