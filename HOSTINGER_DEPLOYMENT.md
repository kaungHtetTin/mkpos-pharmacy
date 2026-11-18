# Hostinger deployment

Phase 2 is live at https://pharmacypos.pwarmyar.com using Laravel 13.42.0 and
PHP 8.4.27. The deployed application source is commit `a2fccdf`, including
subscriptions and manual payment approval. HTTP redirects to HTTPS.
No Git push was needed for this archive deployment.

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
