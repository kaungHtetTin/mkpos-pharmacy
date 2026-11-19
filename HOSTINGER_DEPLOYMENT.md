# Hostinger deployment

Phase 3 is live at https://pharmacypos.pwarmyar.com using Laravel 13.42.0 and
PHP 8.4.27. The deployed application source is commit `518087c`, including founder finance, the expanded platform office, password recovery,
owner self-registration and the Phase 3 platform operations/security features. HTTP redirects to HTTPS.
No Git push was needed for this archive deployment.

## Mobile business-owner POS flow — 20 November 2026

Phones and tablets below the desktop breakpoint use a dedicated Products → Cart →
Payment → Sale complete flow. Search and category/price-type controls lead the
catalog, products use touch-friendly rows, and a safe-area-aware bottom dock keeps
the cart total and next action reachable. Infinite loading, explicit load-more
recovery, barcode search, unit selection and business price types are retained.

Cart rows put quantity and unit controls first, with free quantities under Item
options. Changing the overall price type and clearing the cart require explicit
confirmation. Combined base-unit stock, missing prices and invalid quantities
block payment. A closed shift permits browsing but requires opening a shift before
payment. The payment page supports walk-in or named customers, new customers,
payment methods, cash/change, credit eligibility and due dates. Full upfront
payments must use Pay in full. Failed submissions retain the cart; the completion
screen appears only after the server returns a receipt, with printing and Start
new sale actions. Same-page browser Back preserves the cart between steps.

Desktop POS, cashier PWA assets, Laravel backend logic and database schema are
unchanged. Browser scenarios use intercepted product/payment fixtures; they do
not create operational sales. Shared navigation, login, loading and business
storage regression checks also pass. Live deployment verification is recorded
below after publishing.

## Separate mobile app bar — 20 November 2026

Frontend patch `e92e3ea` gives phones and tablets below the desktop breakpoint a
dedicated header with navigation/back controls, an ellipsized page title, workspace
or POS branch subtitle, account menu and utility menu. Page tools occupy a separate
row only when available. Search expands across that row; primary actions stay
visible and secondary actions use an overflow popover. Language, theme, refresh
and POS branch choices are available through More options. Desktop action order
and toolbar layout are retained.

The overflow popover renders the original action components so links, disabled
states, callbacks and asynchronous CSV downloads continue to work. Mobile targets
are 40–44px, and controls fit without horizontal toolbar scrolling. A measured
app-bar height offsets page content and the loading progress line. Viewport forms
recalculate after toolbar-height changes so their footers remain visible.

Both production builds passed. Five responsive browser scenarios passed locally
and live at 320×780, 390×844, 768×1024, 844×390 and 1440×1000 across inventory,
suppliers, purchases, customers, products, settings, product forms and POS. Checks
cover header/tool geometry, form footer placement, primary actions, drawer and
account menus, theme switching, expanded search, filters, asynchronous CSV export,
overflow navigation and resizing between mobile and desktop without duplicate
portal targets. Five existing login/loading/storage checks also passed. No live
business records were submitted by these browser checks.

This is a frontend-only patch; Laravel backend logic and database schema are
unchanged. Private source/manifest and encrypted backups are retained. Temporary
deployment files were removed; no Git push was performed.

## Business-specific price types — 20 November 2026

Source patch `261dabd` adds business-owned custom price types while retaining
Retail and Wholesale as permanent defaults. Settings → Automatic Pricing manages
names, display order, availability and versioned manual/automatic rules. Products
keep the two default prices in their compact table, with Additional prices for
per-unit custom amounts and automatic previews. Customer preferences apply at POS
and can be explicitly overridden by the cashier.

Admin POS, cashier and offline sync validate active business types and authoritative
prices. Missing custom prices never silently use Retail or zero. Sales snapshot
the selected code/name and actual prices, preserving history after renames or
deactivation. Unit-price CSV templates support business-specific custom columns;
product imports initialize applicable automatic custom prices. Customer preferences,
custom unit prices and historical names are included in existing business exports.
See `BUSINESS_PRICE_TYPES.md` for details and offline price-change handling.

