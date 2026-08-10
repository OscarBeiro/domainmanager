/**
 * manual-recorder.ts — narrated-test fixture for generated manuals.
 *
 * A spec describes a user task once; this records both what happened and what to say
 * about it, so the prose and the screenshots cannot drift apart.
 *
 * Place in docs/manual/lib/.
 */
import { test as base, expect, type Locator, type Page, type TestInfo } from '@playwright/test';
import fs from 'node:fs/promises';
import path from 'node:path';

export interface ManualMeta {
  /** kebab-case id; names the asset folder and the manifest file */
  slug: string;
  /** chapter heading in the manual */
  title: string;
  /** optional paragraph under the chapter heading */
  intro?: string;
}

interface Shot {
  file: string;
  caption?: string;
}

interface Step {
  seq: number;
  id: string;
  title: string;
  body: string;
  shots: Shot[];
  notes: string[];
}

export interface Manifest extends ManualMeta {
  order: number;
  locale: string;
  generatedAt: string;
  steps: Step[];
}

export class ManualRecorder {
  private meta?: ManualMeta;
  private readonly steps: Step[] = [];
  private current?: Step;
  private shotSeq = 0;

  constructor(
    private readonly page: Page,
    private readonly info: TestInfo,
    readonly locale: string,
    readonly outDir: string,
    private readonly messages: Record<string, string> | null,
  ) {}

  /** Declare the chapter. Call once, first, before any step. */
  about(meta: ManualMeta): void {
    this.meta = meta;
  }

  /**
   * Record a chapter step and run its actions.
   *
   * `body` is either literal Markdown prose, or — for multi-locale manuals — a message
   * key resolved against docs/manual/i18n/<locale>.json (see references/
   * authoring-and-output.md). A missing translation is marked, not silently skipped.
   */
  async step(id: string, title: string, body: string, fn: () => Promise<void>): Promise<void> {
    if (!this.meta) throw new Error('call manual.about({...}) before the first step()');
    const step: Step = {
      seq: this.steps.length + 1,
      id,
      title,
      body: this.resolve(`${this.meta.slug}.${id}`, body),
      shots: [],
      notes: [],
    };
    this.steps.push(step);
    this.current = step;
    try {
      await base.step(`${step.seq}. ${title}`, fn);
    } finally {
      this.current = undefined;
    }
  }

  /** A callout rendered after the current step (or the chapter, outside a step). */
  note(markdown: string): void {
    (this.current ?? this.steps[this.steps.length - 1])?.notes.push(markdown);
  }

  /**
   * Capture a screenshot for the current step.
   *
   * Scope to a locator by default — a full-page GLPI screenshot is mostly navigation
   * chrome. `mask` blocks out volatile or sensitive regions; for values that should look
   * natural, substitute them with CSS instead (see references/determinism-and-fixtures.md).
   */
  async shot(
    name: string,
    opts: { target?: Locator; fullPage?: boolean; mask?: Locator[]; caption?: string } = {},
  ): Promise<void> {
    if (!this.current) throw new Error('manual.shot() must be called inside manual.step()');
    const file = `${String(++this.shotSeq).padStart(2, '0')}-${name}.png`;
    const abs = path.join(this.assetDir, file);
    await fs.mkdir(path.dirname(abs), { recursive: true });

    const common = {
      path: abs,
      animations: 'disabled' as const,
      mask: opts.mask ?? [],
      maskColor: '#94a3b8',
    };
    if (opts.target) {
      await expect(opts.target).toBeVisible(); // never capture a half-rendered panel
      await opts.target.screenshot(common);
    } else {
      await this.page.screenshot({ ...common, fullPage: opts.fullPage ?? false });
    }
    this.current.shots.push({ file, caption: opts.caption });
  }

  private get assetDir(): string {
    return path.join(this.outDir, 'assets', this.meta!.slug);
  }

  private resolve(key: string, fallback: string): string {
    if (!this.messages) return fallback;
    const hit = this.messages[key];
    if (hit) return hit;
    // Only treat the body as a key if it looks like one; otherwise it is literal prose.
    return /^[a-z0-9-]+\.[a-z0-9-]+$/i.test(fallback) ? `\`[untranslated: ${key}]\`` : fallback;
  }

  /** Ordering comes from the spec filename prefix: 10-domains.manual.spec.ts → 10 */
  private get order(): number {
    const m = path.basename(this.info.file).match(/^(\d+)/);
    return m ? Number(m[1]) : 999;
  }

  async writeManifest(): Promise<void> {
    if (!this.meta || this.steps.length === 0) return;
    const manifest: Manifest = {
      ...this.meta,
      order: this.order,
      locale: this.locale,
      generatedAt: new Date().toISOString(),
      steps: this.steps,
    };
    const dir = path.join(this.outDir, '.manifests');
    await fs.mkdir(dir, { recursive: true });
    await fs.writeFile(
      path.join(dir, `${this.meta.slug}.json`),
      JSON.stringify(manifest, null, 2) + '\n',
      'utf8',
    );
  }
}

async function loadMessages(locale: string): Promise<Record<string, string> | null> {
  try {
    const raw = await fs.readFile(path.join('docs', 'manual', 'i18n', `${locale}.json`), 'utf8');
    return JSON.parse(raw) as Record<string, string>;
  } catch {
    return null; // single-locale manual: prose lives inline in the specs
  }
}

export const test = base.extend<{ manual: ManualRecorder }>({
  manual: async ({ page }, use, testInfo) => {
    const locale = process.env.MANUAL_LOCALE ?? 'en_GB';
    const outDir = process.env.MANUAL_OUT ?? path.join('docs', 'manual', locale);
    const recorder = new ManualRecorder(page, testInfo, locale, outDir, await loadMessages(locale));

    await use(recorder);

    // A failed run must not publish half a chapter — the previous manifest stays valid.
    if (testInfo.status === testInfo.expectedStatus) {
      await recorder.writeManifest();
    }
  },
});

export { expect };
