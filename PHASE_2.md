# Phase 2: subscriptions and manual payment approval

Phase 2 builds on the one-account/one-business architecture. The platform office
manages plans and payment review; owners manage their own subscription. Staff and
cashiers cannot inspect other businesses or change billing. Branch switching
remains inside the account's business.

## Delivered workflow

- Platform office → **Plans**: create monthly/yearly plans, set an exact decimal
  price and currency, branch/active-account/medicine limits, grace days, and POS,
  offline-sync and reporting features. Archive a plan or make it office-only.
  Issued invoices and paid periods retain immutable snapshots of their terms.
- New businesses receive one 14-day trial. The system trial has five branches,
  25 active accounts and 10,000 medicines, with all three features enabled.
  Trial defaults are deployment configuration in `config/billing.php`.
- Owner → **Subscription & billing**: see access state, paid/trial dates, upcoming
  paid periods, usage, available plans, payment instructions, invoices and review
  history. No sample commercial prices or real bank details are installed.
- An owner requests a plan invoice, pays outside the app using office instructions,
  and submits a transfer/cash-receipt reference and payment date. A JPG/PNG/WebP/PDF
  receipt is optional, private and limited to 5 MB. The server fixes the amount
  and currency from the invoice; clients cannot approve or discount a payment.
- Office → **Subscriptions & payments**: search businesses, create invoices,
  configure transfer instructions/support email, inspect receipts and approve or
  reject submissions. Approval requires confirmation that the full payment,
  currency and reference were verified. Rejection requires a reason visible to
  the owner. Payment submission alone never grants paid access.
- Approval grants one immutable paid period. Repeated requests/reviews do not
  double-charge, duplicate a submission or extend the period twice. References
  cannot be reused for another pending/approved payment within the business.
- Unpaid invoices can be voided, with a reason, while no payment awaits review.
  Paid invoices cannot be voided or rewritten. Rejected payments can be replaced
  by corrected submissions.

## Time, plans and cancellation

Paid access begins on approval when no paid period remains. Renewals and plan
changes start after the last paid period, preserving paid time. There is no
proration. A future paid period cannot be stacked indefinitely: at most one next
period can be purchased before it starts. Month/year boundaries use calendar
months without overflow; a month beginning on 31 January ends on 28/29 February.

Current periods use their own plan snapshot; a future downgrade does not change
current features early. Editing the plan catalog affects new quotes, not issued
invoices or existing paid periods. Archived plans stop new quotes but existing
quotes can still be reviewed and paid.

Paid periods can have 0–30 grace days. Trials have no additional grace. Access
checks derive state from timestamps on every request, so expiry is enforced even
if the scheduler is unavailable. States include trialing, active, grace,
canceling, canceled, expired, suspended and unsubscribed.

Canceling stops future automatic renewal invoices and retains all already paid
periods. It removes grace after covered access ends. Manual approval does not
silently undo cancellation; the owner can explicitly resume renewal invoicing.
A subscription payment never lifts a separate administrative business suspension.

This is **manual billing**: no bank connection, automatic charge, partial payment,
refund, tax calculation, currency conversion or external payment-provider webhook
is implied. Zero-price public plans are intentional free plans and activate
without fabricating a payment. Money is stored as integer minor units with two
decimal places; currencies are kept separate, not summed into a mixed total.

## Enforcement and offline behavior

- Both web and API business operations check subscription access. POS/cashier,
  dedicated offline batch endpoints and reports check their plan features.
- Owners can still reach billing, profile/password, receipt download and logout
  after expiry. Staff receive a clear renewal message; they cannot enter billing.
- Branch, active-account and non-deleted medicine limits are enforced at model
  writes under a business-row transaction lock, including CSV imports and APIs.
  Account creation rolls back if membership capacity is exceeded. Reactivating
  an account and restoring a soft-deleted medicine also consume capacity.
- Active accounts include the owner. Inactive accounts free account capacity;
  inactive branches still count. A downgrade preserves existing data even if
  usage is over its new cap, but blocks additional capacity until usage is reduced
  or a suitable plan becomes effective.
- Cashier subscription errors preserve its token, cart and pending sales. A
  paused screen offers retry after renewal. No silent queue deletion occurs.
