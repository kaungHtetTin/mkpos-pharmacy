<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->foreignUuid('payment_method_id')->nullable()->constrained('platform_payment_methods')->restrictOnDelete();
            $table->json('payment_method_snapshot')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropColumn('payment_method_snapshot');
        });
    }
};
