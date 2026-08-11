/**
 * glpi.ts — GLPI-specific helpers for the manual pipeline.
 *
 * VERIFY ONCE, THEN TRUST THIS FILE. GLPI 11 moved from front/*.php to Symfony routes and
 * its login markup differs between major versions, so the selectors and paths below are
 * starting points to confirm against the running instance — not facts to rely on. Confirm
 * them once, fix them here, and no spec ever needs to know.
 *
 * Place in tools/manual-generator/lib/.
 */
import type { Page } from '@playwright/test';

/**
 * One documentation user per locale, seeded in the golden fixture with its language and a
 * neutral display name. GLPI renders in the *user profile's* language — Playwright's
 * browser locale only sets Accept-Language and will not change the UI.
 *
 * This manual is English-only for now; add locales here (and a matching fixture user)
 * if it grows to cover more.
 */
export const DOC_USERS: Record<string, { user: string; pass: string }> = {
  en_GB: { user: 'manual_en', pass: 'ManualPass1234' },
};

/** Kills every source of pixel jitter that isn't the UI itself. */
export async function stabilise(page: Page): Promise<void> {
  await page.addStyleTag({
    content: `
      *, *::before, *::after {
        animation: none !important;
        transition: none !important;
        caret-color: transparent !important;
      }
      html { scrollbar-width: none; }
      ::-webkit-scrollbar { display: none; }
    `,
  });
}

/**
 * Hide or neutralise regions that change between runs. Extend the list as you find them;
 * anything that varies will otherwise rewrite a PNG on every regeneration.
 */
export async function hideVolatile(page: Page): Promise<void> {
  await page.addStyleTag({
    content: `
      /* GLPI chrome that leaks environment detail into client-facing screenshots */
      .glpi-version, footer .copyright { visibility: hidden !important; }
      #debug-toolbar, .debug-toolbar { display: none !important; }
    `,
  });
}

export async function login(page: Page, locale: string): Promise<void> {
  const creds = DOC_USERS[locale];
  if (!creds) throw new Error(`no documentation user configured for locale ${locale}`);

  // Verified against glpi_glpi_1 (GLPI 11.0.8): login form uses id="login_name"/
  // "login_password" with a "Login" label, and a submit button labelled "Sign in".
  await page.goto('/');
  await page.locator('#login_name').fill(creds.user);
  await page.locator('#login_password').fill(creds.pass);
  await page.getByRole('button', { name: 'Sign in' }).click();

  await page.waitForLoadState('networkidle');
  const failed = await page.getByText('Incorrect username or password').isVisible().catch(() => false);
  if (failed) {
    throw new Error(
      `login failed for ${creds.user} — wrong credentials, or BASE_URL/GLPI_NETWORK point ` +
        'at the wrong GLPI instance (this bit run.sh when it forgot to source manual.env)',
    );
  }
  await stabilise(page);
  await hideVolatile(page);
}

/**
 * Plugin route helper. Domain and Supplier are core GLPI itemtypes — the plugin adds a tab
 * to their existing forms rather than shipping its own pages, so these are classic GLPI 11
 * front/*.php routes, not plugin-specific ones. Keep every path in one place so a routing
 * change is a one-line fix rather than a sweep through the specs.
 */
export const routes = {
  domainList: '/front/domain.php',
  domainForm: (id: number | string) => `/front/domain.form.php?id=${id}`,
  supplierList: '/front/supplier.php',
  supplierForm: (id: number | string) => `/front/supplier.form.php?id=${id}`,
};
