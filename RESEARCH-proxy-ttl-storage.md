# Research — persist proxy addresses + TTL-auto flag, then relocate the display

Scope: this document only. No code, schema, or git changes made. Findings below answer the
numbered research questions from the task prompt, in order.

## 1. Where do the anycast addresses come from today?

**Resolved live, on click, never stored.** `src/Service/PublicIpResolver.php:55-79`:
`resolve()` calls `@dns_get_record($fqdn, DNS_A|DNS_AAAA)` synchronously, on every invocation —
no cache, no persistence.

It is wired to a genuinely lazy, per-row, on-click trigger, not a render-time fan-out:
`templates/domainrecord_proxy_indicators.html.twig:60-77` attaches a `click` listener per proxied
row's "Show public IP" button; the `fetch()` to `GET /plugins/domainmanager/recordip/{id}`
(`src/Controller/RecordPublicIpController.php`) only fires when a user actually clicks that
button. So today: **0 lookups on page load**, at most 1 lookup per click, never blocking the tab
render. A 47-record tab issues 0 DNS queries on open; each click issues exactly 1.

This confirms ARCHITECTURE.md §17.16's own description of Phase 71 ("lazy per-row… no polling, no
load-time query per row") — code matches doc here.

## 2. How does `rdap_nameservers` store its multi-value list?

`glpi_plugin_domainmanager_states.rdap_nameservers` — `text` column, added via
`Migration::addField($table, 'rdap_nameservers', 'text', ['value' => null])`
(`src/Installer.php:752`). Encoding: plain `json_encode()`/`json_decode(..., true)` of a plain
PHP string array — write site `src/Cron.php:406` (`json_encode($result->nameservers)`), read site
`src/DomainForm.php:142-146` (`json_decode((string) $state->fields['rdap_nameservers'], true)`,
guarded with an `is_array()` check before use). No delimiter-joined string, no serialized PHP —
a plain JSON array in a `text` column is the established convention for a multi-value list on
this plugin. The new proxy-addresses column should mirror this exactly (`text`, nullable,
`json_encode`/`json_decode`), not invent CSV or a second table.

## 3. `is_proxied` migration pattern — confirmed exact idempotent shape, no backfill

`src/Installer.php:403-408` (`addRecordProxiedColumn()`):
```php
$migration->addField($table, 'is_proxied', 'tinyint NULL DEFAULT NULL');
$migration->addKey($table, 'is_proxied');
```
Called unconditionally from `install()` (`src/Installer.php:69`) — safe on both fresh installs
(column already present in the initial `CREATE TABLE`, `addField()` is a no-op if it exists — see
`src/Installer.php:228`/`238` for the fresh-install column/key already baked into
`createRecordsTable()`) and upgrades (adds the column to a pre-existing table).

**No backfill.** Unlike `is_managed` on `glpi_plugin_domainmanager_records` (which does a
row-by-row `SELECT`+`UPDATE` backfill pass, per the doc comment at `ARCHITECTURE.md:193`),
`is_proxied` is left `NULL` on every pre-existing row after the migration and only gets a real
value the next time that row's domain is synced — confirmed by the absence of any backfill method
call near `addRecordProxiedColumn()` in `install()`, and explicitly stated in
`ARCHITECTURE.md:193` ("unlike... `is_proxied` on the records table below... leaving every
pre-existing row `0`" — actually `NULL`, doc's own wording there is imprecise but the code is
unambiguous: `DEFAULT NULL`, no backfill query anywhere in `Installer.php`).

The two new columns must follow this exact pattern: `addField()` + `addKey()` (if searchable),
nullable, **no backfill** — existing rows stay `NULL` until their next sync, exactly like
`is_proxied`.

## 4. Where `ImportedRecord` rows are written during sync — the one place to populate both fields

