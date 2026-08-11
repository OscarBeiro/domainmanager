/**
 * tools/manual-generator/specs/05-rights-and-profiles.manual.spec.ts
 *
 * Chapter 0 (prefix 05, rendered first): granting Domain Manager rights to a profile.
 *
 * Selectors verified against the running glpi-11-testing-only instance (GLPI 11.0.8): the
 * rights matrix is rendered by core's Profile::displayRightsChoiceMatrix() (see
 * src/Profile.php::getAllRights()/displayProfileForm()), so checkboxes have no
 * data-testid — they use the deterministic `name` attribute the matrix always emits:
 * `_domainmanager:<field>[<bit>_0]`, e.g. `_domainmanager:dns_records_a[4_0]` for CREATE.
 * Bit values follow GLPI's core right constants: UPDATE=2, CREATE=4, DELETE=8, PURGE=16.
 *
 * This spec acts as the Super-Admin documentation user throughout; it never logs in as the
 * restricted user (`manual_en_restricted`) — that user exists in the fixture only so the
 * profile being edited here (Technician, id 6) is a real, in-use profile with rights
 * initially unset, not an abstract example.
 */
import { test, expect } from '../lib/manual-recorder';
import { login } from '../lib/glpi';

test.describe.configure({ mode: 'serial' });

test.beforeEach(async ({ page, manual }) => {
  await login(page, manual.locale);
});

test('Granting Domain Manager rights to a profile', async ({ manual, page }) => {
  manual.about({
    slug: 'rights-and-profiles',
    title: 'Granting Domain Manager rights to a profile',
    intro:
      'Domain Manager adds its own rights to GLPI’s profile system. A profile has no ' +
      'access to the plugin’s protected actions until an administrator grants them here — ' +
      'this is the first thing to check if a user reports missing DNS record buttons or an ' +
      'un-editable synced field.',
  });

  await manual.step(
    'open-profile',
    'Open the profile to edit',
    'Go to **Administration → Profiles** and open the profile to grant rights to — here, ' +
      '**Technician**.',
    async () => {
      await page.goto('/front/profile.php');
      await page.getByRole('link', { name: 'Technician' }).click();
      await expect(page.getByRole('tab', { name: 'Domain Manager' })).toBeVisible();
    },
  );

  await manual.step(
    'open-tab',
    'Open the Domain Manager tab',
    'Select the **Domain Manager** tab. It lists every right the plugin defines: unlocking ' +
      'fields and records that came from a synchronization, and per-record-type write-back ' +
      'rights (Create/Update/Delete/Purge) for each DNS record type the plugin can push to ' +
      'a provider.',
    async () => {
      await page.getByRole('tab', { name: 'Domain Manager' }).click();
      // The tab's content is fetched over ajax; screenshotting before that settles can grab
      // a detached mid-swap DOM node (visible one instant, blank the next).
      await page.waitForLoadState('networkidle');
      const matrix = page.locator('table', { has: page.getByText('Domain Record: A') });
      await expect(matrix).toBeVisible();
      await manual.shot('rights-matrix-before', {
        target: matrix,
        caption: 'The Domain Manager rights matrix, before any right is granted',
      });
    },
  );

  await manual.step(
    'grant-rights',
    'Grant unlock and A-record write-back rights',
    'Tick **Edit fields and records imported by synchronization** to let this profile ' +
      'override plugin-managed locks on the native form, and tick **Create**/**Update** on ' +
      '**Domain Record: A** to let it push new and changed A records to the provider. ' +
      'Leave the other record types and the destructive Delete/Purge bits unchecked — grant ' +
      'only what a role actually needs.',
    async () => {
      await page.locator('input[type=checkbox][name="_domainmanager\\:unlock_imported\\[1_0\\]"]').check();
      await page.locator('input[type=checkbox][name="_domainmanager\\:dns_records_a\\[4_0\\]"]').check();
      await page.locator('input[type=checkbox][name="_domainmanager\\:dns_records_a\\[2_0\\]"]').check();
      const matrix = page.locator('table', { has: page.getByText('Domain Record: A') });
      await manual.shot('rights-matrix-checked', {
        target: matrix,
        caption: 'Unlock and A-record Create/Update ticked, not yet saved',
      });
    },
  );

  await manual.step(
    'save',
    'Save the profile',
    'Click **Save**. The rights take effect immediately for every user with this profile.',
    async () => {
      await page.getByRole('button', { name: 'Save' }).click();
      await expect(page.getByRole('tab', { name: 'Domain Manager' })).toBeVisible();
      const checked = page.locator('input[type=checkbox][name="_domainmanager\\:unlock_imported\\[1_0\\]"]');
      await expect(checked).toBeChecked();
    },
  );

  manual.note(
    'Delete and Purge are separate bits from Create/Update on each record-type right: a ' +
      'profile can be trusted to push new and changed records without also being able to ' +
      'remove them from the provider.',
  );
});
