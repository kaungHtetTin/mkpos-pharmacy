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
