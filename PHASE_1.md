# Phase 1: multi-business foundation

Implemented on `feat/phase-1-multi-business`. This phase deliberately targets a
new installation; it changes the Laravel backend and database schema as well as
the admin and cashier interfaces. It has not been deployed to the existing site.

## Delivered

- Platform office at `/platform/businesses`: create a business and its owner,
  suspend/reactivate businesses, and review recent platform activity. Platform
  administrators are global identities, separate from business roles. They do
  not automatically gain access to business operational records.
- Global user accounts with separate business memberships, roles, membership
  status, primary/active branch and branch access. One identity can own one
  business and work as a cashier in another. Owners manage memberships at
  `/business-members`; Staff Management creates new staff accounts. Existing
  accounts can be added by email without changing their credentials.
- Business selection after sign-in and switching from the account menu. Stale
  browser writes carrying another business ID receive HTTP 409. Every request
  verifies the selected business and active membership.
- Shared database with required `business_id` on operational records, scoped
  Eloquent models, raw queries, route binding and exists/unique validation.
  Composite foreign keys enforce business ownership across record relations and
  membership pivots. Business barcodes, role names, settings, invoice numbers,
  pricing rules and offline references are independent.
- Business provisioning creates Main Branch, Owner/Manager/Accountant/Cashier
  roles, default permissions, Tablet unit, Tax Free tax, General expense category,
  invoice/currency settings and independent pricing state. No sample customers,
  inventory, default users or hardcoded passwords are installed.
- Product images and business logos use private business-prefixed storage.
  Session requests require the selected business; cashier clients receive signed
  media links valid for 15 minutes. Suspended businesses cannot serve their media.
- Cashier login prompts for a business when an account has multiple memberships.
  Sanctum tokens are bound to one business; switching updates only that token.
  Products, customers, carts, cash sessions and pending sales are stored under
  both user and business IDs. Switching is blocked while unsynced sales remain.
  The service worker caches the public shell/assets only and supports an offline
  reload after the initial online visit.
- Central audit events for sign-in/sign-out, API sign-in, business selection,
  provisioning and status changes, with explicit credential-free payloads.

Business owners retain the existing permission/branch controls inside their
business. Protected Owner roles cannot be edited/deleted through Staff/Role
Management. Membership management prevents removal of the last active owner.
Shared identity details/security must be changed by the account holder when the
account belongs to multiple businesses or is a platform administrator. Reserved
Owner/Root names cannot be assigned to custom roles. Deleting staff inactivates only the
current business membership and preserves historical records.

## Fresh installation

Requirements remain PHP 8.4+ with the extensions declared by Composer, Node for
the frontend build, and MySQL/MariaDB with composite foreign-key support. The
verification environment used PHP 8.4.24 and MariaDB. SQLite is not a validated
target for this migration chain.

1. Install dependencies with `composer install` and `npm ci`.
2. Copy `.env.example` to `.env`, set a **new, empty database**, `APP_URL`, mail
   configuration and filesystem settings, then run `php artisan key:generate`.
3. Run `php artisan migrate --seed` and `npm run build`.
4. Run `php artisan platform:admin your-office-email@example.com`. The command
   prompts privately for a new administrator's password, or promotes an existing
   global identity without resetting its password.
5. Sign in at `/login`, create a business in Platform office, and sign in as its
   owner. Configure branches/staff/settings and add the operational catalog.

The foundation migration checks for existing user/domain rows **before changing
its schema** and refuses a populated installation. It does not backfill an old
business. Earlier migrations are part of the fresh-install chain, so always
select the new database before running migrations. Do not point this branch at
the existing production database. `down()` deliberately refuses to discard the
foundation: restore a verified pre-install backup, or recreate only a disposable
database. No destructive reset is part of the installation procedure above.

Keep tenant media on the private `local` disk; do not expose it through a public
storage symlink. Protect application logs, backups and database credentials as
usual. Use HTTPS in production, including for the cashier service worker.

## Developer contract

Operational jobs/commands must explicitly enter
`app(BusinessContext::class)->run($business, fn () => ...)` before using business
models or `BusinessQuery`. Reads without context return no business records;
writes require a context. Do not bypass the scope or use raw `DB::table` for new
operational queries. The central identity, platform and provisioning paths are
explicit exceptions. New business-owned tables must join `BusinessSchema`, the
model trait and database constraints; adding a request business ID alone is not
sufficient.

API clients submit `business_id` to `/api/login` when prompted by HTTP 409 and
then use the returned bearer token. `/api/account/businesses` lists permitted
businesses; `/api/account/businesses/switch` changes the calling token. The token
and server-side membership determine access; `X-Business-ID` detects stale writes
and never grants access. Public health and login endpoints have no tenant context.

Cashier local storage is account/business scoped, not encrypted or suitable as a
security boundary against another person controlling the same browser profile.
Old unscoped caches are not imported. Offline access cannot observe suspension
until the device reconnects; all server writes re-check access then. Signed media
may expire offline and is deliberately excluded from the shared asset cache.

## Verification

Run tests only against a separate test database; feature tests reset its tables:

```sh
DB_DATABASE=mkpos_phase1_testing APP_ENV=testing php artisan test --filter=MultiBusinessFoundationTest
node --test tests/browser/business-storage.test.mjs
npm run build
```

Verified 17 November 2026:

- 22 multi-business feature tests, 84 assertions: provisioning, fail-closed
  queries, scoped validation, database isolation, web/API permissions, stale
  writes, business-bound device tokens, suspension, owner protection, media
  expiry, pricing isolation and a real cashier checkout affecting one business.
- Cashier storage isolation test passed; admin and cashier production builds passed.
- Six Chromium flows passed against a separate seeded preview: business login,
  memberships, switching data/permissions, responsive platform create form,
  cashier business selection/cache isolation and first-visit offline reload.
- Full suite: 66 passed, 65 failed, 389 assertions. Comparing normalized failure
  names with the recorded 66-case original baseline found no new failing methods
  and one resolved case. Existing failures span authentication routes and legacy
  pricing/operational expectations; see `UPGRADE_LARAVEL_13.md` for the baseline.
  This comparison is regression evidence, not a fully green release gate.

The original development database and live site were not migrated or reset.
The preview/test databases contain synthetic data only. Resolve inherited test
failures and complete deployment/environment checks before approving production.

## Next phase

Subscription plans, recurring billing, payment-provider integration, quotas,
trial/grace periods, subscription enforcement and platform support workflows are
deferred. Business suspension is an administrative control, not subscription
billing. Parent-company business groups and cross-business consolidated reports
also require an explicit later scope.
