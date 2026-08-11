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
 *
 * This run also (re)generates a per-locale KB deliverable, docs/kb/<slug>.<locale>.md, from
 * the single hand-written docs/kb/<slug>.md — same run, so the two documents never drift.
 * Its `<!-- shot: <chapter-slug>/<filename-without-ext> -->` markers resolve to real image
 * embeds pointing at the *manual's own* screenshots (shared files, never copied or
 * regenerated) under the base locale's assets/ — see loadLabelGlossary's header comment for
 * why no locale other than MANUAL_BASE_LOCALE ever gets its own screenshots.
 */
import fs from 'node:fs/promises';
import path from 'node:path';

const LOCALE = process.env.MANUAL_LOCALE ?? 'en_GB';
// The only locale screenshots are ever captured for. Every other locale's manual and KB
// deliverable reuse these same PNGs verbatim — see the header comment above and Trap 15 in
// the glpi-plugin-manual-generator skill: a screenshot shows GLPI's UI, and re-capturing it
// per locale is exactly the churn this pipeline exists to avoid paying twice for.
const BASE_LOCALE = process.env.MANUAL_BASE_LOCALE ?? 'en_GB';
const ROOT = process.env.MANUAL_ROOT ?? path.join('docs', 'manual');
const OUT = process.env.MANUAL_OUT ?? path.join(ROOT, LOCALE);
const KB_DIR = process.env.MANUAL_KB_DIR ?? path.join('docs', 'kb');
const KB_I18N_DIR = process.env.MANUAL_KB_I18N_DIR ?? path.join(KB_DIR, 'i18n');
const LABELS_DIR = process.env.MANUAL_LABELS_DIR ?? path.join('tools', 'manual-generator', 'i18n');

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

// Matches the per-locale KB deliverables this script itself generates (<slug>.<locale>.md,
// e.g. "domain-manager.en_GB.md") so findKbFile() below doesn't mistake its own previous
// output for a second hand-written source and give up disambiguating.
const KB_LOCALE_SUFFIX_RE = /\.[a-z]{2,3}(?:_[A-Z]{2,3})?\.md$/;

