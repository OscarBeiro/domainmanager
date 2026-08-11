# tools/manual-generator — how the domainmanager manual is built

This directory builds the user manual for the domainmanager plugin. The manual itself is a
build artifact published to `docs/manual/<locale>/`; the sources here are the narrated
Playwright specs in `specs/` plus `docs/kb/domain-manager.md`. Intro and Setup content is
auto-pulled from that KB doc's matching sections (Description/Why this plugin?/Supported
providers/Features list/Impacted GLPI items/Interactions with other plugins for Intro;
Permissions/Automatic Actions/Notifications/Rules/Setup for Setup) — there is no
hand-written intro/setup file to keep in sync. The manual is published as four numbered,
cross-linked files — `01-intro.md`, `02-setup.md`, `03-usage.md`, `04-troubleshooting.md` —
instead of one monolithic `MANUAL.md`; section numbers (`2.3`, `2.3.1`, ...) are assigned by
`render-manual.mjs` from the chapter order baked into that script (which chapters land in
Setup vs. Usage). Only Troubleshooting is hand-written, since there's no automatable source
for it: its source lives in `docs/manual/<locale>/_troubleshooting.md` (never overwritten by
the renderer) and `04-troubleshooting.md` is the generated file built from it. See
`render-manual.mjs`'s header comment for the exact section/file layout.

**Production versions only.** `render-manual.mjs` refuses to run (non-zero exit) while
`setup.php`'s `PLUGIN_DOMAINMANAGER_VERSION` still carries a `-dev`/`-alpha`/`-beta`/`-rc`
suffix — a client-facing manual must never advertise a pre-release build. Bump the version
past the pre-release stage before regenerating for a real release; `MANUAL_ALLOW_PRERELEASE=1`
is available for a developer's own local test render only, never for CI or a release job.

**Current scope: 2 chapters, English only** — Supplier driver setup/import
(`10-supplier-setup.manual.spec.ts`) and domain monitoring/DNS records
(`20-domain-monitoring.manual.spec.ts`). More chapters (dashboard, profile rights) and
locales are expected later; see `fixtures/CHECKLIST.md` before adding either.

Packaging: no release/packaging script exists in this repo yet. When one is added, keep
`tools/manual-generator/**` (specs, fixtures, node_modules) out of the shipped plugin zip —
it's a repo/GitHub-release artifact, not something the plugin needs at runtime. The generated
`docs/manual/en_GB/0{1,2,3,4}-*.md` files and their screenshots are still committed to git so
PRs show documentation changes and the repo doubles as the published manual.

## One-time setup

```bash
cd tools/manual-generator
npm install                                   # installs the pinned @playwright/test
cp manual.env.example manual.env              # already filled in for this repo's dev stack
$EDITOR manual.env                            # only needed on a different machine/stack
```

`manual.env` here points at the `~/containers/glpi` podman stack: `glpi_glpi_1` /
`glpi_db_1` on network `glpi_default`, GLPI 11.0.8, login `glpi`/`glpi`.

Build the demo data by hand following `fixtures/CHECKLIST.md` (one documentation user, one
Supplier with a Cloudflare driver, one imported domain with two DNS records), then freeze
it:

```bash
npm run manual:fixture:dump
```

## Every run

```bash
cd tools/manual-generator
npm run manual                     # restore fixture → capture → render the 4 manual files
npm run manual:pdf                 # optional, for a release
```

`npm run manual` is idempotent. On unchanged UI it produces byte-identical PNGs, so:

```bash
git status --short docs/manual     # empty = the UI didn't change
```

A screenshot diff you didn't expect is one of three things — a real UI change (update the
prose in the spec too), a determinism leak (fix it, don't just commit the new PNG), or a
regression. That is the second job this pipeline does.

## Adding a chapter

1. New spec in `specs/`, numbered with a gap: `30-dashboard.manual.spec.ts`.
2. Add its demo data to `specs/fixtures.ts` and to `fixtures/CHECKLIST.md` — never inline a
   literal the fixture also defines, and never generate one.
3. Narrate with `manual.step(id, title, prose, fn)`; capture with `manual.shot()`, scoped to
   a locator rather than the full page.
4. Extend the golden fixture by hand, `npm run manual:fixture:dump`, then `npm run manual`
   and read the rendered chapter as a client would.
5. No `data-testid` attributes exist in this plugin's templates yet — selectors use
   `getByRole`/`getByLabel`/text against real markup (checked in `templates/*.twig` and the
   controller that renders it). If the manual grows much further, consider adding test ids
   via the `glpi-plugin-builder` skill instead of accumulating more text-based selectors.

## Layout

```
tools/manual-generator/
  manual.env            stack-specific values (gitignored); every script sources it
  manual.css            print stylesheet for the PDF build
  run.sh                capture in a version-matched Playwright container
  render-manual.mjs     manifests → 01-intro.md/02-setup.md/03-usage.md/04-troubleshooting.md
  build-pdf.sh          the 4 manual files → dist/MANUAL-domainmanager-<version>-<locale>.pdf
  lib/                  manual-recorder.ts (the fixture), glpi.ts (login, routes, masking)
  specs/                narrated scenarios + fixtures.ts
  fixtures/             golden.sql.gz (after dump.sh), dump.sh, restore.sh, CHECKLIST.md

docs/manual/<locale>/    _troubleshooting.md (hand-written); 0{1,2,3,4}-*.md, assets/ (generated)
docs/kb/                domain-manager.md (hand-written; also feeds the manual's Intro/Setup)
```
