#!/usr/bin/env node
/**
 * render-manual.mjs — manifests → 01-intro.md / 02-setup.md / 03-usage.md / 04-troubleshooting.md
 *
 * Place in tools/manual-generator/. Run after a capture pass, from the repo root:
 *   MANUAL_LOCALE=es_ES node tools/manual-generator/render-manual.mjs
 *
 * The manual is four numbered, cross-linked files instead of one monolithic MANUAL.md:
 *   1. Introduction   — 01-intro.md   (KB-sourced: what/why/features/providers/impacted
 *                        elements, all reference-only — no setup or usage detail)
 *   2. Setup           — 02-setup.md  (KB-sourced install/config/permissions/automatic
 *                        actions, plus the rights-and-profiles and supplier-setup chapters)
 *   3. Usage           — 03-usage.md  (KB "How to use", plus every remaining chapter)
 *   4. Troubleshooting — 04-troubleshooting.md, generated from the hand-written
 *      _troubleshooting.md (no automatable source for troubleshooting content)
 * _troubleshooting.md is hand-written and the renderer never overwrites it. Section and
 * subsection numbers (2.3, 2.3.1, ...) are assigned by this script from the chapter order
 * below, so inserting/reordering a chapter here renumbers the manual consistently.
 */
import fs from 'node:fs/promises';
import path from 'node:path';

const LOCALE = process.env.MANUAL_LOCALE ?? 'en_GB';
const ROOT = process.env.MANUAL_ROOT ?? path.join('docs', 'manual');
const OUT = process.env.MANUAL_OUT ?? path.join(ROOT, LOCALE);
const KB_DIR = process.env.MANUAL_KB_DIR ?? path.join('docs', 'kb');

const STRINGS = {
  en_GB: {
    note: 'Note', banner: (p, g, d) =>
      `Generated for **${p}** on GLPI ${g} — ${d}. Screenshots are produced automatically; do not edit generated sections by hand.`,
    intro: 'Introduction', setup: 'Setup', usage: 'Usage', troubleshooting: 'Troubleshooting',
    seeAlso: 'See also',
  },
};
const S = STRINGS[LOCALE] ?? STRINGS.en_GB;

// Mirrors GitHub's own heading-anchor algorithm (strip punctuation, keep spaces, lowercase,
// spaces -> hyphens) so links like [2.2 Configuration](02-setup.md#22-configuration) resolve.
const slugify = (s) => s.toLowerCase().replace(/[^\p{L}\p{N}\s-]/gu, '').trim().replace(/\s+/g, '-');
const anchor = (heading) => `#${slugify(heading)}`;

async function readIfPresent(p) {
  try { return (await fs.readFile(p, 'utf8')).trim(); } catch { return ''; }
}

/** Locate the plugin's KB/marketplace-style doc (docs/kb/*.md, or MANUAL_KB override). */
async function findKbFile() {
  if (process.env.MANUAL_KB) return process.env.MANUAL_KB;
  let files;
  try {
    files = (await fs.readdir(KB_DIR)).filter((f) => f.endsWith('.md'));
  } catch {
    return null;
  }
  return files.length === 1 ? path.join(KB_DIR, files[0]) : null;
}