/** Locate the plugin's hand-written KB/marketplace-style doc (docs/kb/*.md, or MANUAL_KB override). */
async function findKbFile() {
  if (process.env.MANUAL_KB) return process.env.MANUAL_KB;
  let files;
  try {
    files = (await fs.readdir(KB_DIR))
      .filter((f) => f.endsWith('.md') && !KB_LOCALE_SUFFIX_RE.test(f));
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

// A manual is a client deliverable: it must never advertise a dev/alpha/beta/rc build as
// if it were the released product. Matches a trailing -dev, -alpha, -beta, -rc (optionally
// followed by a number), case-insensitively, e.g. "1.7.1-beta2", "2.0.0-rc1", "1.0-dev".
const PRERELEASE_RE = /-(dev|alpha|beta|rc)\.?\d*$/i;

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
  if (PRERELEASE_RE.test(version) && process.env.MANUAL_ALLOW_PRERELEASE !== '1') {
    throw new Error(
      `refusing to build the manual for pre-release version '${version}' — this pipeline ` +
      `only targets production releases (no -dev/-alpha/-beta/-rc). Bump the version past ` +
      `the pre-release stage before regenerating, or set MANUAL_ALLOW_PRERELEASE=1 for a ` +
      `local-only test render.`,
    );
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

/**
 * Render one chapter under a dotted section prefix, e.g. prefix "2.3" -> "2.3", "2.3.1",
 * "2.3.2"... `localize` runs over every piece of prose (chapter intro, step body, notes) —
 * the step's own screenshot is never touched, since it was captured once against the base
 * locale's UI and is reused as-is for every other locale (Trap 15).
 */
function renderChapter(m, assetDir, prefix, localize) {
  const out = [`## ${prefix} ${m.title}`, ''];
  if (m.intro) out.push(localize(m.intro), '');
  for (const step of m.steps) {
    out.push(`### ${prefix}.${step.seq} ${step.title}`, '');
    if (step.body) out.push(localize(step.body), '');
    for (const shot of step.shots) {
      const alt = (shot.caption ?? `${m.title} — ${step.title}`).replace(/[[\]]/g, '');
      out.push(`![${alt}](assets/${assetDir}/${shot.file})`, '');
      if (shot.caption) out.push(`*${shot.caption}*`, '');
    }
    for (const note of step.notes) out.push(`> **${S.note}:** ${localize(note)}`, '');
  }
  return out.join('\n');
}

/**
 * Load { English label -> localized label } for LOCALE from tools/manual-generator/i18n/
 * labels.<locale>.json, e.g. { "Setup": "Configuración", "General": "General" }. Returns
 * null for the base locale (nothing to localize) or when no glossary file exists yet.
 *
 * This glossary is NOT a translation exercise for an LLM to do — every value in it must come
 * from GLPI's own rendered UI (core + this plugin) in that locale: log in as that locale's
 * documentation user (the fixture already has one per Trap 4) and copy the real label text
 * from the DOM, once, into this file. A glossary entry that was guessed/machine-translated
 * instead of read off the actual UI is worse than a missing one — a reader trusts it to be
 * the literal button/menu text they'll see, not an approximation of it.
 */
async function loadLabelGlossary() {
  if (LOCALE === BASE_LOCALE) return null;
  const raw = await readIfPresent(path.join(LABELS_DIR, `labels.${LOCALE}.json`));
  if (!raw) return null;
  try {
    return new Map(Object.entries(JSON.parse(raw)));
  } catch {
    throw new Error(`labels.${LOCALE}.json is not valid JSON — fix or delete it`);
  }
}

/**
 * Append each glossary hit as "**English (Localized)**" beside every bolded UI-label mention
 * this pipeline emits — KB prose, Setup/Intro text pulled from the KB, and chapter step
 * bodies alike. Longest keys are substituted first so "Setup > Automatic actions" doesn't
 * get its "Setup" swapped out from under the more specific match. A no-op when `glossary`
 * is null (base locale, or no glossary file yet for this locale).
 */
function localizeLabels(text, glossary) {
  if (!text || !glossary) return text;
  const keys = [...glossary.keys()].sort((a, b) => b.length - a.length);
  let out = text;
  for (const key of keys) {
    const escaped = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    out = out.replace(new RegExp(`\\*\\*${escaped}\\*\\*`, 'g'), `**${key} (${glossary.get(key)})**`);
  }
  return out;
}

/**
 * Resolve `<!-- shot: <chapter-slug>/<filename-without-ext> --> markers in the KB doc into
 * real image embeds against the *manual's own* screenshots — same PNG files the manual
 * itself references, always under the base locale's assets/ regardless of which locale's KB
 * deliverable is being rendered (Trap 15: no locale but the base one ever gets its own
 * screenshots). Throws on a marker whose chapter/file doesn't exist, rather than silently
 * dropping the image — a KB doc referencing a screenshot that was never captured is a bug in
 * the marker, not something to paper over.
 */
// `assetsPathPrefix` differs by consumer: the manual's own 0N-*.md files live inside
// docs/manual/<LOCALE>/ already, right next to assets/, so they need a bare "assets/...";
// the KB deliverable lives in docs/kb/, so it needs to reach across into
// "../manual/<BASE_LOCALE>/assets/...". Same markers, same underlying PNGs either way —
// only the relative path differs.
function resolveKbShotMarkers(text, manifests, assetsPathPrefix) {
  const assetDirByChapterSlug = new Map(manifests.map((m, i) => [m.slug, assetDirName(i + 1, m.slug)]));
  const shotFilesByChapterSlug = new Map(
    manifests.map((m) => [m.slug, new Set(m.steps.flatMap((s) => s.shots.map((sh) => sh.file)))]),
  );
  return text.replace(/<!--\s*shot:\s*([\w-]+)\/([\w-]+)\s*-->/g, (whole, chapterSlug, fileStem) => {
    const assetDir = assetDirByChapterSlug.get(chapterSlug);
    const file = `${fileStem}.png`;
    if (!assetDir || !shotFilesByChapterSlug.get(chapterSlug)?.has(file)) {
      throw new Error(`KB doc shot marker '${chapterSlug}/${fileStem}' has no matching chapter/screenshot`);
    }
    return `![${chapterSlug} — ${fileStem}](${assetsPathPrefix}/${assetDir}/${file})`;
  });
}

/**
 * docs/kb/<slug>.<locale>.md — generated in the same run as the manual from the single
 * hand-written docs/kb/<slug>.md, with its shot markers resolved and (for any locale but the
 * base one) its bolded UI labels given a localized twin from the glossary. Never overwrites
 * the hand-written source it reads from — that file has no locale suffix.
 */
async function renderKbDoc(kbPath, kbRawWithShots, glossary) {
  if (!kbPath) return;
  const localized = localizeLabels(kbRawWithShots, glossary);
  const slug = path.basename(kbPath, '.md');
  const target = path.join(KB_DIR, `${slug}.${LOCALE}.md`);
  await fs.writeFile(target, localized.trimEnd() + '\n', 'utf8');
  console.log(`${target}: generated from ${kbPath} (${LOCALE}${glossary ? ', localized labels' : ''})`);
}

const main = async () => {
  const { name, version, glpi } = await pluginInfo();
  const manifests = await loadManifests();
  await renumberAssetDirs(manifests);
  const date = new Date().toISOString().slice(0, 10);
  const banner = `> ${S.banner(`${name} ${version}`, glpi, date)}`;

  // Shot markers are resolved once, here, on the raw KB text — before it's split into
  // sections — so the manual's Intro/Setup pull-through and the KB deliverable both get the
  // same embedded images from the same source text, rather than the manual leaking raw
  // `<!-- shot: ... -->` comments because only the KB doc's own render step resolved them.
  // Two variants because the two consumers sit in different directories (see
  // resolveKbShotMarkers's header comment for why the path prefix differs).
  const kbPath = await findKbFile();
  const kbSourceText = kbPath ? await fs.readFile(kbPath, 'utf8') : '';
  const kbRawForManual = kbPath ? resolveKbShotMarkers(kbSourceText, manifests, 'assets') : '';
  const kbRawForKb = kbPath ? resolveKbShotMarkers(kbSourceText, manifests, `../manual/${BASE_LOCALE}/assets`) : '';
  const kb = kbPath ? parseKbSections(kbRawForManual) : new Map();
  const glossary = await loadLabelGlossary();
  // Every mention of a KB section below (and step prose from a chapter manifest, which was
  // authored once against the base locale's UI — Trap 15) is routed through this, not just
  // raw kb.get() calls, so "**Setup**" reads "**Setup (Configuración)**" everywhere it
  // appears, not only inside the KB deliverable itself.
  const localize = (text) => localizeLabels(text, glossary);

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
    localize(stripHeading(kb.get('Description'))),
    '',
    '## 1.2 Pain points it addresses',
    '',
    localize(stripHeading(kb.get('Why this plugin?'))),
    '',
    '## 1.3 Features',
    '',
    localize(stripHeading(kb.get('Features list'))),
    '',
    '## 1.4 Supported nameservers & drivers',
    '',
    localize(stripHeading(kb.get('Supported providers'))),
    '',
    '## 1.5 Affected GLPI elements',
    '',
    'Reference only — see [2. Setup](02-setup.md) for how to configure each of these.',
    '',
    '### 1.5.1 Assets, management & administration items',
    '',
    localize((stripHeading(kb.get('Impacted GLPI items')) || impactedNamesOnly).replace(/^###\s+/gm, '**').replace(/^(\*\*.+)$/gm, '$1**')),
    '',
    '### 1.5.2 Automatic actions',
    '',
    namesOnly(kb.get('Automatic Actions')) || '_None._',
    '',
    `See [2.2 Configuration](02-setup.md${anchor('2.2 Configuration')}).`,
    '',
    '### 1.5.3 Notifications',
    '',
    localize(stripHeading(kb.get('Notifications'))) || '_None._',
    '',
    '### 1.5.4 Rules',
    '',
    localize(stripHeading(kb.get('Rules'))) || '_None._',
    '',
    '### 1.5.5 Permissions',
    '',
    namesOnly(kb.get('Permissions')) || '_None._',
    '',
    `See [2.3 Permissions](02-setup.md${anchor('2.3 Permissions')}).`,
    '',
  ].join('\n');

  // ---- 2. Setup -------------------------------------------------------------
  const automaticActionsBlock = localize(stripHeading(kb.get('Automatic Actions')));
  const setupParts = [
    `# 2. ${S.setup}`,
    banner,
    `*Part of the ${name} manual — see also [1. Introduction](01-intro.md), [3. Usage](03-usage.md), [4. Troubleshooting](04-troubleshooting.md).*`,
    '## 2.1 Installation',
    localize((stripHeading(kb.get('Setup')).match(/### Installation\n([\s\S]*?)(?=\n### |$)/)?.[1] ?? '').trim()),
    '## 2.2 Configuration',
    localize((stripHeading(kb.get('Setup')).match(/### Configuration\n([\s\S]*?)(?=\n### |$)/)?.[1] ?? '').trim()),
  ];
  if (automaticActionsBlock) setupParts.push('### 2.2.1 Automatic actions', automaticActionsBlock);
  setupParts.push(
    '## 2.3 Permissions',
    localize(stripHeading(kb.get('Permissions'))),
    ...setupChapters.map(({ m, assetDir }, i) => renderChapter(m, assetDir, `2.${4 + i}`, localize)),
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
    localize(stripHeading(kb.get('How to use'))),
    '',
    ...usageChapters.map(({ m, assetDir }, i) => renderChapter(m, assetDir, `3.${i + 2}`, localize)),
  ].join('\n');

  // ---- 4. Troubleshooting (hand-written, never overwritten) ------------
  // Hand-written source lives at _troubleshooting.md (never overwritten by this script);
  // 04-troubleshooting.md itself is the generated file this section produces.
  const troubleshootingBody = localize((
    await readIfPresent(path.join(OUT, '_troubleshooting.md'))
    || await readIfPresent(path.join(OUT, '4-troubleshooting.md'))
  ).replace(/^##\s+Troubleshooting\s*\n/, '').trim());
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

  await renderKbDoc(kbPath, kbRawForKb, glossary);

  const shots = manifests.reduce((n, m) => n + m.steps.reduce((k, s) => k + s.shots.length, 0), 0);
  console.log(`${OUT}: 4 files (01-intro, 02-setup, 03-usage, 04-troubleshooting), ${manifests.length} chapters, ${shots} screenshots, ${LOCALE}`);
};

main().catch((err) => {
  console.error(String(err.message ?? err));
  process.exit(1);
});