`src/Service/RecordReconciler.php`, three call sites, all on the `ImportedRecord` itemtype:
- **Update** (existing record, hash changed or unchanged): `RecordReconciler.php:252-259` —
  `$imported->update([... 'is_proxied' => self::toNullableInt($record->isProxied)])`, called
  **unconditionally every sync** regardless of which branch above it ran (the same "proxy toggle
  can change with no other content change" reasoning applies identically to proxy addresses and
  TTL-auto: both can change without `record_hash` changing, since neither is a hash input —
  `ZoneRecord::getHash()` at `ZoneRecord.php:109-112` only hashes `type|name|data|ttl`).
- **Create** (new record): `RecordReconciler.php:416-431` — `$imported->add([...
  'is_proxied' => self::toNullableInt($record->isProxied)])`.

Both call sites read `$record->isProxied` off the same `ZoneRecord` DTO instance built by the
driver's `fetchZoneRecords()`. This is the single place both new fields must be populated —
confirmed as the one place `is_proxied` itself is populated, no second site exists (`grep -rn
is_proxied src/Service` shows only these two `RecordReconciler.php` sites plus the read-side
`DomainForm.php` template query).

## 5. Cloudflare "automatic TTL" indicator — verified against the live API, no separate field

Verified against Cloudflare's current API reference
(`developers.cloudflare.com/api/resources/dns/subresources/records/methods/list/`, fetched live
this session): the `ttl` field's own schema description is *"Time To Live (TTL) of the DNS record
in seconds. Setting to 1 means 'automatic'."* — **`ttl == 1` is the only signal**; there is no
separate `ttl_auto`/`is_automatic` boolean anywhere in the response schema.

No other driver in this codebase uses a TTL sentinel of any kind — confirmed by grep: IONOS
(`src/Driver/IonosDriver.php:429`) and Dinahosting (`src/Driver/DinahostingDriver.php:384`) both
read `ttl` as a plain `(int) ($row['ttl'] ?? 0)` with no special-case value anywhere in either
file. This means `1` is a **Cloudflare-specific** semantic, not a general DNS concept — an IONOS
or Dinahosting record whose real TTL happens to be `1` second is a literal 1-second TTL, not
"automatic." The `is_ttl_auto` flag must therefore be **driver-supplied** (like `isProxied`
already is on `ZoneRecord`), never derived from `ttl === 1` in shared code — deriving it
generically in `ZoneRecord`'s own constructor or in `RecordReconciler` would silently mislabel a
genuine 1-second TTL from IONOS/Dinahosting as "automatic."

## Recommended bound on address-resolution lookups per sync run

**Recommendation: a per-domain cap, not a global cron-tick cap**, applied inside
`RecordReconciler::reconcile()` (the method that already owns the per-domain proxied-record loop)
— e.g. a `PROXY_IP_LOOKUP_LIMIT` constant, suggested value **20**, matching the order of magnitude
of `Cron::RDAP_CANDIDATE_SCAN_LIMIT` (50, but that's a *domain* scan limit, not a per-domain
sub-resource limit) while being generous enough that a domain with a realistic proxied-record
count (the background example cites 47 total records, a small fraction actually proxied) is never
truncated in practice.

**Reasoning:**
- The cost moves from "paid when someone opens the tab, at most 1 lookup per click" (today) to
  "paid on every sync, for every proxied record, whether or not anyone ever looks" — this is a
  strictly worse cost profile per the task's own framing, and with `DomainSync`'s continuous cron
  (`Cron::cronDomainSync()`, default batch 20 domains per tick, §16 in ARCHITECTURE.md) that
  multiplies: unbounded, a domain with N proxied records costs N DNS lookups *per tick it's
  selected*, and a batch of 20 domains selected in one tick could cost `20 × N` lookups in a
  handful of seconds — a burst pattern, not a smooth one.
