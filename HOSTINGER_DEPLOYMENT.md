# Hostinger deployment

Phase 3 is live at https://pharmacypos.pwarmyar.com using Laravel 13.42.0 and
PHP 8.4.27. The deployed application source is commit `518087c`, including founder finance, the expanded platform office, password recovery,
owner self-registration and the Phase 3 platform operations/security features. HTTP redirects to HTTPS.
No Git push was needed for this archive deployment.

## Founder finance and bank books — 19 November 2026

Source `518087c` is live in `_pharmacy_founder_finance_518087c` for both pharmacy
entries. The office Finance group provides bank books, founder capital/profit
statements, future share settings, income/expenses, transfers, allocations,
payouts, capital returns, opening adjustments, preserved reversals and CSV.
See [FOUNDER_FINANCE.md](FOUNDER_FINANCE.md) for setup and accounting rules.

Seven additive tables were migrated. Approved subscription receipts are imported
once without invented receiving books; future approvals post their receipt in
the same transaction, using the selected currency's current default. The
idempotent `platform:finance-sync` reconciliation ran during preparation and
cutover and is scheduled every five minutes under the existing app scheduler.
No founders, bank books, manual entries or balances were seeded live. At
preparation there were zero approved payments and zero finance records.

Private rollback backups saved the preceding app and both public entries
(26,327,327 bytes) and SQL database (129,688 bytes). A fresh encrypted backup
was created and verified after migration/reconciliation (17,829 bytes). Existing
account credentials, sessions, APP_KEY, SMTP settings, subscription data and
password-only office access were retained. The persistent backup directory and
key remain. Both caches were purged, the scheduler was repointed and confirmed,
and the previous pharmacy job was removed; the four unrelated jobs remain.
Temporary installer/archive were removed and return HTTP 404.

Validation: **263 tests and 1,654 assertions**, both production builds, two
JavaScript checks, ten synthetic end-to-end browser groups and **ten live
groups** passed. Tests cover exact cents, snapshots, retries, receipt atomicity,
legacy import/repair, currencies, overdrafts, founder obligations, reversals,
role/privacy boundaries and separate export/write throttles. Live checks were
read-only apart from normal authentication/audit records: no founders, bank
books, manual entries, allocations or actual money movements were created.
Existing business identity, billing, medicine pricing, cashier offline shell
and the original reference office all remain available.

Rollback restores both preceding entry scripts/manifests and scheduler target.
Keep the additive finance tables and all newer operational records. Do not
restore an old SQL dump over posted transactions. On a later reactivation,
finance reconciliation recovers approved receipts missed while the old source
was active. No Git push was performed.

## Expanded office administration — 19 November 2026

Source `b18bf6d` is live in `_pharmacy_office_features_b18bf6d` for both
pharmacypos.pwarmyar.com and pharmacyposoffice.pwarmyar.com. The reference app
at office.pwarmyar.com remains unchanged. The release adapts business detail,
account/admin management, trial extensions, subscription calendar, payment
destinations, collection reporting/CSV, audit filters and application health to
the existing pharmacy architecture. See [OFFICE_FEATURE_COMPARISON.md](OFFICE_FEATURE_COMPARISON.md)
for feature mapping and explicitly deferred modules.

The additive migration creates `platform_payment_methods`. Existing accounts,
passwords, tenant records, subscription periods, invoices, roles, SMTP settings
and backup encryption key are retained. Both entries share one private release
and database. Existing sessions were copied during preparation and activation;
mandatory office MFA remains disabled. No synthetic users, businesses, plans,
payments or bank destinations were seeded live.

Before migration, private rollback backups saved the old app and both public
entries (25,276,245 bytes), plus full SQL (127,193 bytes). An encrypted database
and private-file backup was created and verified after migration (16,846 bytes).
The persistent backup directory and key were retained. The new scheduler job
executed successfully; the previous pharmacy job was removed and all four
unrelated PwarMyar jobs remain. Both hosting caches were purged. Temporary
installer and archive were removed and return HTTP 404.

Validation: **235 tests and 1,526 assertions**, both builds, two JavaScript
checks, nine complete synthetic browser workflow groups plus one populated-chart/
CSV/mobile check, and **11 live groups** passed. Live checks covered all new
sections, normal office login, role separation, mobile forms, CSV export,
existing business identity/billing/pricing, cashier offline access and the
unchanged reference. Live checks created no accounts or payment records and
changed no passwords. CSV export writes its normal audit entry.

Rollback restores both old public entry scripts/manifests from the private
backup and repoints the pharmacy scheduler to the preceding private release.
Retain current SQL data and the additive payment-method table; do not overwrite
new customer records with an old SQL dump. No Git push was performed.

## Office password login — 19 November 2026

