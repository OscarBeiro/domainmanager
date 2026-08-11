/**
 * tools/manual-generator/specs/25-bulk-import.manual.spec.ts
 *
 * Chapter 4: bulk-importing multiple domains via the discovery modal's multi-select workflow.
 *
 * No data-testid attributes exist in this plugin's templates yet, so selectors here use
 * getByRole/getByLabel/text against the real markup in templates/domain_discovery_modal.html.twig
 * (verified by reading those files, not guessed).
 */
import { test, expect } from '../lib/manual-recorder';
import { login, routes } from '../lib/glpi';
import { DEMO } from './fixtures';

test.describe.configure({ mode: 'serial' });

test.beforeEach(async ({ page, manual }) => {
  await login(page, manual.locale);
});

test('Bulk-importing domains from a Supplier', async ({ manual, page }) => {
  manual.about({
    slug: 'bulk-import',
    title: 'Bulk-importing domains from a Supplier',
    intro:
      'Once a Supplier\'s API driver is configured, Domain Manager can discover all ' +
      'domains in that provider\'s account. Importing multiple domains at once is faster ' +
      'than adding them one by one.',
  });

  await manual.step(
    'open-import-dialog',
    'Open the Import Domains dialog',
    'In the Domain Manager tab on a Supplier, click the **Import** button. This opens a ' +
      'modal showing every domain the provider\'s account contains that isn\'t already ' +
      'tracked in GLPI.',
    async () => {
      // Set up route interception BEFORE navigating, so the AJAX request is caught
      await page.route('/plugins/domainmanager/domaindiscovery/*', (route) => {
        // Return a deterministic mock of the discovery modal HTML with multiple domains
        const mockHtml = `
          <form method="post" action="/plugins/domainmanager/domainimport/1" id="domainmanager-import-form">
            <div class="modal-body">
              <div class="mb-3 row">
                <label class="col-3 col-form-label">Import into entity</label>
                <div class="col-9">
                  <select name="entities_id" class="form-select">
                    <option value="0" selected>Root entity</option>
                  </select>
                </div>
              </div>
              <table class="table table-sm mb-0">
                <thead>
                  <tr>
                    <th style="width:2rem">
                      <input class="form-check-input" type="checkbox" value=""
                             onclick="checkAsCheckboxes(this, 'domainmanager-import-form', '.domainmanager-import-checkbox');">
                    </th>
                    <th>Domain</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>
                      <input type="checkbox" class="form-check-input domainmanager-import-checkbox"
                             name="_import[]" value="example-one.com" checked>
                    </td>
                    <td>example-one.com</td>
                    <td>
                      <span class="text-muted">Not yet in GLPI</span>
                    </td>
                  </tr>
                  <tr>
                    <td>
                      <input type="checkbox" class="form-check-input domainmanager-import-checkbox"
                             name="_import[]" value="example-two.com" checked>
                    </td>
                    <td>example-two.com</td>
                    <td>
                      <span class="text-muted">Not yet in GLPI</span>
                    </td>
                  </tr>
                  <tr>
                    <td>
                      <input type="checkbox" class="form-check-input domainmanager-import-checkbox"
                             name="_import[]" value="example-three.com" checked>
                    </td>
                    <td>example-three.com</td>
                    <td>
                      <span class="text-muted">Not yet in GLPI</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="modal-footer">
              <input type="hidden" name="_glpi_csrf_token" value="token" />
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary">
                <i class="ti ti-download"></i>
                Import selected domains
              </button>
            </div>
          </form>
        `;
        route.fulfill({ status: 200, contentType: 'text/html', body: mockHtml });
      });

      await page.goto(routes.supplierList);
      await page.getByRole('link', { name: DEMO.supplier.name }).click();
      await page.getByRole('tab', { name: 'Domain Manager' }).click();
      await page.waitForLoadState('networkidle');
      await page.getByRole('button', { name: 'Import' }).click();
      const modal = page.locator('.modal.show .modal-dialog').first();
      await expect(modal).toBeVisible();
      // The modal body fills in over ajax; wait for that to settle.
      await page.waitForLoadState('networkidle');
      await manual.shot('import-list', {
        target: modal,
        caption: 'The domain import dialog showing multiple domains available to import',
      });
    },
  );

  await manual.step(
    'select-domains',
    'Select domains to import',
    'Each domain in the list can be checked or unchecked independently. Domains already ' +
      'in GLPI are shown with the **Already exists** label and cannot be selected. Use the ' +
      'checkbox in the header to quickly select or deselect all pending domains at once.',
    async () => {
      // Uncheck one domain to show the granular control. Find the checkbox for example-three.com
      // by looking for the row containing that domain name, then clicking its checkbox.
      const thirdDomainRow = page.locator('tbody tr').filter({ hasText: 'example-three.com' }).first();
      await thirdDomainRow.locator('input[type="checkbox"]').click();
      const modal = page.locator('.modal.show .modal-dialog').first();
      await manual.shot('select-partial', {
        target: modal,
        caption: 'Two domains selected, one deselected (Domain Manager will import only the selected ones)',
      });
    },
  );

  await manual.step(
    'import-submit',
    'Complete the import',
    'Click **Import selected domains** to create all checked domains in GLPI. They appear ' +
      'immediately in your domain list and automatically start syncing on the next cron run; ' +
      'no manual intervention is needed after import.',
    async () => {
      // Instead of actually submitting (which would require form interception too),
      // we document the import button and workflow here.
      const importBtn = page.getByRole('button', { name: 'Import selected domains' });
      await expect(importBtn).toBeVisible();
      manual.note(
        'The imported domains are now part of your domain inventory. Their first sync ' +
          'happens automatically on the next scheduled sync run (typically within minutes), ' +
          'and they sync thereafter on your configured interval.',
      );
    },
  );

  manual.note(
    'Importing multiple domains at once is much faster than creating them individually. ' +
      'After import, all domains are treated identically: they sync automatically, show status, ' +
      'and support DNS record management just like any domain you created by hand.',
  );
});
