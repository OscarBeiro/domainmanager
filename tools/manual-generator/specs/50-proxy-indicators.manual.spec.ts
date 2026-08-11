/**
 * tools/manual-generator/specs/50-proxy-indicators.manual.spec.ts
 *
 * Chapter 6: proxy vs. origin IP indicators for Cloudflare-proxied records.
 *
 * Selectors verified against templates/domainrecord_proxy_indicators.html.twig — the spec
 * demonstrates the visual indicators (cloud icon, anycast addresses) overlaid on the
 * native DomainRecord datatable by the client-side script.
 */
import { test, expect } from '../lib/manual-recorder';
import { login, routes } from '../lib/glpi';
import { DEMO } from './fixtures';

test.describe.configure({ mode: 'serial' });

test.beforeEach(async ({ page, manual }) => {
  await login(page, manual.locale);
});

test('Proxy vs. origin IP indicators', async ({ manual, page }) => {
  manual.about({
    slug: 'proxy-indicators',
    title: 'Proxy vs. origin IP indicators',
    intro:
      'When a domain is proxied through Cloudflare (or another proxy service), Domain Manager ' +
      'displays both the origin IP address and the proxy service\'s address. Visual indicators ' +
      'make it clear which records are being proxied.',
  });

  await manual.step(
    'open-records-tab',
    'Open the Records tab on a proxied domain',
    'Navigate to a domain with some records proxied through Cloudflare. Open the **Records** tab ' +
      'to see the full list of DNS records.',
    async () => {
      await page.goto(routes.domainList);
      await page.getByRole('link', { name: DEMO.domainProxied.name }).click();
      await page.waitForLoadState('networkidle');

      // Click the Records tab
      const recordsTab = page.getByRole('tab', { name: /^Records/i });
      await recordsTab.click();
      await page.waitForLoadState('networkidle');

      // Verify the table is visible
      const table = page.getByRole('table').first();
      await expect(table).toBeVisible();

      await manual.shot('records-table-with-proxied', {
        target: table,
        caption: 'The records table shows both proxied (cloud icon) and non-proxied records',
      });
    },
  );

  await manual.step(
    'identify-proxied',
    'Identify which records are proxied',
    'A **cloud icon** appears next to the name of any record that is proxied through Cloudflare. ' +
      'This small visual indicator tells you at a glance which records have proxy enabled.',
    async () => {
      // Look for any cloud-filled icon on the page (these are added by the proxy indicators script)
      const cloudIcons = page.locator('.ti-cloud-filled');
      const count = await cloudIcons.count();

      // We should have at least one cloud icon for the proxied record
      expect(count).toBeGreaterThan(0);

      // Take a screenshot of the table
      const table = page.getByRole('table').first();
      await manual.shot('proxy-icon-detail', {
        target: table,
        caption: 'The cloud icons next to proxied record names show which records are proxied',
      });
    },
  );

  await manual.step(
    'see-proxy-address',
    'See the proxy service\'s address',
    'Below the **Target** (origin IP) for any proxied record, a second line shows the proxy service\'s ' +
      'address — for Cloudflare, this is the anycast IP they\'re using. This address ' +
      'is updated automatically during each sync and acts as the public-facing address for the record.',
    async () => {
      // Look for proxy address lines (these are added by the proxy indicators script)
      const proxyAddrLines = page.locator('.domainmanager-proxy-addresses');
      const count = await proxyAddrLines.count();

      // We should have at least one proxy address line
      expect(count).toBeGreaterThan(0);

      // Take a screenshot focused on the target column where proxy addresses appear
      const table = page.getByRole('table').first();
      await manual.shot('proxy-address-detail', {
        target: table,
        caption: 'Proxy service anycast addresses appear below the origin IP on proxied records',
      });
    },
  );
});
