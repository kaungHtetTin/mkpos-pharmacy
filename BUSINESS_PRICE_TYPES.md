# Business price types

Each business owns its price types and shares them across its branches. Retail
and Wholesale are permanent defaults. Settings → Automatic Pricing lets an owner
add, rename, reorder or deactivate custom types. Deactivation keeps saved prices
and historical sales; it removes the type from current selections. Custom types
start in Manual mode and do not assign zero or copy Retail prices.

## Product pricing

Keep Retail and Wholesale in Units & Pricing. **Additional prices** opens a compact
form for each custom type and selling unit. Enter a positive price or leave it
blank for “Price not set”. Each type has its own existing-style automatic rule:
markup, minimum profit per base unit, and base-price rounding followed by unit
conversion. Automatic changes use a reviewed, versioned preview. A unit can have
a manual override. Missing buying costs are explicit and block custom automatic
prices until resolved, even if an old calculated amount remains saved.

Purchases, purchase edits/reversals, product edits, and CSV imports use the common
pricing service. Retail and Wholesale retain their existing fields and amounts.
Custom amounts, modes and statuses are stored in product_units.custom_prices;
the business-scoped pricing_rules registry supplies their labels and rules.

## Selling and customers

Admin POS and the cashier app select the same active business types. Retail is
the initial default. A customer can have an optional preferred type, applied when
selected at POS; cashiers can explicitly choose another available type. No missing
custom price silently falls back to Retail or zero. Checkout validates active
types and authoritative prices inside the existing pricing transaction.

Sales snapshot both the stable type code and readable type name alongside their
actual monetary values. Renaming/deactivating a type or changing prices does not
rewrite completed sales. Sale detail and cashier history display the saved name;
financial reports continue to use the actual recorded sale amounts.

The cashier caches available types and prices per account, business and branch.
Cached carts restore their selected type after reload, including offline. Sync
rejects inactive, foreign or missing types/prices. A custom price differing from
the issued offline receipt requires review rather than silently repricing it.
Failed submissions stay in the existing pending-sale queue. Successful retries
remain idempotent, including after the type is later deactivated.

## CSV and exports

Download the current unit-price CSV template. It contains optional custom columns
such as `price: member`. Names follow the current business's type labels; download
a new template after renaming types. Positive values become manual overrides.
Blank or omitted custom columns preserve existing prices; use the product form to
clear a price. Unknown custom columns and invalid amounts fail atomically.
The original Retail/Wholesale-only template remains accepted. General product
imports also initialize new units using active automatic custom rules. Business
exports and encrypted database backups include the additional fields.

## Deployment

Migration 2026_11_20_000003 adds rule metadata, unit custom prices, customer
preferences, and a readable sale-item price-type snapshot. It widens the previous
two-value price_type enum. Existing Retail/Wholesale sale labels are backfilled;
stock quantities, existing price amounts and financial totals are not changed.
The existing cashier service worker advances to cashier-shell-v7 so the new UI
and its assets are cached together. No new dependency is required.