The user requested removal of mandatory office authenticator verification.
Sources `966398d` and `d9fd5a5` are deployed on both Pharmacy POS entries, with
`PLATFORM_MFA_REQUIRED=false` in the private environment and rebuilt config
cache. Security navigation is hidden; its old URL returns to the dashboard and
setup/code endpoints are disabled. Email/password login and office role checks
remain. No account passwords, business data or schema changed. The full suite
passes 218 tests (1,407 assertions); both builds and JavaScript checks pass.
Five live groups passed for password-only entry, hidden Security links, office
sections/profile, security URL redirect and business login/pricing. Temporary
deployment files were removed (HTTP 404) and private rollback copies retained.
See [PLATFORM_OFFICE_HOSTING.md](PLATFORM_OFFICE_HOSTING.md) for operations.
No Git push was performed.

## Dedicated office portal — 19 November 2026

The platform office is now available at https://pharmacyposoffice.pwarmyar.com,
using source `446b168`, existing office accounts/MFA and the existing database.
The separate PwarMyar Office at office.pwarmyar.com remains untouched. The new
entry has office-only login, a separate secure cookie, SSL and HTTPS redirect.
No migrations or additional scheduler were needed. Shared application files
were backed up and checked before deployment. Full validation passed 217 tests
(1,382 assertions), both builds, two JavaScript checks and seven live groups.
See [PLATFORM_OFFICE_HOSTING.md](PLATFORM_OFFICE_HOSTING.md) for paths, limitations,
future deployment requirements and rollback. No Git push was performed.

## Units & pricing editor — 19 November 2026

Frontend source `9df8299` is deployed over the existing recovery release. The
product create/edit form now uses seven columns, pairs modes with their prices,
right-aligns numeric values, and gives the active input one clear outline.
The unit name stays pinned during mobile scrolling; accessible row errors wrap
below the table. Add/remove actions restore keyboard focus and keep one default
selling unit. Pricing calculations and submitted fields retain their existing
behavior.

Only compiled admin assets and `resources/js/Pages/Products/Edit.jsx` changed
on the server. No PHP backend, environment, database, scheduler, account or SMTP
settings changed. The previous manifest and JSX are retained in a private backup;
old hashed assets remain available for open browser tabs. Rollback restores that
manifest and JSX without touching business data.

Both builds, two JavaScript checks, and six local browser groups passed. Four
live checks passed using normal owner login and unsaved form edits: table/focus,
default/removal, mobile sticky column, and clean navigation/logout. No product
was saved. Cache was cleared and temporary archive/installer removed (HTTP 404).
No Git push was performed.

## Password recovery — 19 November 2026 (application timezone)

Source `fe0d7e3` is live in `_pharmacy_password_recovery_fe0d7e3`. Recovery uses
expiring, one-use links and revokes the reset account's browser sessions and API
tokens. The migration adds `users.auth_epoch` and encrypted platform sender
settings. Existing passwords, business records and office MFA are retained.
Existing web users sign in again after deployment; existing cashier API tokens
remain valid until logout or a password reset.

