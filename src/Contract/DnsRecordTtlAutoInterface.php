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

namespace GlpiPlugin\Domainmanager\Contract;

/**
 * Pure marker, no methods: a driver whose provider treats a TTL value of
 * `1` as "automatic" (confirmed for Cloudflare against its own API docs,
 * §9 research "persist proxy addresses + TTL-auto flag") implements this so
 * the UI layer (`DnsRecordWriteback::supportsTtlAutoSentinel()`,
 * `DomainForm`) can decide whether to show the "TTL 1 means Automatic"
 * reminder on an editable TTL field, without ever naming a driver directly
 * (same pattern as `DnsRecordProxyToggleInterface`). A driver with no such
 * sentinel (IONOS, Dinahosting) simply doesn't implement this — a `1`-second
 * TTL there is a literal one-second TTL, not "automatic" (see
 * `ZoneRecord::$isTtlAuto`'s own docblock).
 */
interface DnsRecordTtlAutoInterface
{
}
