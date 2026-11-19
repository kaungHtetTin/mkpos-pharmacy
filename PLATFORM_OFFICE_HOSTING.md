# Dedicated platform office

The platform office is live at https://pharmacyposoffice.pwarmyar.com.
The original https://office.pwarmyar.com remains the separate PwarMyar Office app
and was not modified. Businesses continue at https://pharmacypos.pwarmyar.com,
including owner signup and the cashier app.

## Architecture and access

The new subdomain has its own public directory and compiled frontend. Its PHP
entry boots the existing private Pharmacy POS release with per-request overrides
registered after configuration loading: office-only mode, canonical APP_URL,
a separate secure host-only session cookie, and the office public path. These
overrides work with Laravel's configuration cache and do not rewrite the shared
.env or cached business configuration.

Existing office accounts, passwords, role restrictions and MFA enrollment are
retained. Business credentials cannot sign in through the office portal; office
login now opens the dashboard using email and password. Mandatory authenticator
verification was removed at the user's request on 19 November 2026. The Security
links are hidden, the old security URL returns to the dashboard, and setup/code
submission is disabled. The production setting is `PLATFORM_MFA_REQUIRED=false`. Signup, tenant routes, cashier and APIs are unavailable on the
office entry. Office password recovery only issues/accepts tokens for office
accounts and generates links on the office origin. Mail configuration is the
same encrypted platform setting used by the business entry.

Both entries use the existing database, encryption key and private storage.
There is no second database, no migration, no new account seed, no duplicate
scheduler and no separate subscription state. Office-cookie isolation does not
provide a separate database/server security boundary; this is a dedicated entry
to the same trusted platform application.

## Hosting and rollout

Source `446b168` adds the office-only boundary and login branding. Hostinger's
new `pharmacyposoffice` subdomain uses
`/home/u588757048/domains/pwarmyar.com/public_html/public/pharmacyposoffice`.
It boots the private application recorded in the ignored `.hostinger/site.json`.
Hostinger created the DNS record and activated SSL and HTTP-to-HTTPS redirect.
Existing email records (MX, SPF, DKIM, DMARC), other subdomains and the original
office app were preserved. The new website retains its existing PHP 8.5 runtime;
no runtime setting change was applied. The shared pharmacy entry remains on PHP
8.4. No Git push was performed.

The deployed frontend uses office-specific branding, hides business signup and
uses the compact auth layout. Source changes to shared PHP files were checked
against the reviewed hashes and backed up privately before activation. The
initial subdomain placeholder was also retained privately. Temporary deployment
archive and installer were removed after verification and return HTTP 404.

## Validation

The complete suite passed **217 tests and 1,382 assertions**. Both production
builds and both JavaScript checks passed. Seven live Chromium groups verified:

- HTTPS, office branding, origin-correct routes and no signup link.
- Blocked tenant signup, cashier and API paths.
- Rejection of business credentials on the office login.
- Existing office credentials, MFA protection and a separate secure host-only cookie.
- Compact mobile login and invalid password-recovery links.
- Existing pharmacy login and the current pricing editor.
- The original office app and HTTP-to-HTTPS redirect.

Live verification did not enroll/reset MFA, change passwords, send email,
create businesses or run migrations. Authenticated office screens behind the
user's existing MFA were not bypassed for testing.

## Future deployments and rollback

Keep both public manifests/assets current when deploying shared frontend changes.
When the private release directory changes, regenerate the office entry from
`deploy/hostinger-office-index.php` with the new verified private path. Preserve
its office-only/configuration overrides and retain one pharmacy scheduler.
Do not copy `.env`, private storage or backups into either public directory.

The private office-entry backup contains the previous shared PHP/JS source,
the staged office build and the initial placeholder; its path is recorded in
`.hostinger/site.json`. To withdraw the portal, restore those source files and
the placeholder, remove its public index and invalidate the changed PHP files
in OPcache. Retain business data and do not restore an old SQL dump for rollback.
Coordinate any later source changes before restoring shared files.

## Password-only office login update — 19 November 2026

Sources `966398d` and `d9fd5a5` disable the requirement and remove its navigation
from the sidebar, account menu and section toolbar. The auth footer no longer
claims that two-factor authentication protects the office entry. Production
.env and its rebuilt Laravel configuration cache both disable the requirement;
the default configuration is also disabled. Existing account credentials,
roles, cookies, data and schema are retained. Optional authenticator code remains
available in source for explicit future re-enablement; setup and challenge
endpoints are inactive while disabled.

The full suite passed 218 tests and 1,407 assertions, both builds and JavaScript
checks passed. Live verification covers direct password login, removed Security
links, all office sections/profile, the old security URL redirect, and normal
business login/pricing. Private backups retain the prior configuration and
manifests. No Git push was performed.

## Expanded office administration — 19 November 2026

The current shared private release is `_pharmacy_office_features_b18bf6d`, source
`b18bf6d`. Both public entries now target it; the pharmacy scheduler was repointed
and verified. Existing identity, session cookies, copied sessions, encrypted mail
settings and password-only office access were retained. One additive payment
method table was migrated; no synthetic bank details or account seeds were
installed. See [OFFICE_FEATURE_COMPARISON.md](OFFICE_FEATURE_COMPARISON.md) for
coverage and [HOSTINGER_DEPLOYMENT.md](HOSTINGER_DEPLOYMENT.md) for backup and
validation evidence. New office navigation groups are Workspace, Subscriptions
and Office settings, filtered by the existing role model.

## Founder finance — 19 November 2026

Both entries now share `_pharmacy_founder_finance_518087c`, source `518087c`.
The pharmacy scheduler points to this release, including five-minute approved
receipt reconciliation. Credentials, copied sessions and password-only office
login remain unchanged. The seven-table additive finance migration introduced
no seeded founders, bank accounts or balances. See [FOUNDER_FINANCE.md](FOUNDER_FINANCE.md)
and the deployment log for validation, backup sizes and rollback requirements.
