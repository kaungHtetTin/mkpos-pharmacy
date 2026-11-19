<?php

namespace App\Tenancy;

final class BusinessSchema
{
    public const FOUNDATION_TABLES = ['branches', 'roles', 'categories', 'units', 'taxes', 'products', 'product_units', 'inventories', 'inventory_batches', 'suppliers', 'purchases', 'purchase_items', 'customers', 'sales', 'sale_items', 'returns', 'return_items', 'expenses', 'expense_categories', 'stock_transfers', 'stock_transfer_items', 'prescriptions', 'activity_logs', 'settings', 'inventory_adjustments', 'cash_sessions', 'supplier_payments', 'customer_payments', 'return_settlements', 'pricing_rules', 'pricing_states', 'price_changes'];

    public const TABLES = [...self::FOUNDATION_TABLES, 'stock_movements'];

    public const REFERENCES = ['branch_id'=>'branches', 'active_branch_id'=>'branches', 'from_branch_id'=>'branches', 'to_branch_id'=>'branches', 'product_id'=>'products', 'product_unit_id'=>'product_units', 'foc_product_unit_id'=>'product_units', 'unit_id'=>'units', 'foc_unit_id'=>'units', 'category_id'=>'categories', 'tax_id'=>'taxes', 'supplier_id'=>'suppliers', 'customer_id'=>'customers', 'sale_id'=>'sales', 'sale_item_id'=>'sale_items', 'purchase_id'=>'purchases', 'purchase_item_id'=>'purchase_items', 'return_id'=>'returns', 'return_item_id'=>'return_items', 'cash_session_id'=>'cash_sessions', 'expense_category_id'=>'expense_categories', 'stock_transfer_id'=>'stock_transfers', 'inventory_batch_id'=>'inventory_batches', 'batch_id'=>'inventory_batches', 'transfer_id'=>'stock_transfers', 'source_purchase_item_id'=>'purchase_items', 'source_sale_item_id'=>'sale_items'];
}