/** Split a KB doc into { headingText: fullBlockIncludingHeadingAndNestedSubheadings }. */
function parseKbSections(md) {
  const sections = new Map();
  let current = null;
  let buf = [];
  for (const line of md.split('\n')) {
    const h2 = line.match(/^##\s+(.+)$/);
    if (h2) {
      if (current) sections.set(current, buf.join('\n').trim());
      current = h2[1].trim();
      buf = [line];
    } else if (current) {
      buf.push(line);
    }
  }
  if (current) sections.set(current, buf.join('\n').trim());
  return sections;
}

/** Strip a KB section's own "## Heading" line — the caller re-numbers and re-emits it. */
function stripHeading(block) {
  return block ? block.replace(/^##\s+.+\n?/, '').trim() : '';
}

/** First bullet-list line's leading label (before the em dash/colon), for a names-only reference list. */
function namesOnly(block) {
  if (!block) return '';
  const names = [];
  for (const line of block.split('\n')) {
    const bullet = line.match(/^-\s+(.+)$/);
    if (bullet) {
      const label = bullet[1].split(/\s+—\s+|\s+-\s+|:/)[0].trim();
      names.push(label);
      continue;
    }
    const row = line.match(/^\|\s*([^|]+?)\s*\|/);
    if (row && !/^-+$/.test(row[1]) && !/^name$/i.test(row[1])) names.push(row[1].trim());
  }
  return names.length ? names.map((n) => `- ${n}`).join('\n') : '';
}

/** Plugin name and version from setup.php; env wins if provided. */
async function pluginInfo() {
  let name = process.env.PLUGIN_NAME ?? path.basename(process.cwd());
  let version = process.env.PLUGIN_VERSION ?? '';
  if (!version) {
    const setup = await readIfPresent('setup.php');
    version = setup.match(/define\(\s*'PLUGIN_\w+_VERSION'\s*,\s*'([^']+)'/)?.[1] ?? 'unknown';
    const declared = setup.match(/PLUGIN_(\w+)_VERSION/)?.[1];
    if (declared && !process.env.PLUGIN_NAME) name = declared.toLowerCase();
  }
  return { name, version, glpi: process.env.GLPI_VERSION ?? '11.0' };
}

async function loadManifests() {
  const dir = path.join(OUT, '.manifests');
  let files;
  try {
    files = (await fs.readdir(dir)).filter((f) => f.endsWith('.json'));
  } catch {
    throw new Error(`no manifests in ${dir} — run the capture pass first`);
  }
  if (files.length === 0) throw new Error(`no manifests in ${dir} — run the capture pass first`);
  const all = await Promise.all(
    files.map(async (f) => JSON.parse(await fs.readFile(path.join(dir, f), 'utf8'))),
  );
  return all.sort((a, b) => a.order - b.order || a.slug.localeCompare(b.slug));
}

/** Chapter asset folders are prefixed with the zero-padded chapter position so they sort like the manual. */
function assetDirName(chapterNum, slug) {
  return `${String(chapterNum).padStart(2, '0')}-${slug}`;
}

/**
 * Rename each manifest's assets/<slug> folder to assets/<N>-<slug>, N being its 1-based
 * position in manifest order (independent of which document section it lands in).
 * Idempotent: a folder already carrying its current prefix is left alone.
 */
async function renumberAssetDirs(manifests) {
  const assetsRoot = path.join(OUT, 'assets');
  for (const [i, m] of manifests.entries()) {
    const wanted = assetDirName(i + 1, m.slug);
    const wantedAbs = path.join(assetsRoot, wanted);
    const alreadyRenamed = await fs.stat(wantedAbs).then(() => true, () => false);
    if (alreadyRenamed) continue;
    let entries;
    try {
      entries = await fs.readdir(assetsRoot);
    } catch {
      continue;
    }
    const stale = entries.find((e) => e === m.slug || e.endsWith(`-${m.slug}`));
    if (stale && stale !== wanted) {
      await fs.rename(path.join(assetsRoot, stale), wantedAbs);
    }
  }
}

/** Render one chapter under a dotted section prefix, e.g. prefix "2.3" -> "2.3", "2.3.1", "2.3.2"... */
function renderChapter(m, assetDir, prefix) {
  const out = [`## ${prefix} ${m.title}`, ''];
  if (m.intro) out.push(m.intro, '');
  for (const step of m.steps) {
    out.push(`### ${prefix}.${step.seq} ${step.title}`, '');
    if (step.body) out.push(step.body, '');
    for (const shot of step.shots) {
      const alt = (shot.caption ?? `${m.title} — ${step.title}`).replace(/[[\]]/g, '');
      out.push(`![${alt}](assets/${assetDir}/${shot.file})`, '');
      if (shot.caption) out.push(`*${shot.caption}*`, '');
    }
    for (const note of step.notes) out.push(`> **${S.note}:** ${note}`, '');
  }
  return out.join('\n');
}

const main = async () => {
  const { name, version, glpi } = await pluginInfo();
  const manifests = await loadManifests();
  await renumberAssetDirs(manifests);
  const date = new Date().toISOString().slice(0, 10);
  const banner = `> ${S.banner(`${name} ${version}`, glpi, date)}`;

  const kbPath = await findKbFile();
  const kb = kbPath ? parseKbSections(await fs.readFile(kbPath, 'utf8')) : new Map();

  // Chapters split between Setup (2.x) and Usage (3.x); order here drives numbering.
  const setupSlugs = ['rights-and-profiles', 'supplier-setup'];
  const bySlug = new Map(manifests.map((m, i) => [m.slug, { m, assetDir: assetDirName(i + 1, m.slug) }]));
  const setupChapters = setupSlugs.map((s) => bySlug.get(s)).filter(Boolean);
  const usageChapters = manifests
    .map((m, i) => ({ m, assetDir: assetDirName(i + 1, m.slug) }))
    .filter(({ m }) => !setupSlugs.includes(m.slug));

  // ---- 1. Introduction ----------------------------------------------------
  const impactedNamesOnly = namesOnly(kb.get('Impacted GLPI items'));
  const introDoc = [
    `# 1. ${S.intro}`,
    '',
    banner,
    '',
    `*Part of the ${name} manual — see also [2. Setup](02-setup.md), [3. Usage](03-usage.md), [4. Troubleshooting](04-troubleshooting.md).*`,
    '',
    '## 1.1 What is Domain Manager',
    '',
    stripHeading(kb.get('Description')),
    '',
    '## 1.2 Pain points it addresses',
    '',
    stripHeading(kb.get('Why this plugin?')),
    '',
    '## 1.3 Features',
    '',
    stripHeading(kb.get('Features list')),
    '',
    '## 1.4 Supported nameservers & drivers',
    '',
    stripHeading(kb.get('Supported providers')),
    '',
    '## 1.5 Affected GLPI elements',
    '',
    'Reference only — see [2. Setup](02-setup.md) for how to configure each of these.',
    '',
    '### 1.5.1 Assets, management & administration items',
    '',
    (stripHeading(kb.get('Impacted GLPI items')) || impactedNamesOnly).replace(/^###\s+/gm, '**').replace(/^(\*\*.+)$/gm, '$1**'),
    '',
    '### 1.5.2 Automatic actions',
    '',
    namesOnly(kb.get('Automatic Actions')) || '_None._',
    '',
    `See [2.2 Configuration](02-setup.md${anchor('2.2 Configuration')}).`,
    '',
    '### 1.5.3 Notifications',
    '',
    stripHeading(kb.get('Notifications')) || '_None._',
    '',
    '### 1.5.4 Rules',
    '',
    stripHeading(kb.get('Rules')) || '_None._',
    '',
    '### 1.5.5 Permissions',
    '',
    namesOnly(kb.get('Permissions')) || '_None._',
    '',
    `See [2.3 Permissions](02-setup.md${anchor('2.3 Permissions')}).`,
    '',
  ].join('\n');

  // ---- 2. Setup -------------------------------------------------------------
  const automaticActionsBlock = stripHeading(kb.get('Automatic Actions'));
  const setupParts = [
    `# 2. ${S.setup}`,
    banner,
    `*Part of the ${name} manual — see also [1. Introduction](01-intro.md), [3. Usage](03-usage.md), [4. Troubleshooting](04-troubleshooting.md).*`,
    '## 2.1 Installation',
    (stripHeading(kb.get('Setup')).match(/### Installation\n([\s\S]*?)(?=\n### |$)/)?.[1] ?? '').trim(),
    '## 2.2 Configuration',
    (stripHeading(kb.get('Setup')).match(/### Configuration\n([\s\S]*?)(?=\n### |$)/)?.[1] ?? '').trim(),
  ];
  if (automaticActionsBlock) setupParts.push('### 2.2.1 Automatic actions', automaticActionsBlock);
  setupParts.push(
    '## 2.3 Permissions',
    stripHeading(kb.get('Permissions')),
    ...setupChapters.map(({ m, assetDir }, i) => renderChapter(m, assetDir, `2.${4 + i}`)),
  );
  const setupDoc = setupParts.join('\n\n');

  // ---- 3. Usage ---------------------------------------------------------
  const usageDoc = [
    `# 3. ${S.usage}`,
    '',
    banner,
    '',
    `*Part of the ${name} manual — see also [1. Introduction](01-intro.md), [2. Setup](02-setup.md), [4. Troubleshooting](04-troubleshooting.md).*`,
    '',
    '## 3.1 How to use',
    '',
    (stripHeading(kb.get('How to use'))),
    '',
    ...usageChapters.map(({ m, assetDir }, i) => renderChapter(m, assetDir, `3.${i + 2}`)),
  ].join('\n');

  // ---- 4. Troubleshooting (hand-written, never overwritten) ------------
  // Hand-written source lives at _troubleshooting.md (never overwritten by this script);
  // 04-troubleshooting.md itself is the generated file this section produces.
  const troubleshootingBody = (
    await readIfPresent(path.join(OUT, '_troubleshooting.md'))
    || await readIfPresent(path.join(OUT, '4-troubleshooting.md'))
  ).replace(/^##\s+Troubleshooting\s*\n/, '').trim();
  const troubleshootingDoc = [
    '# 4. Troubleshooting',
    '',
    banner,
    '',
    `*Part of the ${name} manual — see also [1. Introduction](01-intro.md), [2. Setup](02-setup.md), [3. Usage](03-usage.md).*`,
    '',
    troubleshootingBody,
    '',
  ].join('\n');

  const files = {
    '01-intro.md': introDoc,
    '02-setup.md': setupDoc,
    '03-usage.md': usageDoc,
    '04-troubleshooting.md': troubleshootingDoc,
  };

  for (const [filename, content] of Object.entries(files)) {
    const target = path.join(OUT, filename);
    await fs.writeFile(target, content.replace(/\n{3,}/g, '\n\n').trimEnd() + '\n', 'utf8');
  }

  const shots = manifests.reduce((n, m) => n + m.steps.reduce((k, s) => k + s.shots.length, 0), 0);
  console.log(`${OUT}: 4 files (01-intro, 02-setup, 03-usage, 04-troubleshooting), ${manifests.length} chapters, ${shots} screenshots, ${LOCALE}`);
};

main().catch((err) => {
  console.error(String(err.message ?? err));
  process.exit(1);
});
