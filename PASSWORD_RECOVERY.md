# Password recovery and Hostinger SMTP

Users open **Forgot password?** at `/login`, enter their account email, and follow
an emailed link to choose a new password. No business selector is required.
Business users and platform office identities use the same identity-based flow.
The existing compact login design now extends to the request/reset pages, with
clear expired/invalid-link actions and links back to sign-in.

## Recovery behavior

- Reset links expire after the password broker's configured 60 minutes and are
  single use. Only their hashes are stored in `password_resets`.
- Requests normalize the email address. Known, unknown and broker-throttled
  addresses receive the same generic response when delivery is configured.
- Named limits bound requests by IP and email, and reset submissions by IP.
  They do not consume signup, support or billing rate-limit buckets.
- Reset requires matching confirmation and a password of at least 12 characters,
  no longer than bcrypt's 72-byte boundary. Invalid/expired/used links cannot
  change a password or consume another identity's token.
- The reset transaction locks the identity before checking/consuming the token,
  rotates the remember token, increments its session version and revokes all
  Sanctum tokens. Browser sessions recheck that version against the database.
  Concurrent API logins lock the same identity before issuing a new token.
- Password reset does not disable office MFA, reactivate inactive memberships,
  lift administrative suspension, extend a trial or alter subscription terms.
- Canonical reset links use configured APP_URL rather than the request Host.
  Recovery pages are private/no-store; token pages use no-referrer. Raw reset
  tokens and SMTP exceptions/credentials are excluded from application audit
  payloads and error flash data. Hosting access logs may still record URL paths
  and require appropriate operator access/retention controls.

The session-version column also means deployment signs existing web sessions out
once. Owner registration/login now initialize the version. Cashier tokens remain
valid through deployment and are revoked only when their account is reset.
Pending cashier transactions remain in their existing business/user storage.

## Configure Hostinger email

Only an MFA-verified **platform owner** can open `/platform/email` (Office → Email).
Business owners, billing officers, support officers and platform administrators
cannot edit the global sender.

1. Enter an existing Hostinger mailbox address and its mailbox password.
2. Keep the default **465 — TLS**, or select **587 — STARTTLS required**.
   The SMTP host is fixed to `smtp.hostinger.com`, TLS is required, and the sender
   address is the authenticated mailbox to avoid forged From addresses.
3. Set the display name, enable delivery, and save. Passwords are encrypted with
   APP_KEY, never returned in page/API props, and cleared from the form after save.
   Leaving it blank keeps the stored secret; changing the mailbox requires a new
   secret. Keep APP_KEY in secure recovery escrow.
4. Send a delivery test to an inbox you control. SMTP acceptance is reported
   separately from inbox delivery; check the inbox and spam folder. Tests are
   limited to five per hour per office user. Delivery failures are recorded with
   sanitized exception class information, never SMTP passwords or reset URLs.

Until a sender is enabled, the public form explains that email is not configured
and does not pretend a link was emailed. The production `log`/`array` mailer is
never used as a password-recovery fallback. Tests and explicitly configured
local previews can use the in-memory array mailer; no real email is sent there.

## Hosting decision

The connected account has an existing Hostinger email service for
superkingmm.com. Its five mailbox seats are in use. A request for a dedicated
Pharmacy POS mailbox was rejected with `ERR_ACC_LIMIT_REACHED`; no mailbox was
created and no purchase was made. The user chose to configure SMTP personally.
Existing mailbox passwords and email/DNS records were not changed. Outbound
recovery delivery remains pending that configuration and inbox verification.

## Validation and operations

The full suite passes **210 tests and 1,338 assertions**. Recovery tests cover
canonical URLs, hashed/expiring/one-use tokens, uniform responses, confirmation,
old/new login credentials, browser/API revocation, MFA preservation, inactive
access, mail failure rollback, mail secrecy and throttling. Sender tests cover
owner-only/MFA gates, encryption, hidden credentials, updates, secure ports and
test-email acceptance tracking.

Seven Chromium flows passed against a synthetic database and private in-memory
mail capture: sender configuration, owner registration and API access, delivery
capture, confirmation/reset, stale session/token rejection, new login and token
reuse rejection, plus compact mobile/expired-link screens. Both production
builds and the existing JavaScript checks pass. No external emails were sent
for these tests and no token retrieval endpoint was deployed.

The migration adds `users.auth_epoch` and one central encrypted sender settings
table. All business records and existing passwords are retained. Full platform
backups include the encrypted SMTP record; APP_KEY is needed to restore it.

For rollback to an older release without session-version enforcement, invalidate
its session files/database sessions before exposing it, and revoke API tokens as
appropriate. Preserve reset password hashes and all business data; do not restore
an old database over operational records just to roll back the UI.
