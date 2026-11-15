# IT 314 proposal implementation

Reviewed against `IT-314-Project-Proposal_Timeline_Revised.pdf` supplied on October 28, 2026. The document's project timeline describes academic activities; it is not an application feature or evidence those activities occurred.

| Proposal requirement | Available workflow |
| --- | --- |
| Authentication and role access | Individual admin, staff, and tenant accounts; password hashing, CSRF, throttling, and server-side role checks |
| Boarder management | Residents: registration, editing, assignment, transfers, and preserved move-out history |
| Room availability | Rooms & Beds: occupied and available beds derived from active assignments; maintenance rooms excluded from availability |
| Rental billing and cutoffs | Billing Statements: individual and monthly batch statements, configurable cutoff/due days |
| Utility computation | Configured fixed room charges are allocated automatically using immutable monthly occupancy snapshots; departure preserves issued utility charges |
| Full and partial payments | Payments: office payments, GCash verification, receipts, dates, balances, and audited voids |
| Due dates and unpaid balances | Billing statuses, dashboard alerts, tenant inbox reminders, financial aging reports |
| Final bill computation | Billing Statements → Final Bill & Departure, also linked from resident management |
| Tenant dashboard | My Home: itemized statements, due dates, balances, payment history, room details |
| Reports and dashboard | Financial Reports: collections, outstanding balances, charts, expenses, CSV exports; Overview: occupancy and availability |
| Responsive interface | Mobile navigation, responsive forms/tables, section tabs, keyboard skip link and focus indicators |
| Secure MySQL records | PDO prepared statements, transactions, role and ownership checks, protected internal files |

## Final bill and departure

1. Select an assigned resident and actual departure date.
2. Choose the agreed full-month or calendar-day rent policy. Full-month rent is the default because the proposal does not prescribe a proration policy.
3. Utilities are calculated automatically from the monthly snapshot; previously issued utility charges are retained. See FIXED-UTILITIES-REPORT.md for the fixed-utility policy.
4. Review the server-calculated rent, utilities, prior balance, existing adjustments, confirmed payments, and remaining balance.
5. Confirm to save the final statement and release the bed in one transaction.

Daily rent includes the move-in and departure dates, uses actual days in the calendar month, and rounds once to the nearest centavo. An existing month's statement supplies the rent basis; otherwise the assigned room supplies it. Existing month statements are updated rather than duplicated. The audit event retains the original statement and final calculation. Existing statement due dates remain unchanged; a newly created final bill is due on departure.

Pending payment submissions, later statements, missing earlier monthly statements, stale previews, and totals below confirmed payments block confirmation. Resolve these in the normal billing/payment workflows and preview again. A repeated confirmation cannot release a second bed or create another bill. Unpaid final balances remain collectible and visible to the tenant. Deposits require their separate existing refund/settlement workflow.

## Operational configuration

SMTP credentials and a scheduled `app/jobs.php` run are needed for unattended email delivery. Configure the actual GCash account/QR in Settings; transfers are verified by staff, not automatically charged. Camera counting requires a physical camera and browser permission. These external services were not verified with real accounts or hardware.

The application uses its existing custom responsive CSS rather than introducing Bootstrap over its established styling. The subsequent fixed-utility enhancement adds two room-rate columns through the safe setup migration; see FIXED-UTILITIES-REPORT.md.

## Verification

`python tests/integration.py` exercises isolated MySQL workflows including final billing, partial payments, stale previews, proration, missing periods, duplicate confirmation, role restrictions, and bed release. `node tests/visual.js` covers existing desktop/mobile interfaces and payment interactions in headless Edge. Both create disposable test databases rather than modifying resident records.
