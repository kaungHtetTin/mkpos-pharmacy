<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
return new class extends Migration {
    public function up(): void
    {
        Schema::table('pricing_rules', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
        });
        Schema::table('product_units', fn (Blueprint $table) => $table->json('custom_prices')->nullable());
        Schema::table('customers', fn (Blueprint $table) => $table->string('preferred_price_type', 30)->nullable());
        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('price_type', 30)->default('retail')->change();
            $table->string('price_type_name', 60)->nullable();
        });
        DB::table('pricing_rules')->where('code', 'selling_price')->update(['name'=>'Retail', 'sort_order'=>0]);
        DB::table('pricing_rules')->where('code', 'wholesale_price')->update(['name'=>'Wholesale', 'sort_order'=>1]);
        DB::table('sale_items')->where('price_type', 'retail')->update(['price_type_name'=>'Retail']);
        DB::table('sale_items')->where('price_type', 'wholesale')->update(['price_type_name'=>'Wholesale']);
    }
    public function down(): void
    {
        throw new RuntimeException('Price types preserve historical sales. Restore a backup instead of removing them.');
    }
};
