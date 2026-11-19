<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            // Snapshot identifiers deliberately survive deletion of source documents/products.
            $table->uuid('product_id');
            $table->uuid('branch_id');
            $table->string('product_name');
            $table->string('branch_name');
            $table->string('base_unit')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('actor_name');
            $table->string('action', 40);
            $table->string('source_type', 40)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('reference', 160)->nullable();
            $table->bigInteger('before_quantity')->nullable();
            $table->bigInteger('quantity');
            $table->bigInteger('requested_quantity')->nullable();
            $table->bigInteger('after_quantity');
            $table->string('batch_number')->nullable();
            $table->text('note')->nullable();
            $table->date('document_date')->nullable();
            $table->timestamp('occurred_at', 6);
            $table->string('event_key', 100)->nullable();
            $table->unique(['business_id', 'event_key']);
            $table->index(['business_id', 'product_id', 'branch_id', 'occurred_at'], 'stock_movement_product_history');
            $table->index(['business_id', 'occurred_at'], 'stock_movement_business_history');
            $table->index(['business_id', 'action', 'occurred_at'], 'stock_movement_action_history');
        });
    }
    public function down(): void { Schema::dropIfExists('stock_movements'); }
};