Migration 2026_11_20_000003 adds rule metadata, unit custom-price JSON, customer
preferences and sale-item label snapshots, and widens the previous price-type
enum. Default sale labels were backfilled. Deployment verified stock totals,
existing Retail/Wholesale price totals, sale counts and sale amounts unchanged.
No example custom types, products, customers or sales were created on the live
server. The cashier assets/manifest and cashier-shell-v7 worker were published
together; admin assets were published on both business and platform-office hosts.

Validation: **316 tests / 2,109 assertions**; five JavaScript pricing/storage checks;
five local login/loading/storage browser checks; and local/live custom-pricing
browser scenarios at 1440px, 390px and offline cashier passed. Scenarios cover
management, manual values, automatic unit previews, preferences, custom selection,
missing-price blocking, cached cart type restoration and business-scoped offline
settings. Browser interactions used intercepted fixtures with no operational
writes. Actual authenticated live reads confirmed permanent default types and
catalog custom-price fields. Both login pages and the v7 worker passed final
checks. Private source and encrypted backups are retained; temporary deployment
files were removed. No Git push was performed.

## Lightweight page loading — 20 November 2026

Frontend patch `0198bbe` replaces the heavy loading blocks with small pale,
brand-tinted placeholders, compact rows and a gentle 1.8-second pulse. The initial
workspace keeps its sidebar and app bar mounted while the lazy page module loads.
Short loads have a 180ms delay before visual feedback to avoid flashing.

Navigation and refreshes keep existing content visible with one soft 2px progress
line that remains below the app bar while scrolling. The redundant product-list
progress line was removed. Existing form reset/preserve semantics apply after a
successful response; validation failures retain the form and entered values.
Failed initial imports provide Retry instead of loading forever. Light/dark themes
and reduced-motion preferences are supported.

Both production builds passed. Five local browser checks passed, including login
validation and cashier storage isolation. Three live loading scenarios at desktop
and phone sizes passed, covering light/dark themes, delayed imports, retained
refresh content, form resets after success, fast-response flicker prevention,
reduced motion, scrolling, request failure and initial-import retry. Loading tests
use intercepted read-only responses; no live operational data was created.
Laravel backend logic and database schema are unchanged. Private rollback backups
are retained, temporary deployment files were removed, and no Git push was made.

## Inventory detail table spacing — 20 November 2026

Frontend patch `2aadc1a` separates the inventory summary, movement history and batch
section using a responsive grid gap: 24px on desktop and 16px on phones. The shared
legacy wrapper style resets Paper margins, so a component bottom margin previously
had no effect and the tables touched. Grid spacing survives that reset. The batch
table now has a Stock batches heading; the grid column can shrink without causing
page overflow.

Both production builds passed. Local and live Chromium checks at 1440px and 390px
measured the expected gaps and verified filters, pagination, per-product history
and contained table scrolling. This patch changes one frontend source file and
built assets; no backend logic, schema or business data changed. Rollback backups
are retained and temporary deployment files return HTTP 404. No Git push was made.

## Inventory stock movement history — 20 November 2026

Source patch `b175aff` adds a business-wide ledger at `/inventory/movements` and a
per-product ledger in Inventory Detail. Stock Balance has a Movement history
button. Both views provide search, branch/action/date filters, 25-row paging,
Myanmar display dates and a phone layout with table scrolling inside the screen.

The ledger covers purchases and edits/deletions, POS/cashier/offline sales, sale
voids, restocked customer returns, supplier returns, transfers, adjustments and
mobile/offline purchases. Each entry snapshots the product, branch, actor, source
reference, signed base-unit change and before/after branch balances. History and
stock are committed in the same locked transaction. The existing business export
also includes the ledger. See `STOCK_MOVEMENTS.md` for the recording boundary and
operational details.

The additive migration and opening-snapshot command ran during brief maintenance.
Eight nonzero inventory balances were recorded as opening snapshots across the
businesses. Inventory row counts and total quantities were verified unchanged,
and there were zero nonzero inventory rows missing a snapshot. Existing financial
records were preserved. Prior actions are not reconstructed because reliable
before/after snapshots were unavailable.

