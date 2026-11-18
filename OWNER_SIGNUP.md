# Owner self-registration

Owners can create their own business at `/register` using **Create a business
account** on the login page. Platform office provisioning remains available.
This supersedes the earlier Phase 1 restriction that public registration was closed.

The compact form asks for business name, owner name, email, password confirmation
and business currency. The server creates a new owner identity, one independent
business, Main Branch, roles/permissions, defaults and the initial 14-day trial
in one transaction. It signs the owner in and opens the business dashboard.
Owners can add branches and staff inside that workspace afterwards. Selecting a
paid plan and manual payment approval remain in the existing billing workflow.
Business currency changes the workspace's display symbol; subscription invoice
currencies are still configured separately by the platform office.

Each email belongs to one account globally. An existing business or office email
cannot be reused to create or join another business. Business names need not be
unique: server-generated unique slugs distinguish independent workspaces. A
signed-in owner cannot register a second business from the signup endpoint.
Separate businesses still require separate owner accounts.

Passwords require at least 12 characters and are limited to bcrypt's 72-byte
boundary. Signup is CSRF protected and uses a dedicated rate-limit bucket: five
attempts per minute and 20 per day per IP, including failed attempts. All role,
status, plan, trial-duration, branch and business assignment fields are controlled
by the server. Clients cannot register office accounts or extend their trial.
Business/owner/trial creation rolls back together if provisioning fails. Duplicate
submissions cannot create a second business for the same email, including a
concurrent duplicate caught by the database's email uniqueness constraint.

Signup activates the trial immediately. Email verification is not a prerequisite,
and the feature does not send or claim a verification email. The current mail
transport is still a separate operational configuration. No live business data,
existing passwords or subscription records are reset by this update, and no
schema migration is required.

## Validation

The full regression suite passes **191 tests and 1,217 assertions**, including 12
registration tests for provisioning, access boundaries, duplicate emails,
normalization, validation, transaction rollback, authenticated signup rejection,
throttling and immediate cashier API access. Both production builds pass. Seven
Chromium flows pass against a synthetic database, including the compact mobile
form, subsequent login and two owners with the same business name in separate
workspaces. No synthetic businesses are created during live verification.
