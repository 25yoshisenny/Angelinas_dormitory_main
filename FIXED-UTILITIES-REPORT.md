# Fixed monthly utilities implementation

The PHP/MySQL architecture, three roles, existing page names, navigation, dashboard structures, styling, payments, deposits, and room/bed model are retained.

## Live room configuration

Matched against the actual room IDs and numbers in `angelinas_dormitory`. Amounts below are whole-room monthly estimates, not per-bed charges or measured consumption.

| ID | Room | Capacity | Electricity | Water | Total |
|---|---|---|---|---|---|
| 1 | 101 | 4 | PHP 650 | PHP 180 | PHP 830 |
| 2 | 102 | 6 | PHP 850 | PHP 220 | PHP 1,070 |
| 3 | 103 | 4 | PHP 1,100 | PHP 280 | PHP 1,380 |
| 4 | 104 | 2 | PHP 750 | PHP 200 | PHP 950 |

No additional rooms existed in the live database. For an existing installation with additional rooms, migration uses an editable capacity-only estimate: electricity PHP 450 + PHP 100 × capacity; water PHP 100 + PHP 30 × capacity. No appliance or floor-area facts are assumed. Newly created rooms require explicit rates in the existing form.

## Billing policy

- The first successful individual, batch, or new final statement for a month freezes all rooms' rates and billable occupants for that month. Merely opening a page or previewing a departure does not freeze anything.
- The occupancy reference date is the earlier of today and the billing month's last day. The latest assignment covering that date determines a tenant's room; same-day transfers count the tenant once. Future-month billing uses currently known assignments.
- Every occupant in the snapshot participates, even when only some residents are selected for batch billing. Unselected residents retain their reserved share for later issuance.
- Each utility is divided in integer centavos. Remaining centavos go to ascending tenant IDs. For example, PHP 850.01 across three residents becomes PHP 283.34, PHP 283.34, PHP 283.33. These sum exactly to the configured rate.
- Utilities use a full-month snapshot policy, independent of the existing full-month/calendar-day **rent** policy. Transfers after freezing retain their original room share. Departures retain that month's share. Arrivals absent from an already frozen snapshot receive zero utilities that month and begin next month. Empty rooms have no tenant allocations.
- Room rate changes affect months that have not yet been frozen. Issued bills and already frozen periods do not change. Admin may adjust the prefilled per-tenant electricity and water amounts in the monthly batch form; these edits affect only the statement, not the room rates or frozen snapshot. Individual statements and final departure billing continue to use automatic shares.
- **Transition months:** a room with pre-existing manual bills keeps those actual charges, even if their sum differs from the new estimate. No additional room utility pool is collected for that room/month. This avoids rewriting history or double charging. Normal fixed allocation begins in the next unfrozen period without legacy bills.
- Individual and final billing POST requests cannot override utility amounts. Batch billing starts with the same automatic shares, and validates any Admin-edited electricity and water amounts as nonnegative values with at most two decimal places. Snapshot and statement writes share the existing InnoDB transaction and serialization lock.

Review the displayed policy before issuing the first bill. Freezing an entire month early deliberately excludes residents who arrive later; use the existing audited correction workflow for exceptions agreed with the office.

## Database and preservation

Only two columns were added: `rooms.fixed_electricity` and `rooms.fixed_water`, both nonnegative BIGINT centavo values. NULL is used only to recognize rooms not yet initialized by migration; zero is a legitimate configured amount.

Immutable monthly allocation JSON is stored under `utility_snapshot_YYYY-MM` in the existing `settings` table. It contains room-rate, assignment, occupant, and share snapshots, avoiding extra billing tables. A 60 KB guard rejects an oversized snapshot before bills are saved. This is suitable for the current small dormitory; a much larger installation would need dedicated snapshot storage.

Run `C:\xampp\php\php.exe database\setup.php` for fresh or existing installations. Initialization fills missing values only and does not overwrite Admin edits. No example SQL snapshot is imported. Backups must include `settings` because it now also contains frozen allocations.

