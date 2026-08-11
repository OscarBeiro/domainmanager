/**
 * playwright.config.ts — capture settings for generated manuals.
 * Place in tools/manual-generator/. Run: npx playwright test --config tools/manual-generator/playwright.config.ts
 *
 * Every setting here exists to make screenshots byte-stable across runs. Changing one
 * rewrites every PNG in the repo, so change deliberately.
 */
import { defineConfig } from '@playwright/test';

const LOCALE = process.env.MANUAL_LOCALE ?? 'en_GB';

export default defineConfig({
  testDir: './specs',
  testMatch: /.*\.manual\.spec\.ts/,

  // Screenshots share one GLPI database restored from the golden fixture: strictly serial.
  workers: 1,
  fullyParallel: false,
  retries: 0,
  // A retried step would re-capture over a good PNG; fail loudly instead.
  forbidOnly: !!process.env.CI,
  timeout: 60_000,
  reporter: process.env.CI ? [['github'], ['list']] : [['list']],

  use: {
    // Never a hardcoded localhost — inside a container that is the container itself.
    baseURL: process.env.BASE_URL ?? 'http://localhost:8080',

    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 2, // 1x looks blurry once it lands in a PDF
    colorScheme: 'light',
    reducedMotion: 'reduce',
    timezoneId: process.env.TZ ?? 'Europe/Madrid',
    locale: LOCALE.replace('_', '-'), // Accept-Language only; UI language = the doc user

    ignoreHTTPSErrors: true,
    actionTimeout: 15_000,
    trace: 'retain-on-failure',
    video: 'off',
  },

  projects: [
    {
      name: LOCALE,
      // Don't spread devices['Desktop Chrome'] here: project-level `use` wins over the
      // global block, and that descriptor would silently reset viewport to 1280x720 and
      // deviceScaleFactor to 1 — quietly undoing the settings above.
      use: { browserName: 'chromium' },
    },
  ],
});
