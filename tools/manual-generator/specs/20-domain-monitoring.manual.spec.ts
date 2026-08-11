/**
 * tools/manual-generator/specs/20-domain-monitoring.manual.spec.ts
 *
 * Chapter 2: reading a Domain's Domain Manager status panel and managing its DNS records.
 *
 * Selectors verified against templates/domain_panel.html.twig and
 * templates/domainrecord_add_panel.html.twig — no data-testid attributes exist yet, so this
 * relies on the plugin's own element ids (#domainmanager-panel, #domainmanager-add-record-panel)
 * and core GLPI's tab labels (getByRole/text).
 */
import { test, expect } from '../lib/manual-recorder';
import { login, routes } from '../lib/glpi';
import { DEMO } from './fixtures';

test.describe.configure({ mode: 'serial' });

test.beforeEach(async ({ page, manual }) => {
  await login(page, manual.locale);
});

test('Monitoring a domain and its DNS records', async ({ manual, page }) => {
  manual.about({
    slug: 'domain-monitoring',
    title: 'Monitoring a domain and its DNS records',
    intro:
      'Once a domain is managed by Domain Manager, its own record shows a live status ' +
      'panel — registrar, DNS provider, sync health — and its DNS zone becomes editable ' +
      'from GLPI when the provider supports write-back.',
  });

  await manual.step(
    'open-domain',
    'Open the domain’s Domain Manager panel',
    'Open the domain from **Assets → Domains**. The **Domain Manager** panel on its main ' +
      'tab shows which Supplier acts as registrar and which one as DNS provider, plus a ' +
      'sync status badge for each — green for healthy, red for a failed sync, grey for ' +
      '"never synchronized".',
    async () => {
      await page.goto(routes.domainList);
      await page.getByRole('link', { name: DEMO.domain.name }).click();
      const panel = page.locator('#domainmanager-panel');
      await expect(panel).toBeVisible();
      await manual.shot('domain-panel', {
        target: panel,
        caption: 'The Domain Manager status panel on a managed domain',
      });
    },
  );

  await manual.step(
    'open-records-tab',
    'Open the domain’s DNS records',
    'Switch to the domain’s **Records** tab to see its DNS zone. Records synced ' +
      'from the provider are locked against manual edits — this keeps GLPI from drifting ' +
      'out of sync with what the DNS provider actually serves.',
    async () => {
      await page.getByRole('tab', { name: /^Records/i }).click();
      const table = page.getByRole('table').first();
      await expect(table).toBeVisible();
      await manual.shot('records-list', {
        target: table,
        caption: 'The domain’s DNS records, synced from its provider',
      });
    },
  );

  await manual.step(
    'add-record',
    'Add a DNS record',
    `On a domain whose DNS provider supports write-back (here, ${DEMO.supplier.driver}), ` +
      'a **New record** form appears in place of the usual "Link a record" form. Creating a ' +
      'record here pushes it live to the DNS provider immediately, not just to GLPI\'s copy ' +
      'of the zone — the confirmation prompt exists for that reason.',
    async () => {
      // The form's labels aren't <label for="…"> associated with their inputs (verified in
      // templates/domainrecord_add_panel.html.twig), so target the inputs by name instead.
      const addPanel = page.locator('#domainmanager-add-record-panel');
      await expect(addPanel).toBeVisible();
      await addPanel.locator('select[name="domainrecordtypes_id"]').selectOption({ label: DEMO.dnsRecord.a.type });
      await addPanel.locator('input[name="name"]').fill(DEMO.dnsRecord.a.name);
      await addPanel.locator('input[name="data"]').fill(DEMO.dnsRecord.a.value);
      await manual.shot('add-record-form', {
        target: addPanel,
        caption: 'Adding an A record; this will be created live at the DNS provider',
      });
    },
  );

  manual.note(
    'Fields populated by the last sync (registration date, expiry, existing record values) ' +
      'are locked by default. A user with the "Unlock imported domain data" right can edit ' +
      'them anyway — useful when the provider reported something wrong.',
  );
});