Validation: **303 tests / 2,039 assertions**, both production builds and two
JavaScript checks passed. Local and live desktop/phone browser checks at 1440px
and 390px used read-only fixtures to verify global and product history, filters,
pagination, Myanmar time, empty states and contained table scrolling. No live
stock transactions were submitted. Both login pages remain available. Source and
encrypted backups are retained, and temporary deployment files were removed.
No Git push was performed.

## Searchable stock-adjustment product picker — 20 November 2026

Source patch `c4a5cf6` replaces the Product dropdown in New Stock Adjustment with
a searchable autocomplete. It matches names, generic names and barcodes and shows
secondary details to distinguish duplicate product names. Up to 100 matches are
rendered while searching the full active-product list. Keyboard selection, clear
and no-match states are supported. Processing requires a committed product choice.

Changing or clearing the product, or changing branch, resets the batch selection.
Previous batch requests are canceled and late results cannot overwrite the new
product's batches. The existing read response includes generic name/barcode; no
schema or stock-writing logic changed.

Validation: the existing **292 tests / 1,966 assertions**, both production builds,
two JavaScript checks and desktop/phone browser scenarios passed locally and live
at 1440px and 390px. Browser-only fixtures exercised matches beyond the first 100
products, duplicate names, keyboard selection, batch clearing, slow old responses,
no matches and clearing. Zero adjustment POSTs were made; no stock quantities or
adjustment records were changed. Private source/manifest and encrypted backups
are retained; temporary deployment files were removed. No Git push was performed.

## Inventory In Stock filter correction — 20 November 2026

Backend patch `6c016a7` requires current_stock > 0 as well as current_stock >=
min_stock_level for In Stock filtering. Previously a minimum of zero allowed
zero-stock products through the SQL filter (0 >= 0), while the displayed badge
correctly classified them Out of Stock. Missing inventory rows also aggregate
to zero and had the same mismatch. Filtering now agrees with the existing status
classification before pagination; positive stock below its minimum stays Low Stock.
No inventory values, schema, frontend source or subscription behavior changed.

Validation: the regression test reproduced both zero-minimum and branch-specific
failures before the fix. **292 tests / 1,966 assertions** passed afterward,
including missing inventory, exact thresholds, branch totals and filtered paging.
Read-only live aggregate checks found 63 zero-stock matches under the old rule,
two products meeting the corrected rule, and zero invalid rows in the corrected
result. The live owner endpoint passed In Stock, Out of Stock and Low Stock row
checks. Existing source and encrypted data backups are retained. Temporary
installer/archive were removed; no Git push was performed.

## Screenshot preview inside payment review — 20 November 2026

Frontend patch `e260ad3` displays uploaded payment images directly in the office
Review manual payment dialog, above the decision controls. Reviewers can open
the full-size image or download the original. Private authenticated downloads
are fetched into temporary browser URLs, revoked when the dialog closes. Loading,
retry and older PDF download fallback are supported. The dialog scrolls within
the screen while its review action buttons remain reachable.

Both production builds, two JavaScript checks, four local browser groups and
four live fixture groups passed at desktop/phone widths, including retry/PDF
handling. A separate read-only live check loaded and decoded an actual pending
payment screenshot in the dialog. No review POST, approval or rejection was
submitted. Backend/database and private receipt authorization are unchanged.
Source/manifests rollback backups are retained; temporary deployment files were
removed. No Git push was performed.

## Direct owner subscription screenshot requests — 20 November 2026

Source patch `0d3dc80` replaces owner invoice creation with direct plan/payment
screenshot submission. Owners see all active configured destinations and select
one by name/account details. A private JPG/PNG/WebP screenshot up to 5 MB is
required; transaction reference is optional. Payment dates follow Myanmar time.
Requests atomically create/reuse internal billing records and await office
approval; access and finance receipts change only after approval. Request keys
prevent duplicates and pending requests cannot be submitted twice. Existing
quoted renewal terms are preserved. Type is removed from office payment-method
forms/listings; operator review shows the chosen destination name.

