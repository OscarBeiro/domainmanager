/**
 * tools/manual-generator/specs/30-editing-records.manual.spec.ts
 *
 * Chapter 5: Editing an existing DNS record, and understanding the locked-field state
 * when a record is managed by Domain Manager synchronization.
 *
 * No data-testid attributes exist in this plugin's templates yet, so selectors here use
 * getByRole/getByLabel/text against the real markup (verified against actual GLPI pages,
 * not guessed).
 */
import { test, expect } from '../lib/manual-recorder';
import { login, routes } from '../lib/glpi';
import { DEMO } from './fixtures';

test.describe.configure({ mode: 'serial' });

test.beforeEach(async ({ page, manual }) => {
  await login(page, manual.locale);
});

test('Editing an existing record & the managed/locked-field state', async ({ manual, page }) => {
  manual.about({
    slug: 'editing-records',
    title: 'Editing an existing record & the managed/locked-field state',
    intro:
      'Domain Manager imports DNS records by syncing them from your provider\'s live account. ' +
      'Some records can be edited here; others are locked because the provider hasn\'t confirmed ' +
      'that your edits would succeed.',
  });

  await manual.step(
    'open-editable-record',
    'Open a record that can be edited',
    'Navigate to a Domain\'s Records tab and click **Edit** on an A or CNAME record. ' +
      'Since this domain\'s DNS provider is configured and working, the form shows an alert warning that ' +
      '**saving updates the record live** at the provider.',
    async () => {
      // Navigate to the first domain (manual-example.com, which is editable)
      await page.goto(routes.domainList);
      await page.getByRole('link', { name: DEMO.domain.name }).click();
      // Go to the Records tab
      await page.getByRole('tab', { name: /Records/ }).click();
      await page.waitForLoadState('networkidle');

      // Navigate directly to edit the A record (domainrecords_id=1 from the fixture)
      // This avoids needing to locate the edit link in the table, which may vary by GLPI layout
      const domainRecordId = 1;
      await page.goto(`${new URL(page.url()).origin}/front/domainrecord.form.php?id=${domainRecordId}`);
      await page.waitForLoadState('networkidle');

      // Screenshot the editable fields (data and TTL)
      const dataField = page.locator('input[name="data"]');
      const ttlField = page.locator('input[name="ttl"]');
      await expect(dataField).not.toBeDisabled();
      await expect(ttlField).not.toBeDisabled();

      const form = page.locator('form#main-form').first();
      await manual.shot('editable-fields', {
        target: form,
        caption: 'The data and TTL fields are editable (no lock icons)',
      });
    },
  );

  await manual.step(
    'demonstrate-edit',
    'Make a change and review before saving',
    'Type a new value (the form doesn\'t require you to submit, so you can review the change). ' +
      'Notice there\'s no lock icon next to **data** or **TTL** — they\'re fully editable.',
    async () => {
      const dataField = page.locator('input[name="data"]');
      const currentValue = await dataField.inputValue();
      const newValue = currentValue === '203.0.113.10' ? '203.0.113.11' : currentValue;
      await dataField.click();
      await dataField.selectText();
      await dataField.fill(newValue);

      const form = page.locator('#main-form');
      await manual.shot('edit-form-changed', {
        target: form,
        caption: 'The form shows your change ready to save (no undo once submitted)',
      });

      // Restore original value (don't actually submit to keep fixture stable)
      await dataField.click();
      await dataField.selectText();
      await dataField.fill(currentValue);
    },
  );

  await manual.step(
    'open-locked-record',
    'Open a record that cannot be edited',
    'Go back to the domain list and open a different domain whose DNS provider is in a ' +
      '**read-only state** (write-back failed or never succeeded). Records on this domain cannot ' +
      'be edited here.',
    async () => {
      // Navigate directly to edit the locked A record (domainrecords_id=3 from the fixture)
      // This is a record on the locked-example.com domain (domains_id=2)
      const lockedRecordId = 3;
      await page.goto(`${new URL(page.url()).origin}/front/domainrecord.form.php?id=${lockedRecordId}`);
      await page.waitForLoadState('networkidle');
    },
  );

  await manual.step(
    'show-locked-alert',
    'See the locked-field explanation',
    'The form shows an alert explaining that this record is **imported by Domain Manager synchronization** ' +
      'and cannot be edited. This happens when the provider hasn\'t confirmed that write-back would succeed.',
    async () => {
      const lockAlert = page.locator('.alert-secondary', {
        has: page.locator('text=imported by Domain Manager synchronization'),
      });
      await expect(lockAlert).toBeVisible();

      await manual.shot('locked-alert', {
        target: lockAlert,
        caption: 'The alert explains why this record cannot be edited',
      });
    },
  );

  await manual.step(
    'show-locked-fields',
    'Notice the lock icons on editable fields',
    'Look at the **data** and **TTL** fields — they have a lock icon next to them, ' +
      'indicating they\'re read-only. The fields are disabled, preventing any changes.',
    async () => {
      // Find the data field and its label (which has the lock icon)
      const dataLabel = page.locator('label', { has: page.locator('text=data') }).first();
      // The lock icon should be visible now
      const lockIcon = dataLabel.locator('.ti-cloud-lock');
      await expect(lockIcon).toBeVisible();

      // Screenshot the disabled field with lock icon
      const fieldRow = dataLabel.locator('..').first();
      await manual.shot('locked-field', {
        target: fieldRow,
        caption: 'The data field has a lock icon and is disabled',
      });

      // Verify fields are actually disabled
      const dataField = page.locator('input[name="data"]');
      const ttlField = page.locator('input[name="ttl"]');
      await expect(dataField).toBeDisabled();
      await expect(ttlField).toBeDisabled();
    },
  );

  await manual.step(
    'understand-locked-state',
    'Why records become locked',
    'Domain Manager locks imported records until a successful write confirms the provider ' +
      'can push updates. Once a write succeeds, these fields unlock. If the provider returns an error, ' +
      'the fields stay locked (the "read-only" state shown here). A user with the ' +
      '**Unlock imported domain data** right can override the lock if needed.',
    async () => {
      // This step is narrative-only; no screenshot.
      // The locked state was already shown in the previous step.
    },
  );
});
