# Founder finance and bank books

The platform office adds a Finance group with Financial book, Bank books, and
Founders. This follows the relevant workflows reviewed in office.pwarmyar.com
and uses Pharmacy POS's approved subscription payments as its income source.

## Setup

1. Open Bank books and create a separate book for each bank, wallet or cash
   balance. Choose its three-letter currency and, optionally, make it the
   receiving book for future subscription approvals in that currency.
2. Open Founders, choose Edit founder shares, and enter the real founder names
   and percentages. Active shares must total exactly 100%. No founders, bank
   accounts, percentages or opening balances are seeded by deployment.
3. Record actual opening adjustments, platform expenses, other income and
   founder contributions in Financial book. Subscription receipts are included
   automatically; do not enter them again as Other income.
4. Assign any historical unassigned subscription receipts to their verified
   receiving books. Changing the default never moves an earlier receipt.
5. Allocate available profit. Leave "Record all founder shares as paid now" off
   to recognize amounts owed without cash movement, or enable it to record all
   founder payouts from one sufficiently funded bank book. Separate founder
   payouts can be recorded later against unpaid allocations.

Every action records bookkeeping. The app does not initiate external bank
transfers, withdraw funds, charge customers or send founder payments.

## Accounting rules

All money is stored as integer minor units under the existing two-decimal
currency convention. Currencies are never combined or exchanged automatically.
A book's currency is fixed after creation.

- Recorded profit = approved subscription receipts + other income − expenses.
- Founder contributions and capital returns change cash/capital, not profit.
- Opening adjustments change cash, not profit.
- Internal transfers move funds between two active books of the same currency;
  combined cash and profit stay unchanged.
- Profit allocation changes allocated/unallocated profit and amounts owed.
  Allocation alone does not change cash.
- Founder payouts reduce cash and unpaid allocated profit, not expenses/profit.
- Capital returns cannot exceed that founder's recorded net capital.
- Payouts cannot exceed unpaid founder allocations. Outflows and decreasing
  adjustments cannot overdraw the selected recorded book.

All-time balances are shown independently of table search/date filters.
Founder statements show each founder's allocation share rather than the full
allocation amount. Statement CSV exports follow the same rule. Approved receipts
that have no book count as income and recorded cash, but cannot fund book
outflows until assigned.

Allocations preserve founder names, percentages and exact share amounts at
posting. Later changes affect only future allocations. Integer largest-remainder
rounding preserves every cent; zero-percent founders receive zero. Founder-share
revisions reject stale configuration screens. Allocation/payout posting dates
must be the platform's current date. Other entries may be dated on or before
today. The frontend receives the accounting date from the server.

## Corrections and history

Posted entries are immutable. Voiding retains the entry, actor, timestamp and
reason, and reverses its effects on balances. Dependent spending, payouts or
capital returns must be reversed before a correction that would leave a book,
founder obligation or contributed capital below zero. Voiding a combined
allocation reverses its remaining automatic child payouts atomically. Already
voided children stay voided. Repeating a void is idempotent.

Corrections can reveal a profit deficit after earlier allocations; further
allocation is then blocked. Subscription receipts retain their original source
payment and cannot be voided through this ledger. Record an actual refund or
other outflow as a separately described expense; this does not rewrite the
original subscription approval.

Archive founders and bank books instead of deleting their history. Archived
founders retain statements and may receive outstanding allocations/capital.
Select another receiving book before archiving the current default.

## Access and integrity

Owners and billing staff can read the three finance sections, record ordinary
income/expenses/contributions/transfers, manage bank books, assign unassigned
receipts and export CSV. Founder-share changes, profit allocations, payouts,
capital returns, opening adjustments and voids require an office owner.
Other office roles and business accounts cannot enter these sections. Finance
audit details are excluded from non-finance audit views; business activity feeds
omit raw audit properties.

Posting requests use UUID idempotency keys and payload hashes. Founder settings
and currency rows serialize mutations; entries and allocation snapshots commit
atomically. Subscription approval and its financial receipt share a transaction;
if receipt posting fails, approval and its subscription period roll back.

One receipt per approved subscription payment prevents double-counting. A
migration imports existing approved payments into unassigned receipts without
inventing bank balances. `platform:finance-sync` repairs missing approved receipts
idempotently and leaves recovered historical receipts unassigned. It runs every
five minutes under the existing pharmacy scheduler and is also run at release
preparation/cutover. No new hosting cron or service is required beyond repointing
the existing application scheduler to the active release.

Seven additive tables retain founders, share revisions, currency/default-book
locks, bank books, immutable entries, receipt mappings and share snapshots. No
existing business, password, subscription or sales table is restructured.
Encrypted platform backups include these tables. Restore the same APP_KEY and
backup key as the rest of the platform.

## Validation

The complete suite passes **263 tests and 1,654 assertions**. The 28 finance tests
cover approval atomicity, historic receipt backfill, reconciliation, default
book changes, exact cents, retries, overdrafts, currency boundaries, snapshots,
rounding, allocations, capital returns, archived founders, dependent reversals,
role/privacy boundaries, filtered statements/CSV and separate posting/export
rate limits. Both production builds and JavaScript checks pass. Ten synthetic
Chromium groups exercised the full workflow and compact mobile dialogs. No real
money was transferred or messages sent during verification.