One additive migration adds nullable destination ID/snapshot fields to payments.
Existing destinations, payment history, subscription dates and prices are
preserved. Rollback restores source/manifests while retaining the additive
columns; never drop columns after customer requests are written. Private source
and encrypted database/media backups were taken before migration. MFA, scheduler
and environment remain unchanged. At cutover there were two businesses, one
existing invoice, zero payments and zero paid periods; no live requests, images
or financial transactions were created by validation.

Validation: **288 tests / 1,945 assertions**, both production builds, two
JavaScript checks, six local browser groups and four live groups passed.
Local checks exercised actual image upload, selected-method snapshot, required
file validation, pending status, private access and office approval for the
correct MMK plan period. Live checks opened/canceled forms with browser-only
fixtures, verified every active real destination is listed, mandatory screenshot
and phone sizing, existing history and finance. Temporary deployment files were
removed; rollback backups are retained. No Git push was performed.

## Left-aligned purchase inputs — 20 November 2026

Frontend patch `bc6194d` left-aligns all editable inputs in the purchase wizard,
including quantities, cost, retail/wholesale price, unit, batch and date fields.
The scoped dialog rule overrides numeric right alignment in the shared table
styles, including focused/disabled states, without changing table calculations.
Backend, database and existing business records are untouched.

Both production builds, two JavaScript checks, and desktop/phone browser checks
passed locally and live at 1440px and 390px. The checks exercised the real wizard
with browser-only supplier/product fixtures, verified computed alignment and
focus, and confirmed zero purchase POST requests. No purchases or stock movements
were created. Frontend source and both manifests have private rollback backups;
temporary deployment files were removed. No Git push was performed.

## Automatic internal plan codes — 19 November 2026

Source patch `b81ac1c` removes Plan code from creation/editing and plan listings.
New plans receive a unique internal UUID-based code automatically. Existing codes
remain stable on edits, including when names change. Names may contain Myanmar
text and do not need to be unique. MMK pricing and month durations are preserved.

Validation: **282 tests / 1,893 assertions**, both production builds, two
JavaScript checks, four local browser groups and three live groups passed.
Local checks created/edited synthetic plans without supplying codes. Live forms
were opened/canceled and existing terms/prices were unchanged. No schema migration
or existing-data update was required. Source/manifests and encrypted backups are
retained; temporary deployment files were removed. No Git push was performed.

## Custom plan duration in months — 19 November 2026

Source patch `d9a2bc7` replaces Monthly/Yearly with a required Duration (months)
field accepting integers 1–36. Price for full duration is the total MMK charge.
Office plan listings, invoice pickers and owner billing show the actual month
count with correct singular/plural labels. Existing calendar-month settlement
already supports these durations; no schema migration or data update was needed.
Existing invoices and periods retain their snapshotted durations and prices.

Validation: **281 tests / 1,882 assertions**, both production builds, two
JavaScript checks, four local browser groups and three live groups passed.
Tests cover creating 1-, 2-, 3-, 12- and 36-month plans, rejecting invalid terms,
editing two months to three, owner labels, phone layout, end-of-month-safe paid
expiry, renewal stacking and immutable invoice snapshots. Live forms were
opened/canceled; existing plan terms and prices remained unchanged. Encrypted
and source/manifest backups are retained; temporary deployment files were removed.
No Git push was performed.

## MMK-only platform office — 19 November 2026

Source patch `75040af` makes MMK the fixed currency for new plans, invoices,
FOC grants, bank books and finance entries. Finance/founder statements default
to MMK, forms have read-only currency fields, and controllers plus billing/ledger
services reject new foreign-currency records. Owner billing offers MMK plans.
Whole-kyat amounts omit unnecessary .00 while existing exact minor-unit storage
and fractional accounting remain unchanged. Historical foreign-currency records
are never relabeled, exchanged or merged.

A guarded, repeatable command normalized the zero-price system trial and eligible
zero-value trial snapshots to MMK without changing trial dates or features. No
schema migration, environment change, scheduler change or business POS currency
setting change was required. An encrypted backup and source/manifests rollback
were retained before cutover.

