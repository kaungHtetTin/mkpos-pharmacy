# Inventory stock movement history

Stock Balance → **Movement history** opens the business-wide ledger. Opening an
inventory product also shows its own ledger above its batches. Both views support
branch, action, product/reference/batch search, Myanmar calendar dates, and paging.
Quantities use the product's base unit; before/after balances are per branch.

## Recorded actions

| Action | Stock effect |
| --- | --- |
| Purchase received, including paid and free quantities | Increase |
| Purchase edited | Old quantities reversed, then new quantities received |
| Purchase deleted | Original stock reversed; history retains the invoice reference |
| Admin POS, cashier sale, offline sale sync | Decrease, including free quantities |
| Sale voided | Restore quantities |
| Approved customer return with restocking | Increase |
| Approved supplier return | Decrease |
| Completed branch transfer | Outgoing entry and incoming entry, same source reference |
| Stock adjustment | Signed increase/decrease and adjustment reason |
| Mobile purchase and offline purchase sync | Increase |

Pending/rejected returns and customer returns without restocking do not change
stock and do not create stock movements. Pricing changes and payment settlements
are not stock movements. Offline retries preserve the existing transaction
idempotency and do not repeat ledger entries.

## Consistency and access

`App\Inventory\StockLedger` locks the aggregate inventory row and writes its new
balance and movement in the existing transaction. A later failure rolls both back.
History snapshots retain product, branch, user, source reference and optional batch
and note even after a source is removed. There is no update/delete history API;
model writes also reject editing or deleting existing movements. Read routes use
`manage_inventory` and the current business scope. The ledger is included in the
business export and existing encrypted database backups.

The existing sales clamp at zero is preserved. If a pre-existing aggregate/batch
discrepancy causes the actual aggregate change to differ from the requested change,
the actual change and requested quantity are recorded with an explanatory note.
This feature does not recalculate or repair existing stock discrepancies.

## Installation and historical boundary

Apply migrations, then run `php artisan inventory:initialize-movements` while
operational writes are paused. The idempotent command records existing nonzero
aggregate quantities as opening snapshots without changing inventory. It also
includes suspended businesses. Zero-stock rows need no opening entry. Products
with an existing movement are skipped to avoid inventing a late opening balance.

Earlier edits and reversals did not have reliable stock snapshots, so past actions
are not reconstructed. Opening entries have an unknown before balance (`—`) and
explicitly explain this boundary. All supported stock-changing workflows record
their movements from deployment onward. Direct SQL or ad hoc model modifications
outside these workflows must use the ledger explicitly.

The Phase 1 migration uses the frozen `FOUNDATION_TABLES` list; runtime tenancy and
exports use `TABLES`, which includes the new ledger. This prevents a fresh install
from trying to retrofit business columns onto tables created by later migrations.