The live migration was applied. Before/after row counts and hashes matched for every pre-existing table except the intentionally extended room columns and appended migration audit entries. Bills, utility-charge records, payments, receipts, deposits, assignments, accounts, and settings were unchanged.

## Reviewed modules and targeted fixes

Reviewed authentication/actions, rooms and assignments, individual and batch billing, departure, tenant views, payment submission/verification, receipts, balances, reporting, notifications/jobs, maintenance, announcements, schema/setup, and browser interaction code. Existing integration coverage was retained.

Actual issues addressed:

1. Individual/final forms use fixed shares calculated on the server; batch estimates are prefilled from those shares and Admin can edit them before saving. Departure uses automatic utilities.
2. Departure could replace previously issued utility amounts. It now preserves them, including legacy charges. Changed allocation/rates invalidate a pending departure preview.
3. A failed departure re-preview could leave an old confirmation available. Starting a new preview clears the previous one.
4. Batch estimates included residents whose statements would be skipped. Duplicate/later-period rows are now excluded from the estimated selected total.
5. Room cards presented maintenance beds as unassigned without distinguishing availability. They now show zero available beds while under maintenance.
6. Password change, staff creation, and password reset lacked the registration flow's 72-byte upper bound. Server validation now consistently rejects oversized bcrypt passwords.
7. Room update could report success for a nonexistent room. It now rejects missing room IDs and audits previous/new rate values.
8. Browser regression runs reused login cookies from a persistent Edge test profile. The suite now clears those cookies before testing the public entry flow.

Fixed whole-room utility values are no longer displayed or editable in Rooms & Beds. Legacy database values remain for existing allocation compatibility. Payment Staff sees statement charges and retains existing collection permissions; rates and Admin-only actions remain inaccessible. Statements explain their saved allocation without exposing other occupants' identities.

## Changed files for this improvement

- `app/utilities.php`: allocation, snapshots, protected preview endpoint helpers, statement explanations.
- `database/fixed_utilities.php`, `database/schema.sql`, `database/setup.php`, `app/seed.php`: additive, repeatable rate migration.
- `app/actions.php`, `app/features.php`, `app/departure.php`: rate management, automatic billing, departure preservation, validation.
- `app/views.php`, `app/feature_views.php`, `app/operations_views.php`, `app/tenant_home.php`: small extensions to existing forms and details.
- `index.php`, `assets/app.js`: protected preview routing, automatic estimates, batch skip totals.
- `tests/integration.py`, `tests/visual.js`, `tests/utility_departure.php`: regression coverage.
- `README.md`, `PROPOSAL-COVERAGE.md`, this report: updated operating documentation.

## Verification

Commands: `python tests/integration.py`, `node tests/visual.js`, `C:\xampp\php\php.exe tests/utility_departure.php`, PHP lint, and `node --check assets/app.js`. Automated workflow tests use disposable `angelinas_test_*` databases, never live tenant/financial records.

Final results on October 28, 2026:

| Check | Result |
|---|---|
| Expanded MySQL integration suite | PASS |
| Desktop/mobile Edge suite, including read-only shares and mobile room settings | PASS |
| Focused departure, fallback estimates, zero-rate preservation, and unassigned-tenant checks | PASS |
| PHP syntax checks and JavaScript syntax check | PASS |
| Whitespace/diff validation | PASS |
| Live migration before/after preservation comparison | PASS |

The first browser attempt timed out because the reused test profile retained an authenticated cookie. Clearing test browser cookies resolved it; subsequent complete runs passed. Screenshots are in `storage/previews/`, including `room-utilities-mobile.png` and departure previews.

Coverage includes configured room rates, three-person rounding, subset batch selection, forged overrides, frozen rate preservation, transfer allocation, carry-forward, partial payments/receipts, transaction rollback, repeated setup, role restrictions, departure with nonzero utilities, stale departure previews, and historical utilities. The broader existing tests cover reports, tenant privacy, deposits, announcements, maintenance, and verified GCash submissions.

Actual SMTP delivery, real GCash receipt verification, and physical camera capture still require the corresponding credentials/accounts/hardware. No automatic GCash gateway was introduced.
