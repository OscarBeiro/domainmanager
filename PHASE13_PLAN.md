# Phase 13 Plan — Single Source of Truth for Registrar/DNS Status

## Root cause (confirmed)

`src/DomainState.php:202`, in `getDomainsForSupplier()`:

```php
$registrar_verified = (int) ($row['state_registrar_suppliers_id'] ?? 0) === $suppliers_id;
```

This compares the state row's registrar mirror against **the supplier whose tab is
being viewed** (`$suppliers_id`), instead of against the row's own live Infocom value
(`$row['registrar_suppliers_id']`, fetched in the same query). Result: for a domain
whose registrar differs from the supplier tab it's viewed from (e.g. `ticgal.com` —
registrar IONOS, DNS provider Cloudflare — viewed from Cloudflare's "Domains" list),
`registrar_verified` is always false, so the Registrar column collapses to
`STATUS_NEVER` ("Not yet checked") regardless of the domain's real, current
`registrar_status`. The Domain form shows the correct status because
`DomainForm::inject()` trusts `state->fields` directly with no such gate.

This is a single incorrect gating condition in the one existing shared read path —
not two independently-drifted implementations, though the label/class maps *are*
duplicated (see step 2).

## Steps

1. **Fix the gate** in `DomainState::getDomainsForSupplier()`: compare
   `state_registrar_suppliers_id` against the row's own live `registrar_suppliers_id`
   (from the Infocom join), not against `$suppliers_id`. Mirror agrees with live →
   use the real `registrar_status`; disagrees → `STATUS_NEVER` (genuinely stale
   mirror, independent of which supplier's tab is open).

2. **Extract one shared resolver**, e.g. `src/Service/DomainStatusResolver.php`,
   owning:
   - status label/class maps (currently duplicated: `DomainForm::getStatusLabels()`
     / `getStatusClasses()` vs. the inline Twig maps in
     `templates/supplier_domains_list.html.twig`)
   - DNS badge derivation (see step 3)
   Both `DomainForm::inject()` and `SupplierTab::buildDomainsListRows()` /
   `describeDnsProvider()` call this. Delete the duplicated Twig maps and
   `DomainForm`'s private label/class methods once callers are migrated.

3. **DNS/NS Provider column**: replace the detection-only
   `managed`/`unmanaged`/`unknown`/`never` "kind" classification with the same
   `dns_status` sync-outcome badge (OK/Error/Unconfigured/Unsupported/Unknown/Never)
   the Domain form uses for DNS sync, with the provider name shown alongside.
   Keep "detected but not yet actively managed" as smaller secondary text if still
   useful. Document this decision (badge = sync outcome, not detection) in
   `ARCHITECTURE.md`.

4. **Audit for other independent computations**:
   - Tab-counter badge (`SupplierTab::getTabNameForItem()`) already reuses
     `getDomainsForSupplier()` — just inherits the step-1 fix, no separate logic.
   - `Cron.php` / `MassiveActionHandler.php` read straight from `SyncEngine`'s
     result (the authoritative pipeline), not a second display computation — no
     change needed, but state this explicitly in `ARCHITECTURE.md` so it isn't
     re-flagged as drift later.

5. **TESTING.md**: add a permanent regression case — view the same domain's
   Registrar/DNS status from (a) its own Domain form and (b) every supplier tab
   it's linked to (registrar tab and DNS-provider tab, when different),
   immediately after a sync via "Update Now" and via the batch "Review and sync"
   action, and confirm identical badges everywhere with zero lag. Verify against
   `ticgal.com`, `tic.gal`, and `miedoavolar.es` specifically.

## Files touched (expected)

- `src/DomainState.php` — fix `getDomainsForSupplier()` gate
- `src/Service/DomainStatusResolver.php` — new shared resolver (labels/classes/DNS badge)
- `src/DomainForm.php` — use resolver instead of private label/class methods
- `src/SupplierTab.php` — use resolver in `buildDomainsListRows()` / `describeDnsProvider()`
- `templates/supplier_domains_list.html.twig` — drop local label/class maps, use resolver output; DNS column badge becomes sync-outcome based
- `ARCHITECTURE.md` — document the single-resolver convention + DNS column semantics decision + confirm Cron/MassiveActionHandler are not duplicate computations
- `TESTING.md` — new cross-surface consistency regression case
- `CHANGELOG.md` — entry once implemented (per versioning requirement)
