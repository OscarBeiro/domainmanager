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

namespace GlpiPlugin\Domainmanager\Exception;

use RuntimeException;

/**
 * ARCHITECTURE.md §15.3 Phase 55: a reconciliation run whose upstream
 * snapshot would trash more of a domain's owned records than the
 * configured blast-radius thresholds allow — thrown by
 * `RecordReconciler::reconcile()` *before* any trash bin mutation runs,
 * so a wrongly-scoped credential or a truncated/empty upstream page never
 * gets the chance to soft-delete a whole zone. `SyncEngine::syncDnsLeg()`
 * catches this distinctly from `DriverException`/generic `Throwable` and
 * sets `DomainState::STATUS_BLAST_RADIUS_GUARD` rather than `STATUS_ERROR`
 * — this isn't a failure, it's a guard that did its job. Deliberately not
 * a `DriverException`: this has nothing to do with a provider call
 * failing, and mixing it into that catch branch would lose the distinct
 * status.
 */
class BlastRadiusExceededException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $wouldTrash,
        public readonly int $totalOwned,
    ) {
        parent::__construct($message);
    }
}