The user will configure Hostinger SMTP personally at
[Platform office → Email](https://pharmacypos.pwarmyar.com/platform/email).
Delivery is initially disabled and the public form reports that honestly.
No existing mailbox passwords, DNS records or live account passwords were
changed. No real email was sent; external delivery requires user setup and an
inbox test. See [PASSWORD_RECOVERY.md](PASSWORD_RECOVERY.md).

Before activation, private rollback copies saved app/public files (21,695,716
bytes) and SQL (108,169 bytes). An encrypted backup was created and verified
(14,299 bytes); the persistent backup directory and key were retained. The new
pharmacy cron executed successfully, the previous pharmacy job was removed,
and all four unrelated jobs remain. Hosting caches were purged. The temporary
installer and archive were removed and both return HTTP 404.

Validation: **210 tests, 1,338 assertions**, both production builds, JavaScript
checks, seven synthetic browser groups and six live groups passed. Live checks
covered compact recovery, invalid links/security headers, existing owner login,
office MFA, mobile layout and cashier offline reload. No Git push was performed.
For rollback, preserve operational data and reset password hashes, invalidate
older browser sessions and review token revocation as documented in recovery
operations. Do not restore an old SQL dump over current business records.

## Owner self-registration — 18 November 2026

Owners can now create a business at `/register`, linked from the login page.
Source `1dc6d4f` is deployed in the private `_pharmacy_owner_signup_1dc6d4f`
release directory. Signup creates a separate owner, business, first branch,
defaults and initial trial atomically. Existing office provisioning remains.
No schema changes or resets were needed. See [OWNER_SIGNUP.md](OWNER_SIGNUP.md).

The complete suite passes **191 tests and 1,217 assertions**. Seven synthetic
browser flows passed for signup, isolation, duplicate emails, repeated login
and mobile layout. Six live groups passed for signup discovery/validation,
existing owner identity, office MFA, mobile layout and cashier offline reload.
No synthetic business accounts were created live. The first trial is not gated
by email verification, and the feature does not send verification emails.

Before activation, private rollback copies saved the previous app/public files
(21,212,011 bytes) and SQL database (106,678 bytes). A new encrypted backup was
created and verified (13,862 bytes). The persistent backup directory and key
were retained. The pharmacy cron job now points to this release, its output
confirms execution, and all four unrelated jobs remain. Hosting caches were
purged. The one-use installer and archive were removed and return HTTP 404.
Existing passwords, business IDs, office MFA and operational records are retained.
No Git push was performed.

For rollback, restore the preceding Phase 3 front controller/manifests from the
private backup and repoint the pharmacy scheduler. Retain the database and any
businesses created after signup became available; the preceding Phase 3 schema
can read them. Never restore an older SQL dump over newly created businesses.

## Phase 3 deployment — 18 November 2026

The initial source `33bbfef` and its compiled assets were installed into the
private `_pharmacy_phase3_33bbfef` release directory. The final source is
`80fb223`: fixed, hash-checked follow-ups require office MFA for profile/password
changes and version cashier worker/manifest requests. Original framework, route
and worker files were retained privately before those updates. Existing accounts, passwords, business IDs and the shared
Phase 1 database were retained. No QA businesses, plans, tickets or financial
transactions were deployed.

Before migration, private rollback backups saved the Phase 2 app/public files
(20,567,132 bytes) and full SQL snapshot (98,729 bytes). Phase 3 also created and
verified the first AES-256 scheduled backup on Hostinger (12,957 bytes). Scheduled
archives live in a persistent private sibling directory, outside public_html and
release directories, with their encryption key kept only in the private server
environment. Exact paths are in the ignored `.hostinger/site.json`.

The pharmacy scheduler now targets the active Phase 3 directory using PHP 8.4.
Provider cron output confirms successful execution. The previous pharmacy job
was replaced; all four unrelated pwarmyar.com jobs remain. Laravel schedules
hourly subscription processing and daily encrypted backup at 02:15 in the
configured application timezone. Backup success metadata was current at rollout.

Office accounts retain their credentials and owner role, but their next login
requires authenticator enrollment before entering office pages or editing their
account. No live office secret or recovery code was enrolled by the deployment.
The user must save the recovery codes displayed during enrollment. Separate
owner and cashier login flows continue to work directly in their assigned business.

Local verification passes **181 backend tests and 1,128 assertions**, ten browser
flows including cashier checkout/offline reload and the office account MFA
boundary, both frontend builds and JavaScript checks. A disposable-database
restore rehearsal verified the encrypted archive and matched source/restore
records. Production Composer and npm dependency audits report zero advisories;
the remaining build-only glob advisory is documented in [PHASE_3.md](PHASE_3.md).

The final offline rerun identified a stale service worker in the hosting cache.
The worker and manifest URLs now carry a version, hosting caches were purged,
and final verification requires the current `cashier-shell-v6` cache before
checking offline reload. Pending cashier sales and browser account storage were
preserved; this update only replaces the public application shell cache.

Final live verification passed ten groups: owner/office access, operational
pages, billing, support/export forms, private export manifest/tenant checks,
secure cookies/headers, cashier login, current-worker offline reload, file
protection and clean logout. No operational QA data was created. All four
uploaded temporary files were removed and return HTTP 404. Private rollback
copies and encrypted scheduled backups remain. The remaining deployment action
for the user is office authenticator enrollment at the next office login.

For rollback, restore the Phase 2 front controller/public manifests from the
private pre-Phase-3 backup, then repoint the pharmacy scheduler to Phase 2. Retain
the shared database and all new data. Phase 2 ignores the added office/support
columns and tables; do not reset the database or overwrite newer operational
records with an earlier SQL dump. Keep scheduled backup archives and their key
when rolling back. Full recovery procedures are in
[OPERATIONS_RUNBOOK.md](OPERATIONS_RUNBOOK.md).

## Phase 2 deployment — 18 November 2026

The subscription migration and initialization ran against the existing Phase 1
database after a private file backup (19,867,559 bytes) and full SQL backup
(88,331 bytes). The database, owner identity, office identity and their passwords
were retained. The existing business received its initial 14-day trial, ending
2 December 2026. No synthetic plans, receipts or payment records were deployed.

The new private release directory is `_pharmacy_phase2_a2fccdf`. Its environment
and private media were copied server-side from the retained Phase 1 release.
Only public assets and the front controller were switched. Exact active and
rollback paths are in the ignored `.hostinger/site.json`.

One pharmacy cron job invokes the PHP 8.4 release's `artisan schedule:run` every
minute. Laravel runs `subscriptions:process` hourly with an overlap lock. The
provider's captured cron output confirms execution. The four existing cron jobs
for pwarmyar.com were preserved. Renewal processing creates manual invoices;
it never charges a bank account or sends email.

All nine live Chromium check groups passed: preserved owner login, operational
pages, initial-trial billing/usage, office business administration, plan and
payment screens, cashier business/subscription binding, offline shell and logout,
private-file protection, and owner/office logout. No JavaScript errors, HTTP 5xx
or operational test records were produced. The protected installer and uploaded
archive were removed; both return HTTP 404. Rollback backups remain private.

The backend suite passes **161 tests and 901 assertions**. Nine additional local
Chromium flows exercised payment submission/receipt approval, isolation,
expiry/renewal recovery, mobile dialogs and a synthetic online sale. Both
production builds and JavaScript checks pass. See [PHASE_2.md](PHASE_2.md).

Before accepting payments, the platform office must configure real plan prices
at `/platform/plans` and bank/wallet instructions at `/platform/billing`.
Existing owner and office credentials are unchanged.

For Phase 2 rollback, restore the Phase 1 front controller and public manifests
from the retained private Phase 2 file backup, and remove or repoint the pharmacy
cron job. Retain the shared database; the additive billing tables can remain
unused by Phase 1. Do not reset the database or restore the earlier SQL dump over
new operational records. Re-enable Phase 2 only after reviewing any billing
changes made since rollback.

## Phase 1 deployment history

## Application and data

The platform has independent businesses with one business per user account and
multiple branches inside each business. Owner and cashier logins enter their
assigned business directly. A separate platform office account administers
business provisioning and suspension at `/platform/businesses`.

Preflight found one owner, one branch and no products, sales, purchases, customers,
suppliers, stock batches or cash sessions. A fresh Phase 1 database was created.
The existing owner's identity/password hash, branch details and business settings
were copied server-side. The owner's sign-in remains unchanged. No operational
QA records or synthetic accounts were imported.

The old database and app were retained. A complete SQL backup and file archive
were saved in a private sibling directory outside `public_html` before cutover.
The exact active app path, database names and rollback directory are recorded in
the private, Git-ignored `.hostinger/site.json`. Never reset either database.

Only the compiled public assets and front controller are served from
`public_html`. Application code, production Composer dependencies, environment,
logs, configuration cache and private business media remain outside that root.
The current app is a separate release directory; `_pharmacy_app` is the retained
pre-Phase-1 app. Do not assume the old directory is the active release when
performing future updates.

The public storage disk still serves account avatars from `public_html/storage`.
Its production configuration cache explicitly points there. Product images and
business logos use private business-prefixed storage and authorized/signed routes.
No storage symlink is required.

## Deployment and verification

The release used fixed-operation, token-protected temporary installers. They
prepared the new app/database, saved backups, checked that the old live data had
not changed, then switched the front controller. The uploaded archive and all
three one-use endpoints were removed after verification. No deployment tokens,
database credentials or account passwords are committed or served publicly.

The live Sales page exposed one remaining reference to the removed users.branch_id
column. Its query now selects user identity fields and filters branch access
through business memberships. A two-business staff regression test was added;
the full backend suite passes: **138 tests, 788 assertions**.

Live Chromium checks passed:

- Preserved owner login and automatic business assignment.
- Products, inventory, suppliers, purchases, customers, sales, reports, settings,
  business members and profile pages.
- Separate platform office login, business metadata and create-business form.
- Cashier login without a business selector, assigned business/branch display,
  offline reload and token logout after reconnecting.
- Denial of environment/source files, hidden archive and unauthenticated installer
  access, followed by confirmation that the temporary files were removed.
- Owner and platform office logout, with no JavaScript errors or HTTP 5xx in the
  final verification run.

The login and cashier shell both return HTTP 200. Missing routes return 404 and
`.env` requests are forbidden. No operational test data was created on the live
site. Existing pwarmyar.com applications, DNS and email records were unchanged.

## Access and operational notes

Owner access remains as previously configured. Platform office credentials are
provided separately in a private workspace file, never in repository docs.
Outgoing mail still uses the existing log transport; configure SMTP before
relying on password-reset email delivery. Physical receipt printing remains a
hardware-specific check. Subscription billing is delivered in Phase 2 above.

For rollback, restore the retained old front controller/public manifests from the
private file backup so the site uses the untouched old app/database. The retained
SQL dump is an additional recovery copy; changing the front controller does not
require deleting or resetting the new Phase 1 database.
