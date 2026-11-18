# Hostinger deployment

Phase 1 is live at https://pharmacypos.pwarmyar.com using Laravel 13.42.0 and
PHP 8.4.27. The deployed application source is commit `5ad6f0a`, including the
Sales staff query correction verified during rollout. HTTP redirects to HTTPS.
No Git push was needed for this archive deployment.

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
hardware-specific check. Subscription billing belongs to the next phase.

For rollback, restore the retained old front controller/public manifests from the
private file backup so the site uses the untouched old app/database. The retained
SQL dump is an additional recovery copy; changing the front controller does not
require deleting or resetting the new Phase 1 database.