Validation: **279 tests / 1,855 assertions**, a targeted normalization recheck,
both production builds, two JavaScript checks, four local browser groups and
**five live groups** passed. A token-protected comparison against the encrypted
predeployment SQL backup verified byte-identical rows for all three existing
bank books, finance entries/receipts, invoices/payments and subscription periods.
There were no financial transactions before or after deployment. The initial
inspection's USD finance view omitted the existing MMK books; the backup-based
comparison verified the full tables independently of that UI filter. No pricing
or nonzero balances were converted. Temporary deployment files were removed.
No Git push was performed.

## Complimentary plan grants — 19 November 2026

Source patch `ad89ebe` adds Subscription controls → Grant plan FOC. Office
owners/billing operators select an active non-trial plan and 1–36 months, give
an audit reason and confirm. A zero-charge FOC invoice and one subscription
period preserve the plan features/limits without payment, receipt or revenue.
Current paid periods remain intact; future/open-invoice conflicts are validated.
Automatic renewal is disabled. Retries are idempotent and suspension is preserved.
Internal reasons remain in office audit records. No schema migration was needed.

Validation: **276 tests / 1,812 assertions**, both production builds, two
JavaScript checks, three local FOC browser groups and **nine live groups** passed.
The actual grant and zero-finance checks used a synthetic local business; live
checks opened/canceled the desktop and phone forms without granting plans.
Customer records, subscription dates, account credentials and finance data were
unchanged. The source/manifest rollback and encrypted backup were retained;
temporary deployment files were removed. No Git push was performed.

## Unified business administration — 19 November 2026

Source patch `66ab9ba` is live for both pharmacy entries using the existing
private application directory. Businesses and Business accounts now share one
searchable directory and a business detail page with stats, paginated accounts
and billing history, inline account/password/status forms, subscription controls,
invoice create/edit/void, and manual payment recording/edit/review modals.
See [BUSINESS_ADMINISTRATION.md](BUSINESS_ADMINISTRATION.md) for rules and roles.

The patch reuses transactional subscription settlement and finance posting. It
changes backend/application logic and frontend assets, with **no database schema
migration**. The scheduler, environment, database credentials and password-only
office login remain unchanged. Both manifests and changed source files have a
private rollback backup; a new encrypted database backup was verified before
cutover. Previous hashed assets remain available to existing browser sessions.

Validation: **271 tests / 1,747 assertions**, both production builds, two
JavaScript checks, nine local browser groups, and **eight live groups** passed.
Local browser tests created synthetic development records only, exercising invoice
editing, payment approval, voiding, account disabling, password replacement,
suspension/reactivation and phone modals. Live tests opened forms without saving,
verified pagination and existing record counts, owner login/billing/pricing,
finance, mobile sizing, legacy links and the unchanged reference office app.
Deployment counts stayed at two businesses and zero invoices, payments and paid
periods. No live account passwords/statuses or financial records were changed.
The deployment helper/archive were removed; private rollback backups are retained.
No Git push was performed.

## Responsive POS catalog scrolling — 19 November 2026

Frontend source `027451f` is deployed to both pharmacy public asset entries.
The private app path, backend, database and scheduler remain at `518087c`.
On small screens the POS scrolls the document, so the previous catalog-only
scroll listener stopped at 30 products. End-of-list observation now follows
both document and desktop panel scrolling in grid/list views. A Load more
fallback and explicit retry preserve loaded products after request failures.
Search/category changes cancel and discard stale requests.

Both production builds and two JavaScript checks passed. Fourteen browser
groups passed locally and live, using 73 browser-only fixture products across
360, 390, 768, 1024 and 1440px widths in both views. Each page loaded exactly
once, stopped at the last page, recovered from a failed page and discarded
stale category/search responses. The real owner catalog and office finance
access also passed smoke checks. No products, shifts or sales were created.
Private source and both manifest backups are retained; old hashed live assets
remain available for existing sessions. No Git push was performed.

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