- A **per-domain** cap (applied where the loop already iterates that domain's matched records) is
  the right unit, not a global per-tick cap: it bounds worst-case latency added to any single
  domain's sync (bounded, predictable), and composes correctly regardless of `$batch_size`
  (`Cron.php:97`) — capping globally per tick would need the cap threaded through
  `SyncEngine::sync()` call arguments and would make one domain's proxied-record count affect
  whether another domain's addresses get resolved that tick, which is a confusing cross-domain
  dependency for no real benefit.
- Only rows with `is_proxied === 1` (not `0`, not `NULL`) qualify — mirrors the existing gate
  already used for the client-side indicator query (`DomainForm.php:389-392`,
  `WHERE is_proxied = 1`) — proxy-eligible-but-not-proxied records have no anycast address to
  resolve at all.
- Consequence to document, not silently hide: a domain with more than 20 proxied records has its
  21st+ address left `NULL` until covered on the following sync's own 20-record window (a rotating
  partial-coverage pattern, not a permanent gap) — acceptable since Cloudflare proxied records at
  this scale on one zone are an edge case, and the alternative (no bound at all) risks a slow sync
  tick with no ceiling.

## Confirmation: Phase 2 mechanism is the one already in use

**Confirmed, same mechanism.** `DomainForm::renderProxyIndicators()`
(`src/DomainForm.php:381-407`) already renders
`templates/domainrecord_proxy_indicators.html.twig` from the existing `Hooks::POST_SHOW_TAB` →
`DomainForm::onShowTab()` entry point (`DomainForm.php:347-367`), which is the one and only
injection point already used for every other Records-tab client-side overlay in this codebase
(the managed indicator, the write-panel, the proxy cloud icon). Relocating the anycast-address
display and adding the TTL-auto rendering both extend this same template/script — no second
mechanism, no new hook registration needed.

**Selector for the TARGET cell, confirmed from core source** (`src/DomainRecord.php:500-543` on
`11.0/bugfixes`, fetched live this session): `showForDomain()`'s `components/datatable.html.twig`
render uses a **fixed column order** `type, name, ttl, data` (`DomainRecord.php:525-530`), with
`name` the only column using the `raw_html` formatter (so the anchor tag the existing script
matches on is real markup, not text) and `data` rendered as a plain, unformatted value
(`DomainRecord.php:518`, `531-533` — no formatter entry for `data`, so it falls through to the
`{{ entry[colkey] }}` default at `datatable.html.twig:372`, i.e. always plain, non-anchor text).