- Cached expiry is observed offline. The server remains authoritative when a
  device reconnects; device clocks and local storage cannot authorize server
  writes. Existing queued sales are preserved if offline sync is excluded.
- Online cashier checkout has its own idempotent endpoint and works without an
  offline-sync feature. Online-only plans do not create new offline sales. A
  lost online response retains the cart and checkout reference for safe retry.
- Signed product/logo downloads also require current business subscription
  access. Billing receipts instead require owner/office authorization, so an
  expired owner can still resolve payment review.

## Installation and scheduled renewals

Use the Phase 1 PHP/MySQL requirements. For a new installation:

```sh
composer install
npm ci
php artisan migrate --seed
npm run build
php artisan platform:admin office@example.com
```

Newly provisioned businesses automatically receive their initial trial. For the
existing **development** Phase 1 installation, apply the new billing migration
then explicitly initialize missing subscriptions:

```sh
php artisan migrate --force
php artisan subscriptions:initialize --force
```

Initialization never resets an existing trial or subscription. Missing
subscriptions are denied business access; there is no legacy unlimited-access
fallback. The original single-business foundation still requires a fresh database
when installing from an older pre-Phase-1 app. No backward-compatibility layer is
part of Phase 2.

Configure the host to run `php /PRIVATE_APP/artisan schedule:run` every minute.
Laravel schedules `subscriptions:process` hourly with an overlap lock. Processing
reconciles states and creates one upcoming manual invoice seven days before a
paid term ends. It does not charge a bank account or invent a paid plan for a
trial. Repeated/overlapping processing is serialized by business and idempotent.
Owners/office can request invoices manually without waiting for the scheduler.

Office users must set their real prices and payment instructions before accepting
payments. Local development examples and test receipts are never deployed as
commercial data. Outgoing mail is not required for this manual approval workflow;
no external email is sent by its renewal processor.

## API and data contract

- `/api/account/subscription`: current account's business status.
- `/api/billing`: owner-only catalog, usage, invoices, payments and instructions.
- `POST /api/billing/invoices`: `plan_id`, UUID `request_key`.
- `POST /api/billing/invoices/{id}/payments`: UUID `request_key`, `method`
  (`bank_transfer`, `mobile_wallet`, `cash`), `reference`, `payment_date`, optional
  `receipt`; amount/currency/business ID cannot be supplied by the client.
- `POST /api/billing/invoices/{id}/void` and `PUT /api/billing/renewal` manage
  unpaid invoices and renewal preference. Office review remains office-only.
- `POST /api/cashier/checkout` accepts a single sale with a persistent client
  reference. Dedicated `/api/sync/*` and `/api/v1/sync/*` remain batch/offline APIs.
- Expiry returns HTTP 402 with `code: subscription_required`. Feature exclusion
  returns HTTP 403 with `code: feature_unavailable` and a feature name. Invalid
  credentials/inactive memberships remain distinct authentication/access errors.

Billing models are central platform records. Controllers explicitly restrict
owner queries by the resolved business; office routes require a separate active
platform account. Invoice/payment/period business associations and payment
submitter memberships have database constraints. No tenant business ID from a
request selects the operating business.

## Verification

Run feature tests only against an isolated disposable database:

```sh
DB_DATABASE=mkpos_phase1_testing APP_ENV=testing php artisan test
node --test tests/js/automaticPricing.test.mjs tests/browser/business-storage.test.mjs
npm run build
```

The complete suite passes **161 tests and 901 assertions**, including 23 new
billing/entitlement tests. Coverage includes snapshots, manual review, explicit
approval, retries, references, expiry/grace boundaries, cancellation, queued plan
changes, resource limits and rollback, receipt isolation, free plans, suspension,
repeatable renewal processing, and online checkout without offline sync.

Nine end-to-end Chromium flows pass against a separate synthetic database:
plan creation, payment instructions, owner invoices, receipt upload/download and
approval, business isolation, cashier lock with queue/token preservation, renewal
recovery using the same token, compact mobile dialogs, and a real synthetic cashier shift/online checkout/receipt. Both production builds
and JavaScript checks pass.
