# Phase 1: multi-business platform, single-business accounts

Implemented on `feat/phase-1-multi-business`. The platform hosts many independent
businesses. **Every business user account belongs to exactly one business.**
Owners, managers, accountants and cashiers follow the same rule. Each business
can have multiple branches and grant its staff access to those branches.
Platform office accounts remain separate and cannot belong to a business.

This changes the Laravel backend and database schema as well as both interfaces.
It uses a fresh database and is deployed at https://pharmacypos.pwarmyar.com.
The old app/database are retained as described in [HOSTINGER_DEPLOYMENT.md](HOSTINGER_DEPLOYMENT.md).
Subscription billing is implemented in [Phase 2](PHASE_2.md).

## Account and login behavior

- Staff and owners sign directly into their assigned business using email and
  password. They never choose from a platform business list. There is no business
  selector, business switching or linking an account to another business in either
  the admin workspace or cashier app.
- Email addresses identify accounts uniquely across the platform. Another
  business needs separate accounts, including a separate owner email/password.
- Assignment is stored internally in `business_memberships` as a one-to-one user
  relationship. A unique `user_id` database constraint prevents a second business
  assignment, even if the first assignment is inactive. Model guards reject
  reassignment and mixing platform office accounts with business accounts.
- Platform office provisions each business with a new owner account and can
  suspend/reactivate a business. Its users do not automatically receive access
  to business stock, customers or sales. Promote/create office accounts only
  with `php artisan platform:admin office@example.com`.
- `/business-members` lets owners manage account roles, access status and primary
  branch. Staff Management creates accounts and maintains additional branch
  access. Protected Owner roles cannot be edited/deleted through Staff/Role
  Management, and account access management preserves the last active owner.
- Branch selection remains available inside the assigned business. Business
  suspension, inactive account access and cross-business branch IDs are rejected
  by the server. Removing staff inactivates access while retaining history.

## Isolation and defaults

Operational tables carry required `business_id`. Eloquent scopes, raw-query
helpers, route binding, exists/unique validation and composite foreign keys
restrict related records to their business. Without a business context,
operational reads return no records and writes require an explicit context.
Barcodes, invoice numbers, role names, settings, pricing state and offline sale
references are independent between businesses.

Business provisioning creates Main Branch, Owner/Manager/Accountant/Cashier
roles and permissions, Tablet unit, Tax Free tax, General expense category,
invoice/currency settings and independent pricing rules/state. No sample stock,
default users or hardcoded passwords are installed.

Product images and business logos use private business-prefixed storage. Admin
media requests require the account's business; cashier clients receive signed
links valid for 15 minutes. Suspended businesses cannot serve their media.
Central platform auditing records authentication, provisioning and status changes
with explicit credential-free payloads.

Cashier Sanctum tokens are bound automatically to the account's business. Products,
customers, carts, sessions and pending sales remain cached under both account and
business IDs to keep records separate when different accounts use one device.
The service worker caches public shell/assets only and supports offline reload
following the initial online visit. Branch switching retains existing shift
and access checks.

## Fresh installation

Use PHP 8.4.1+ with the extensions in Composer, Node/npm and MySQL/MariaDB.
Verification used PHP 8.4.24 and MariaDB; SQLite is not a validated target for the
migration chain.

1. Install dependencies with `composer install` and `npm ci`.
2. Copy `.env.example` to `.env`, configure a **new, empty database**, `APP_URL`
   and mail settings, then run `php artisan key:generate`.
3. Run `php artisan migrate --seed` and `npm run build`.
4. Run `php artisan platform:admin your-office-email@example.com`; a new account's
   password is entered privately at the prompt. An account already assigned to a
   business cannot be promoted: use a separate office identity.
5. Sign in at `/login` and create each business with its own new owner email and
   password. Owners sign directly into their business to configure branches,
   staff, settings and their catalog.

