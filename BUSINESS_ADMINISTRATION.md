# Unified business administration

The platform office has one **Businesses** directory. Search by business name,
slug, account name or email, or filter Active/Suspended. Account contacts and
subscription/access dates appear in the same row. The old `/platform/users`
directory redirects here; a business filter redirects to that business's
Accounts tab. Office administrators remain separate.

Open **View business** for summary stats, contacts, usage and six detail tabs:
Overview, Accounts, Branches, Billing history, Activity and Support. Accounts
and billing histories are paginated. Currency totals are reported separately.

## Account and availability controls

Office owners/admins can edit business identity and Active/Suspended status.
Suspension blocks business operations across branches without removing records.
They can edit customer account name/email, set Inactive to disable access, and
reset passwords within the detail page. Email/status changes and password resets
revoke existing browser/cashier sessions and tokens. Password reset requires
confirmation, at least 12 characters and an audit reason; share it privately.
These actions do not move accounts between businesses or change tenant roles.

## Billing history and modal forms

Office owners/billing operators can:

- Create an invoice for an active non-trial plan, with due date and reason.
- Edit an open invoice's amount/due date before any payment history exists.
- Void an open invoice with a reason after pending payments are resolved.
- Record a manual payment against an open invoice, for later review or immediate
  approval after explicitly verifying receipt of funds.
- Correct a pending payment's method/reference/date with a reason.
- Approve or reject pending payments and view uploaded receipts.

Invoices and payments are scoped to the business in the route. Void/rejection
preserves history; paid invoices and reviewed payments are not overwritten or
hard deleted. Creating/editing records does not itself prove funds arrived.
Approval uses the existing transactional settlement/finance service, granting
one subscription period and posting one receipt even when approval is retried.
Office-recorded payments remain associated with a customer membership while
the office operator is the recorded audit actor (`submitted_for` identifies the
customer account). They are not represented as actions performed by the owner.
No external payment or banking transaction is executed.

Amounts use exact minor units and retain currency/plan snapshots. No live
billing records are seeded by installation. Zero-price plans retain the existing
automatic settlement behavior.

## Subscription controls

Enable/disable automatic renewal invoices with an internal reason. Existing paid
periods are preserved; disabling renewal removes renewal grace. Extend a trial
before the first paid period (1–365 days, end no more than 365 days from now).
Change a paid plan by creating its invoice and approving payment; the existing
period remains intact and a new period follows it. Suspension/reactivation is
controlled by business status, independently from renewal.

Office billing roles can view business details and control billing; identity,
password and availability controls require owner/admin permissions. Non-billing
admins do not receive financial records or billing activity metadata. The office
MFA setting is unchanged.

## Validation

The full regression suite passes: 271 tests / 1,747 assertions. Coverage includes
cross-business mutation denial, office roles, invoice editing/voiding, exact cents,
idempotent approval, finance receipts, trial/renewal rules and customer privacy.
Local browser scenarios cover modal saves, account resets, suspension, payment
review and phone sizing. No database schema migration is needed.

## Free-of-charge plan grants

Open Subscription controls → Grant plan FOC. Choose any active non-trial plan,
enter 1–36 months and an audit reason, then confirm the free grant. Office owners
and billing operators can grant FOC access. It uses a zero-charge settled invoice
numbered FOC-… with the chosen plan's feature/limit snapshot and selected duration.
The normal plan price is unchanged. There is no payment, financial receipt or
revenue. Internal reasons remain in the office audit, outside the customer plan
snapshot.

For trial/expired businesses access begins now; an unexpired paid period remains
intact and the free period follows it. Resolve open invoices first; an already
covered future period blocks another grant. Retrying the same request creates
no duplicate period. Suspension remains in effect until explicitly lifted.
Automatic renewal is turned off to avoid a later charge; the office or owner can
explicitly resume ordinary renewal if desired. FOC access expires on its end date.
No schema migration is required.

## Platform currency

All new platform plans, invoices and FOC grants use MMK. Plan currency is fixed
in the form and enforced on the server. Business-owner billing lists MMK plans
only. Existing financial/invoice snapshots retain their original currency; no
exchange rate or relabeling is applied to historical amounts. Zero-value system
trial metadata is normalized to MMK without changing trial dates or limits.
Business POS currency settings are separate from platform subscription currency.
