# Phase 3: platform operations and production hardening

This phase follows the [multi-business platform plan](https://chatgpt.com/space/page_608969a4fdbc8191888c49ed2096df0e), not the historical inventory-module phase numbering. One business account still belongs to exactly one business. Phase 2 manual payments remain unchanged.

## Platform overview and office roles

`/platform/dashboard` shows subscription states, resource usage, open support, pending payment reviews, overdue invoices and backup freshness. Business tables are searchable and paginated at 20. Subscription summaries are loaded in batches. Approved subscription collections and monthly charts are grouped by currency; pharmacy sales are never included.

Trusted operators create separate office identities using:

```sh
php artisan platform:admin office@example.com --role=owner
```

Supported roles:

| Role | Access |
| --- | --- |
| owner | Overview, business administration, plans, billing, support, audit |
| admin | Overview, business administration, support, audit |
| billing | Overview, plans, billing; no support or business administration |
| support | Service overview and support; no collection totals, billing or business administration |

All permissions are enforced on the server. Every role can manage its own MFA. The CLI is the staff provisioning/recovery boundary; there is no business-access impersonation endpoint. Business accounts cannot be promoted to office accounts.

Production office routes require TOTP enrollment and verification. Sign in, enter the current password on **Office security**, add the displayed setup key to an authenticator, and verify a code. Eight one-use recovery codes are displayed once; store them privately. Codes follow standard 30-second TOTP with a one-window clock tolerance, reject replay and are rate limited. Enrollment secrets are encrypted with the application key; recovery codes are hashed. Neither is serialized in shared account props or written into audit payloads. A verified session expires after eight hours or logout. The same MFA boundary protects office profile and password changes.

Lost MFA requires trusted server access, not a support-ticket request:

```sh
php artisan platform:admin office@example.com --role=owner --reset-mfa --force
```

This clears enrollment and invalidates old MFA verification fingerprints. The next login must enroll again. Keep the intended role explicit during recovery.

## Support workflow

Owners use `/support` even after subscription expiry. Office support users use `/platform/support`. Tickets have a subject, private conversation, assignee and open/closed state. Replies and transitions are audited without copying message bodies into the central audit trail.

1. Owner opens a ticket.
2. An authorized office user claims it and requests diagnostics.
3. Owner explicitly approves a 30-minute session.
4. Only that assigned office user may read the diagnostic endpoint while the grant remains valid.
5. Owner revocation, closure, reassignment or expiration immediately blocks new diagnostic reads. Reopening does not restore a grant.

The approved diagnostic scope is deliberately limited to record counts for branches, medicines, batches, sales, purchases and cash sessions. No customer names, individual transactions, stock editing, owner impersonation or business-export access is granted. Downloaded or already viewed information cannot be recalled. Approval and every diagnostic read are audited. Owner routes verify business ownership before loading a ticket; staff cannot access owner support or export tools.

## Business exports

Owners use **Account menu → Business data export**. Downloads require explicit acknowledgment, are limited to two per hour, and remain accessible after subscription expiry. The ZIP contains JSON Lines files and a manifest with business identity, row counts and SHA-256 checksums. Queries run in a database transaction and each file is explicitly scoped to the assigned business, including users, pivots, support conversations and billing records.

Exports exclude passwords, MFA/recovery material, remember tokens, API/session credentials, sensitive setting keys and private attachment binaries. They include private customer/business information; users must store them securely. They are data-portability exports, not automatic disaster-recovery imports. Opening stock and financial history must not be reimported as new transactions without a reviewed mapping.

Files are built privately using streamed records. Default caps are 250,000 records and 256 MiB of JSON data; cap failures clean up partial output. Successful downloads remove their temporary ZIP after transmission; daily housekeeping removes abandoned ZIPs older than 24 hours. Owners needing larger/attachment-inclusive exports use an assisted support process with an explicitly agreed scope.

## Backups and recovery

See [OPERATIONS_RUNBOOK.md](OPERATIONS_RUNBOOK.md) for private configuration, scheduled backup verification, retention, restore rehearsal and incident steps. Daily archives use AES-256 ZIP encryption, include the whole database and business/billing/public media, and verify every entry against its manifest before reporting success. This is a platform-operator backup containing all businesses, never an owner download.

Backups are retained for 14 days only after a successful new backup. A failing run retains previous archives and reports failure in the private log/cron output. The overview warns if no verified backup exists or it is older than 26 hours. Application keys and backup encryption keys must be escrowed separately. Offsite copies need an operator-managed destination; this implementation does not claim that a same-server archive survives a hosting-account outage.

## HTTP and deployment controls

Authenticated and office responses are private/no-store. Responses carry frame, content-type, referrer, permissions and limited CSP restrictions; HTTPS responses use HSTS. API credential login has account/IP and aggregate IP throttles. Camera permission remains available to the same-origin cashier scanner. Office role gates and MFA protect privileged endpoints separately from tenant permissions.

Production uses HTTPS, secure/HttpOnly session cookies, debug disabled, private application code and private storage. There is no legacy unscoped business access or automatic deletion of business records. Support, billing and financial history are retained until an explicitly authorized retention/deletion procedure is agreed; canceling a subscription does not erase them. Backup pruning is separate from business record retention.

## Verification

Use the disposable database, never a live database, for tests:

```sh
DB_DATABASE=mkpos_phase1_testing APP_ENV=testing php artisan test
node --test tests/js/automaticPricing.test.mjs tests/browser/business-storage.test.mjs
npm run build
```

The regression suite passes 181 tests and 1,126 assertions, including 20 Phase 3 tests. Cases cover office role boundaries, pagination, currency isolation, tenant exports and cleanup, owner consent, diagnostic expiry/revocation/reassignment, MFA enrollment/replay/recovery/session expiry, security headers, encrypted backups and tamper detection. A separate synthetic database backup was decrypted and restored into a newly created disposable database, and source/restore counts matched before the rehearsal database was removed. Browser verification and deployment results are recorded in the deployment notes after rollout.

Eight synthetic Chromium flows passed for MFA setup/recovery, owner/office support,
consent and revocation, cross-business rejection, conversation closure, native ZIP
downloads and mobile dialog bounds. A ninth flow also passed for a synthetic cashier shift, checkout, receipt and offline reload. Both frontend builds and JavaScript checks pass.

Dependency hardening removed unused Axios, Lodash and Laravel Mix and upgraded the
active build to Vite 8.3.8, React plugin 6.3.0 and Laravel Vite plugin 3.2.1. Node
20.19+ or 22.12+ is required by the build toolchain (verification used Node 24).
Production Composer and npm dependency audits report zero advisories. The full npm
audit still reports 14 build-only dependency findings rooted in the picomatch ReDoS
advisory GHSA-fc5q-39xc-vjpf (affected versions through 4.0.7). Build globs come from
trusted repository configuration; these packages are not uploaded as Node services.
Do not expose the development server publicly or feed it untrusted glob patterns.
Recheck the advisory when updating the toolchain; no suppression or forced downgrade
was used to hide it.
