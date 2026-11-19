# Pharmacy POS office feature alignment

Reference: https://office.pwarmyar.com (confirmed by the user on 19 November 2026).
Target: https://pharmacyposoffice.pwarmyar.com.

The review used the reference application's public navigation, shipped frontend
modules, forms and API contract references. No reference administrator session
or private business data was used. Features below were adapted to Pharmacy POS's
existing data model and office roles; the reference app itself was not modified.

| Reference capability | Pharmacy POS outcome |
| --- | --- |
| Overview and business summary | Existing overview retained; business names open richer detail pages. |
| Business detail | Added overview, owners/contacts, branches, accounts, usage, billing history, recent activity and support tabs. |
| Business administration | Existing creation/suspension reused; added name/slug editing and reasoned trial extensions before any paid period. |
| Owner email and password management | Added business account directory with name/email/status edits and assisted confirmed password resets. Accounts remain in their original business. |
| Platform users | Added searchable, paginated cross-business account directory with business/status filters. |
| Administrators | Added owner-only office account creation, role/status editing and password resets, with self-lockout and last-owner checks. |
| Subscription calendar | Added monthly effective access deadlines, business search, day selection and paginated event list. Includes applicable grace/prepaid access. |
| Plans and subscription payments | Existing plan editor, invoice quotations, manual payment approval/rejection, receipts and renewal processing reused. |
| Payment methods | Added ordered active/hidden bank, wallet and cash destinations, shown in the existing business billing screen. No automatic transfers or charges. |
| Financial report | Added date-filtered approved collections by currency, monthly charts, invoice-state totals, growth counters and CSV export. Pharmacy sales are excluded. |
| Activity log | Existing audit log reused; added actor/business/action search and date/action filters. |
| Server health | Added database, storage, failed-job, backup, runtime and mail-readiness checks without exposing private paths or credentials. Hosting CPU/memory/certificate operations stay in Hostinger. |
| Contact/email settings | Existing office SMTP and billing payment instructions/support email reused. |
| Account settings | Existing profile/password flow reused. Password-only office access remains as requested; no MFA requirement was restored. |
| Support/customer reports | Existing ticket assignment, replies, workflow and consent-based diagnostics reused. |
| Shop distribution map | Deferred: Pharmacy POS businesses do not have verified map coordinates or a geocoding service. Branch addresses are available in business details. |
| Marketplace, ecommerce and product/shop reviews | Deferred: these depend on storefront and marketplace systems that this pharmacy platform does not currently have. |
| AI usage, wallet/top-ups, skills and agents | Deferred: these depend on separate AI services and billing contracts. No fabricated AI balances or integrations were introduced. |
| Financial book, bank reconciliation and founders | Deferred: separate accounting requirements; approved subscription collections are available now. |
| Tutorial publishing, release management and custom branding | Deferred: require content/release workflows beyond the current office administration scope. Existing app help and deployment procedures remain. |
| Telegram support | Deferred: requires channel credentials and an explicit messaging integration. No messages were sent. |
| Detailed user analytics and error-report ingestion | Deferred: requires an event/consent/retention design and client ingestion; the added reports expose actual account/business growth and the health page actual failed-job counts. |

## Access and data handling

Office owners can use all new areas. Office admins can manage businesses and
business accounts and inspect audit/health, but cannot manage office admins or
read financial reports. Billing staff can use calendars, reports and payment
destinations alongside existing plans/payments. Support staff retain overview
and support access. Every route enforces its role on the server. Businesses
cannot enter office routes.

Email, role, status and password changes revoke affected browser sessions and
API tokens. Passwords are never returned in page props or audit records. New
passwords use confirmation and the existing bcrypt byte limit. Office accounts
cannot be edited through tenant endpoints or assigned tenant memberships.
Business accounts cannot be promoted or moved by extra request fields.

Trial extensions require a reason, serialize with subscription settlement,
keep paid periods/invoices unchanged and cap the new trial end at one year from
today. Invoice and payment history is displayed through the existing billing
models. CSV exports include only approved collections in the selected range
and neutralize spreadsheet formula prefixes.

The only new table is `platform_payment_methods`; existing business/account and
billing tables retain their structure. Payment destinations are instructions
for manual payment, not bank secrets or automated payment credentials. Hidden
destinations are excluded from tenant web/API billing props. The existing three
payment types and manual approval rules remain unchanged.

## Verification

The complete backend suite passes 235 tests and 1,526 assertions. The 17 new
feature tests cover role boundaries, two-business data scoping, account/password
changes, office ownership safeguards, trial restrictions, payment destination
visibility, separate currencies, CSV output, calendar dates, audit filters and
health privacy. Production builds and JavaScript checks are also run. Browser
and live deployment evidence is recorded in HOSTINGER_DEPLOYMENT.md after
verification.
