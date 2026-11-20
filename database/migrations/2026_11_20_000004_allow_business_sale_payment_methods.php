<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales', 'customer_payments', 'return_settlements'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->string('payment_method', 60)->change());
        }
    }

    public function down(): void
    {
        // Keep the widened columns: narrowing them could corrupt custom payment history.
    }
};
