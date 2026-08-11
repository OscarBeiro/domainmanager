/**
 * tools/manual-generator/specs/10-supplier-setup.manual.spec.ts
 *
 * Chapter 1: configuring a Supplier's Domain Manager API driver and importing its domains.
 *
 * No data-testid attributes exist in this plugin's templates yet, so selectors here use
 * getByRole/getByLabel/text against the real markup in templates/supplier_tab.html.twig and
 * templates/domain_discovery_modal.html.twig (verified by reading those files, not guessed).
 */
import { test, expect } from '../lib/manual-recorder';
import { login, routes } from '../lib/glpi';
import { DEMO } from './fixtures';

test.describe.configure({ mode: 'serial' });

test.beforeEach(async ({ page, manual }) => {
  await login(page, manual.locale);
});

test('Configuring a Supplier as a domain source', async ({ manual, page }) => {
  manual.about({
    slug: 'supplier-setup',
    title: 'Configuring a Supplier as a domain source',
    intro:
      'Domain Manager syncs domains and DNS records through a Supplier’s API ' +
      'credentials. Before it can manage anything, one Supplier needs an API driver ' +
      'selected and its credentials entered.',
  });

  await manual.step(
    'open-tab',
    'Open the Domain Manager tab on a Supplier',
    'Open the Supplier record and select its **Domain Manager** tab. This is where the ' +
      'API driver and credentials for that provider are configured.',
    async () => {
      await page.goto(routes.supplierList);
      await page.getByRole('link', { name: DEMO.supplier.name }).click();
      await page.getByRole('tab', { name: 'Domain Manager' }).click();
      const driverCard = page.locator('.card.m-2', { has: page.locator('#domainmanager-driver') });
      await expect(driverCard).toBeVisible();
      await manual.shot('supplier-tab', {
        target: driverCard,
        caption: 'The Domain Manager tab on a Supplier, with a driver already selected',
      });
    },
  );

  await manual.step(
    'select-driver',
    'Pick an API driver and enter credentials',
    `Choose the **API driver** that matches this Supplier — Domain Manager currently ` +
      `supports Cloudflare, IONOS and Dinahosting. The fields below change to match the ` +
      `chosen driver; each shows exactly the credentials that provider requires.`,
    async () => {
      await page.getByLabel('API driver').selectOption({ label: DEMO.supplier.driver });
      const fields = page.locator('.domainmanager-driver-fields:not([hidden])');
      await fields.getByLabel('Account ID').fill(DEMO.supplier.accountId);
      await fields.getByLabel('API Token').fill(DEMO.supplier.token);
      await manual.shot('driver-fields', {
        target: fields,
        caption: 'Credential fields for the Cloudflare driver',
      });
    },
  );

  await manual.step(
    'save',
    'Save the configuration',
    'Click **Save**. The *Test* and *Import* actions only appear once a driver has been ' +
      'saved at least once — until then there is nothing configured to test.',
    async () => {
      await page.getByRole('button', { name: 'Save' }).click();
      await expect(page.getByRole('button', { name: 'Test' })).toBeVisible();
    },
  );

  await manual.step(
    'test-connection',
    'Test the connection',
    'Click **Test** to verify the credentials work before relying on them. The result ' +
      'appears in the **Connection diagnostics** panel below, including any error the ' +
      'provider returned — useful for catching a mistyped or under-scoped credential ' +
      'before a sync ever runs.',
    async () => {
      await page.getByRole('button', { name: 'Test' }).click();
      const badge = page.locator('#domainmanager-test-badge');
      await expect(badge).not.toHaveText('Not tested yet', { timeout: 15_000 });
      const diag = page.locator('#domainmanager-test-panel');
      await manual.shot('connection-test', {
        target: diag,
        caption: 'The connection diagnostics panel after running Test',
      });
    },
  );

  await manual.step(
    'import',
    'Import the Supplier’s domains',
    'Click **Import** to list every domain visible in that provider’s account. Domains ' +
      'already tracked in GLPI are shown as already existing; the rest can be selected and ' +
      'imported in one action. This step needs working credentials — with an invalid or ' +
      'under-scoped token, the dialog reports the same error **Test** did instead of a ' +
      'domain list.',
    async () => {
      await page.getByRole('button', { name: 'Import' }).click();
      const modal = page.locator('.modal.show .modal-dialog').first();
      await expect(modal).toBeVisible();
      await manual.shot('import-modal', {
        target: modal,
        caption: 'The domain import dialog',
      });
    },
  );

  manual.note(
    'Domains already synced through this Supplier are updated automatically by the ' +
      '**DomainSync** automatic action; importing here is only needed for domains Domain ' +
      'Manager has not seen yet.',
  );
});
