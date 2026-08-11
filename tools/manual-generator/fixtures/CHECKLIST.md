# Golden fixture checklist — domainmanager

Build this state once by hand, then `tools/manual-generator/fixtures/dump.sh`. Everything here exists
to be photographed, so choose values that read as examples in any language and that exercise
the cases the manual needs to explain.

Current manual scope is **5 chapters, English only**: (0) granting Domain Manager rights to
a profile, (1) Supplier driver setup and domain import (error case), (2) Domain status panel and DNS
record management, (3) Bulk-importing multiple domains, (4) Editing records and locked-field state. Sections 2–5 below are scoped to those chapters — extend them here
first when a new chapter is added.

## 1. Documentation users *(keep)*

The reason for a dedicated user is Trap 4: GLPI renders in the **user profile's** language,
so the locale of a screenshot is decided at login, not by Playwright. English-only for now —
add a row (and a matching `DOC_USERS` entry in `lib/glpi.ts`) if a locale is added later.

| Login | Language | Surname / First name | Profile |
|---|---|---|---|
| `manual_en` | English (UK) | Manual / User | Super-Admin |
| `manual_en_restricted` | English (UK) | User / Restricted | Technician |

- Password: the `DOC_USER_PASS` value from `manual.env`. **Use a plain alphanumeric
  password, no hyphens/punctuation** — a hyphenated password reproducibly made GLPI 11.0.8
  reject a correct login from headless Chromium (curl with the identical `login_password`
  value succeeded every time), while an alphanumeric one worked. Root cause not fully
  chased down (suspected client-side JS touching the password field); not worth the time
  to isolate further when the workaround is free.
- Set a neutral display name — the header appears in nearly every screenshot.
- Super-Admin keeps the manual from silently omitting UI a restricted profile hides.
- `manual_en_restricted` exists only so chapter 0's spec has a real, in-use non-Super-Admin
  profile (**Technician**, id 6) to grant rights to — the fixture leaves that profile's
  `domainmanager:*` rights unset (all rows present in `glpi_profilerights` at value `0`;
  created via `php bin/console user:create`/`user:grant`, never by hand-editing rights) so
  chapter 0's spec can screenshot the before/unset state honestly. The spec never logs in as
  this user — it only edits its profile as `manual_en`.