Core's `datatable.html.twig` (`templates/components/datatable.html.twig:301-375`, fetched live)
gives every `<td>` no stable `data-colkey`/class attribute to select on — only an `aria-label`
that's empty here (no `data_aria_label` entry is set by `showForDomain()`). So the reachable,
stable selector is **positional relative to the already-matched name `<a>`**, not a fresh
`document.querySelectorAll` pass: `link.closest('td')` (the Name cell) → `.nextElementSibling`
(TTL cell) → `.nextElementSibling` (Target/Data cell) — robust regardless of whether the
massive-actions checkbox `<td>` is present at the start of the row (it only shifts everyone's
*absolute* column index, never the *relative* order of name→ttl→data, which
`showForDomain()`'s fixed `columns` array guarantees is always exactly that order). This is the
mechanism Phase 2 should use — reusing the existing script's row-matching loop, not inventing a
new `querySelectorAll` traversal.

## Disagreements found between the code and ARCHITECTURE.md

1. **§9 Phase 7 addendum's `is_managed` backfill sentence** (`ARCHITECTURE.md:193`) says
   `is_proxied` is left `0` on every pre-existing row — the actual column default and every
   observed behavior is `NULL` (`DEFAULT NULL`, tri-state design, confirmed
   `Installer.php:407`/`228`). Minor wording imprecision in the doc, not a behavior bug; flagging
   since this task explicitly asks the new columns to "mirror" `is_proxied`'s pattern and the doc
   text could mislead on which sentinel value that actually is.
2. **Version prompt mismatch.** The task prompt says "Version `1.5.0-beta1`", but the plugin's
   actual current version (`setup.php:43`) is already `1.6.0-beta2`, several versions ahead —
   confirmed also by `git log` showing `4bf56b3 Bump to 1.6.0-beta2` as the second-most-recent
   commit. The prompt's version number is stale; implementation should continue the real running
   `1.6.0` pre-release line (e.g. `1.6.0-beta3`), not regress to `1.5.0-beta1`. Flagging rather
   than silently picking one.
3. No other disagreement found between this document's own §17.9–§17.16 (Phase 71, as shipped)
   and the actual code — the "lazy per-row" description, the controller's gating on `is_proxied`,
   and the resolver's shape all match what's in the repository today.

## Out-of-scope findings, as proposed GitHub issue titles

1. **"Add a `Search::show()`-based list view over `ImportedRecord`/`DomainRecord`"** — already
   flagged by the task prompt itself as known; recording it here per instruction rather than
   building it. Would give sorting/filtering/column selection/export over `is_proxied`,
   `proxy_addresses`, `is_ttl_auto`, and every other `ImportedRecord` field, and would also close
   the gap noted in ARCHITECTURE.md §5 that core has no searchable list page for `DomainRecord` at
   all. Worth doing once filtering on these fields is actually wanted, not before.
2. **"`components/datatable.html.twig` gives per-cell `<td>` no stable selector (no `data-colkey`,
   empty `aria-label`)"** — not this plugin's bug to fix (core template), but worth an upstream
   GLPI core issue/PR: a `data-colkey="{{ colkey }}"` attribute on every rendered `<td>` would let
   every plugin doing this kind of overlay (this one already does it three times: managed
   indicator, proxy cloud icon, and now the TARGET-cell relocation) select cells robustly instead
   of walking `nextElementSibling` chains that break silently if core ever reorders
   `showForDomain()`'s `columns` array.
3. **"`DomainRecord::showForDomain()`'s TTL column has no formatter hook for a computed display
   value"** — same root cause as item 2, worth naming separately since it's specifically about
   *value transformation* (raw TTL → "Automatic") rather than *cell selection*. A core
   `formatters['ttl'] = 'callback'` extension point (or simply exposing `columns`/`formatters` as
   filterable via a hook) would let this rendering happen server-side instead of via a
   client-side text-node rewrite, which is inherently more fragile (locale/formatting drift,
   double-render races on ajax tab reload).

## Anything undetermined

- **Exact real-world proxied-record-count distribution** — the per-domain cap of 20 is reasoned
  from the background's "47-record tab" example and `RDAP_CANDIDATE_SCAN_LIMIT`'s order of
  magnitude, not from an observed live account's actual proxied-record count. If a real Cloudflare
  zone commonly proxies more than 20 records, the cap should be revisited before shipping — noting
  this as an implementation-time judgment call, same posture as §12.8's "verifications required"
  list elsewhere in ARCHITECTURE.md.
- **Whether `PublicIpResolver::resolve()` should be reused as-is for the sync-time lookup, or
  whether it needs a stricter/looser timeout for a batch context** — today it's `@dns_get_record()`
  with PHP's default resolver timeout, acceptable for a single on-click lookup; not verified
  whether that same timeout, multiplied by up to 20 lookups per domain within a cron tick that
  also has its own execution-time expectations, is still comfortable. Flagged for the
  implementation phase, not a design blocker.
- **Whether the existing "Show public IP" on-click button/endpoint
  (`RecordPublicIpController`/`PublicIpResolver`/the click handler in the current template) should
  be removed once the value is persisted and rendered directly**, or kept as a manual "re-check
  right now" affordance. The task's point 9 ("do not leave the addresses displayed in two places")
  implies removal, but doesn't explicitly say whether the *live re-check* capability itself should
  survive in some form (e.g. relabeled "Refresh"). Recommend removal for simplicity, pending
  approval — the persisted value already answers the same question the button answered, without
  the click.
