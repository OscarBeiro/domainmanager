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

use GlpiPlugin\Domainmanager\Dto\ZoneRecord;
use GlpiPlugin\Domainmanager\Exception\DriverException;

/**
 * Optional capability, separate from DnsRecordWriterInterface: a driver
 * whose provider has no per-record CDN-proxy concept (IONOS, Dinahosting)
 * simply doesn't implement this, rather than growing a no-op method on the
 * base write interface every driver would have to carry.
 */
interface DnsRecordProxyToggleInterface
{
    /**
     * @param  string $domain   FQDN, e.g. "example.com"
     * @param  string $remoteId provider-assigned record id
     * @param  bool   $proxied  desired proxy state
     * @return ZoneRecord the record as the provider reports it after the change
     * @throws DriverException on any failure (message safe to persist)
     */
    public function setProxied(string $domain, string $remoteId, bool $proxied): ZoneRecord;
}