- Turn **debug mode off** for `manual_en` (Setup → General, or the user's own preferences):
  the debug toolbar otherwise sits across the bottom of every capture.

## 2. Profile rights — Technician (chapter 0)

Confirmed unset before every dump: `SELECT * FROM glpi_profilerights WHERE profiles_id=6
AND name LIKE 'domainmanager%'` must show all five rows (`unlock_imported`,
`dns_records_a/aaaa/cname/txt`) at `rights=0`. The rows themselves must exist (created the
first time any profile's Domain Manager tab is saved, or by installing the plugin) — a
missing row and a row present-but-zero render identically, so this isn't visible in a
screenshot; it's a dump-time check.

## 3. Supplier + driver config (chapter 1)

One Supplier, name **"Manual Demo Registrar"**, created empty (no driver configured yet —
chapter 1's own spec does the configuring live, as that's the workflow being documented).

- **Decision (resolved, not left open):** the spec fills in an obviously fake Cloudflare
  Account ID and API Token (`tools/manual-generator/specs/fixtures.ts`), saves, then runs Test and
  Import for real against Cloudflare's actual API. There is no real Cloudflare account
  behind this fixture, so both calls fail — and chapter 1's prose documents the *failure*
  UI (the diagnostics panel's error state, the import dialog's error banner) rather than a
  faked success. This is honest, deterministic (Cloudflare returns the same
  "authentication error" for the same malformed/unknown token every time), and needs no
  disposable zone or live credential to maintain.
- If this plugin later gains a driver test-mode or a stable public sandbox account, revisit
  and show a real successful Test/Import instead — track that as a fixture change, not a
  spec change.
- Credentials are encrypted at rest, but **labels and notes are not** — keep them generic;
  this fixture's screenshots go to clients.

## 3b. Domain discovery modal — showing success (chapter 4)

Chapter 4 documents the **successful** bulk-import workflow, not an error case like chapter 1.
Since there is no real registrar account with actual domains behind the fixture (and maintaining
one would be fragile and expensive), the spec uses **Playwright route interception** to mock
the `/plugins/domainmanager/domaindiscovery/{suppliers_id}` AJAX endpoint's HTTP response.

- **Decision rationale:** Mocking at the HTTP response layer (not the driver/API layer) stays
  true to the spirit of "test the real code path" — the spec exercises the actual bulk-select
  and import form submission workflow, the actual browser JS that checks/unchecks domains, the
  actual modal rendering logic in `templates/domain_discovery_modal.html.twig`. Only the
  *discovery source* (the list of domains) is mocked, not the interaction or submission code.
- The mock response includes three domains (`example-one.com`, `example-two.com`,
  `example-three.com`), all marked as "Not yet in GLPI", so the spec can screenshot both the
  full-selected state and the partially-selected state (to demonstrate independent checkbox
  control).
- If this plugin later gains a driver test-mode or a stable sandbox account with real domains,
  revisit and remove the route interception — let the spec call the real API instead. For now,
  this is honest, deterministic, and requires no live credentials.

## 4. Domain (chapter 2)

One Domain, name **`manual-example.com`**, linked to the Supplier above as both registrar
and DNS provider (the common case — one provider handles both):

| Field | Value | Why |
|---|---|---|
| Expiry date | fixed future date, e.g. `2027-06-30` | Never "today + N" — a relative date rewrites the screenshot every run |
| Sync status | successful / up to date | The baseline state chapter 2 explains before any error states |
| DNSSEC / transfer lock / WHOIS privacy | one on, one off, one on | So the status panel's indicators aren't all identical in the screenshot |

## 5. DNS records (chapter 2)

On `manual-example.com`: an **A** record (`@` → a fixed demo IP, e.g. `203.0.113.10` —
TEST-NET-3, guaranteed non-routable) and a **CNAME** (`www` → `manual-example.com`). Enough
variety to show the record-type UI without needing every type. Same values as
`tools/manual-generator/specs/fixtures.ts` — the spec and the fixture must agree, and `fixtures.ts` is
the source of truth for names the specs type or click.

## 5d. Locked domain & record (chapter 5)

A second Domain, name **`locked-example.com`**, linked to the Manual Demo Registrar supplier as **registrar only** (no DNS supplier, `dns_suppliers_id=0`). This domain demonstrates the locked-field UI on records when a domain has no write-capable DNS provider configured.

- **Why locked:** When a domain has no DNS provider or the provider doesn't support write-back, its records cannot be edited in GLPI — they're read-only/imported. This state is rendered by setting `dns_suppliers_id=0` (no provider link), which makes `DnsRecordWriteback::isDomainDnsEditable()` return false in `src/DomainForm.php`, triggering the locked-field banner and disabling edit controls.
- One **A** record (`@` → `203.0.113.20`, TEST-NET-3) with corresponding `ImportedRecord` row to mark it as plugin-managed. When opened for edit, this record shows the lock-field alert and disabled data/TTL inputs, demonstrating to users why some records can't be edited inline.

## 5b. `url_base` must match `BASE_URL` *(keep — easy to lose on a rebuild)*

`glpi_configs` (context `core`, name `url_base`) defaults to whatever hostname the browser
used to access GLPI's install wizard — for a fresh container that's `http://localhost`, not
`http://glpi`. GLPI compares the request's `Origin` header against this value on POSTs
(login included) and silently rejects with a generic "Incorrect username or password" if
they don't match — curl doesn't send `Origin` by default, so this only shows up under a
real browser and is easy to misdiagnose as a credentials/CSRF problem. Set it explicitly:

```sql
UPDATE glpi_configs SET value='http://glpi' WHERE context='core' AND name='url_base';
```

then `bin/console cache:clear` before dumping.

## 5c. The plugin auto-deactivates on a version bump *(keep)*

`compose-glpi-11-testing-only.yml` bind-mounts this repo's live working tree at
`/var/www/glpi/plugins/domainmanager` — so if `setup.php`'s `PLUGIN_DOMAINMANAGER_VERSION`
changes on disk (a normal changelog-bump commit, made by anyone, any time, including a
concurrent session on this same repo) while the container is already running, GLPI notices
the mismatch on its next boot and deactivates the plugin ("To update" in
`glpi:plugin:list`) — every page that shows a Domain Manager tab/panel then simply omits
it, which reads exactly like the Twig cold-cache flakiness in §5b and is easy to conflate
with it. Reactivate before dumping (and before every recording session, since this can
happen mid-session):

```bash
podman exec <glpi-container> php bin/console glpi:plugin:install domainmanager
podman exec <glpi-container> php bin/console glpi:plugin:activate domainmanager
```

## 6. Tidy up before dumping *(keep)*

- Empty the trashbin, so deleted-item counts don't appear in list headers.
- Clear notifications / the alert count in the top bar.
- Check the GLPI footer version matches what you'll stamp as `GLPI_VERSION` in `manual.env`.
- One last pass for anything real: client names, internal hostnames, your own email.

## 7. When to regenerate the fixture *(keep)*

Only for a plugin schema migration or genuinely new demo data. Treat it as a deliberate
commit of its own, because it rewrites every screenshot in the repo and you want that diff
reviewable in isolation.

## How to derive sections 2–5 for a plugin

Read the plugin's itemtypes and list/form templates and ask, per screen: which *states* does
this UI render differently? Each distinct state needs one fixture row, because a state with
no row is a state the manual cannot show. Typical sources of states: status/enum columns,
date thresholds that drive highlighting, optional relations that change the form, and
anything with an empty-vs-populated distinction. Then pick one unremarkable baseline row so
the reader sees the normal case too.
