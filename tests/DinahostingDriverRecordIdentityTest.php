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

namespace GlpiPlugin\Domainmanager\Tests;

use GlpiPlugin\Domainmanager\Driver\DinahostingDriver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * §101-dinahosting: two distinct TXT records at one name (e.g. an apex's
 * SPF and DKIM records) must not collapse onto the identical remoteId —
 * that collision (live-caught 2026-08-17 on `dev.gal`) let an update to one
 * record resolve to, and silently delete, the other instead. A/AAAA/CNAME
 * must keep today's exact `type|name` encoding unchanged.
 */
final class DinahostingDriverRecordIdentityTest extends TestCase
{
    private function encode(string $type, string $name, string $data = ''): string
    {
        $reflection = new ReflectionMethod(DinahostingDriver::class, 'encodeRemoteId');
        $reflection->setAccessible(true);

        return $reflection->invoke(null, $type, $name, $data);
    }

    private function decode(string $remoteId): array
    {
        $reflection = new ReflectionMethod(DinahostingDriver::class, 'decodeRemoteId');
        $reflection->setAccessible(true);

        return $reflection->invoke(null, $remoteId);
    }

    private function findByIdentity(DinahostingDriver $driver, string $domain, string $type, string $name, ?string $dataHash): mixed
    {
        $reflection = new ReflectionMethod(DinahostingDriver::class, 'findByIdentity');
        $reflection->setAccessible(true);

        return $reflection->invoke($driver, $domain, $type, $name, $dataHash);
    }

    private function contentHash(string $type, string $data): string
    {
        $reflection = new ReflectionMethod(DinahostingDriver::class, 'contentHash');
        $reflection->setAccessible(true);

        return $reflection->invoke(null, $type, $data);
    }

    public function testTxtRecordsWithDifferentContentGetDistinctRemoteIds(): void
    {
        $dkim = $this->encode('TXT', 'dev.gal', 'v=DKIM1; k=rsa; p=abc');
        $spf  = $this->encode('TXT', 'dev.gal', 'v=spf1 include:_spf.mailersend.net a mx -all');

        $this->assertNotSame($dkim, $spf);
    }

    public function testMxRecordsWithDifferentContentGetDistinctRemoteIds(): void
    {
        $primary   = $this->encode('MX', 'dev.gal', '10 mail1.example.net.');
        $secondary = $this->encode('MX', 'dev.gal', '20 mail2.example.net.');

        $this->assertNotSame($primary, $secondary);
    }

    public function testTxtRemoteIdRoundTripsThroughDecode(): void
    {
        $remoteId = $this->encode('TXT', 'dev.gal', 'v=spf1 -all');
        $decoded  = $this->decode($remoteId);

        $this->assertSame('TXT', $decoded['type']);
        $this->assertSame('dev.gal', $decoded['name']);
        $this->assertSame($this->contentHash('TXT', 'v=spf1 -all'), $decoded['dataHash']);
    }

    /**
     * A/AAAA/CNAME encoding must stay byte-identical to the pre-fix scheme
     * (no data segment at all) — those types are legitimately single-value
     * per name via assertSingleRecordAtName(), and nothing about that
     * changes here.
     */
    public function testAaaaEncodingIsUnchangedByDataArgument(): void
    {
        $withoutData = $this->encode('A', 'www.dev.gal');
        $withData    = $this->encode('A', 'www.dev.gal', '203.0.113.10');

        $this->assertSame($withoutData, $withData);
        $this->assertSame(base64_encode('A|www.dev.gal'), $withoutData);

        $decoded = $this->decode($withoutData);
        $this->assertNull($decoded['dataHash']);
    }

    /**
     * A remoteId encoded before this fix (two segments only) must still
     * decode without throwing, with dataHash null.
     */
    public function testLegacyTwoSegmentRemoteIdStillDecodes(): void
    {
        $legacy  = base64_encode('TXT|dev.gal');
        $decoded = $this->decode($legacy);

        $this->assertSame('TXT', $decoded['type']);
        $this->assertSame('dev.gal', $decoded['name']);
        $this->assertNull($decoded['dataHash']);
    }

    /**
     * findByIdentity() must select the sibling matching the given content
     * hash, not just the first TXT record Dinahosting happens to list —
     * reproduces the dev.gal SPF+DKIM fixture that triggered this fix.
     */
    public function testFindByIdentitySelectsMatchingSiblingAmongTxtRecords(): void
    {
        $dkimData = '4RBuBxtxhZ5LW_HLpRhfybMAMMZ4RzgQ-zGvxL591J4';
        $spfData  = 'v=spf1 include:_spf.mailersend.net a mx -all';

        $driver = new class ([]) extends DinahostingDriver {
            public function __construct(array $credentials)
            {
            }

            public function fetchZoneRecords(string $domain): array
            {
                return [
                    new \GlpiPlugin\Domainmanager\Dto\ZoneRecord('TXT', 'dev.gal', '4RBuBxtxhZ5LW_HLpRhfybMAMMZ4RzgQ-zGvxL591J4', 3600),
                    new \GlpiPlugin\Domainmanager\Dto\ZoneRecord('TXT', 'dev.gal', 'v=spf1 include:_spf.mailersend.net a mx -all', 3600),
                ];
            }
        };

        $wantDkim = $this->findByIdentity($driver, 'dev.gal', 'TXT', 'dev.gal', $this->contentHash('TXT', $dkimData));
        $this->assertSame($dkimData, $wantDkim->data);

        $wantSpf = $this->findByIdentity($driver, 'dev.gal', 'TXT', 'dev.gal', $this->contentHash('TXT', $spfData));
        $this->assertSame($spfData, $wantSpf->data);
    }
}