The foundation migration refuses populated single-business installations before
changing its schema. Existing Phase 1 development databases with multi-business
users or platform users linked to a business also fail the new unique-assignment
migration before any assignment is changed. No automatic reassignment or deletion
occurs. Use a fresh development database for the revised design; do not reset or
migrate the existing production database. The foundation's `down()` deliberately
refuses to discard data: restore a verified backup or recreate only a disposable
installation.

Keep tenant media on the private `local` disk, outside any public storage symlink.
Use HTTPS in production, including for the cashier service worker.

## Developer and API contract

Operational jobs/commands enter
`app(BusinessContext::class)->run($business, fn () => ...)` before using business
models or `BusinessQuery`. Do not bypass scopes or use unscoped raw queries for
new operational features. New business-owned tables need model scoping,
`BusinessSchema` registration and appropriate database constraints.

`POST /api/login` accepts credentials and optional device name. It returns the
single assigned business and bearer token, or rejects unavailable access.
Client-supplied `business_id` is prohibited. Business list/switch endpoints are
removed. Request sessions and headers cannot select or override the business;
`X-Business-ID` only detects mismatched writes. Tokens bound to another business
are rejected. Existing branch endpoints continue to enforce business and branch
access.

Local browser storage is scoped, not encrypted, and cannot protect data from a
person controlling the browser profile. Offline devices observe suspension only
when they reconnect; server writes always re-check access. Signed media can
expire offline and is excluded from the shared asset cache.

## Verification

Feature tests recreate tables; use a separate disposable test database:

```sh
DB_DATABASE=mkpos_phase1_testing APP_ENV=testing php artisan test --filter='MultiBusinessFoundationTest|AuthenticationTest'
node --test tests/js/automaticPricing.test.mjs tests/browser/business-storage.test.mjs
npm run build
```

The focused tests cover automatic web/API business resolution, database-level
single assignment, rejection of owner reuse and reassignment, separate office
identities, removed selection/switching endpoints, branch switching, token/media
isolation, validation, suspension, pricing and cashier checkout isolation.

Final release verification:

- Full backend suite: **138 passed, 788 assertions, zero failures**.
- Both frontend production builds pass.
- Cashier storage isolation and automatic-pricing JavaScript checks pass.
- Nine Chromium flows pass: business login, account access, separate records,
  cashier login, branch switching, offline reload, platform office, password
  recovery navigation and profile password validation.
- The repository browser suite also passes with no skipped tests: cashier storage
  isolation and failed-login validation followed by successful login. Set
  `POS_BASE_URL`, `POS_TEST_EMAIL` and `POS_TEST_PASSWORD` privately to run
  `npm run test:browser` against a synthetic preview account.

The previous 65 inherited failures are resolved. Import and pricing fixtures now
use real business roles with the current product/supplier/settings permissions;
API fixtures use persisted business-bound tokens; purchase and return fixtures
include actual branch access, payment permissions and required cash shifts.
Return tests also check that an already completed return cannot be approved twice.
Registration tests explicitly verify that public signup remains unavailable.
No permission checks or tenant guards were weakened to make the tests pass.

The application now loads its authentication routes, including password recovery,
profile password updates, password confirmation and signed verification URLs.
The login form links to recovery. Public registration remains closed; owners and
staff are still provisioned through their authorized business workflows.
Password-reset notifications are tested with fake notifications, so verification
sends no real email. Production mail transport must be configured at deployment.

The live release has also passed owner, office and cashier verification, including
workspace pages, private-file protection, offline reload and logout. The Sales
staff selector uses membership branches and has a cross-business regression test.
The live owner account was preserved while the app switched to a fresh Phase 1
database; the old database and private file/SQL backups remain available.
The original local development database was not reset. Physical receipt printing
and real outgoing mail delivery remain environment-specific checks.

## Phase 2

[Phase 2 subscriptions](PHASE_2.md) now supplies plans, trials/grace, manual
payment submission and office approval, recurring invoices, resource limits and
subscription enforcement. This document records the Phase 1 foundation.
