<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema, DB};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 60)->unique();
            $t->string('name', 120);
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency', 3);
            $t->unsignedTinyInteger('interval_months')->default(1);
            $t->unsignedTinyInteger('grace_days')->default(3);
            foreach (['max_branches','max_users','max_products'] as $field) $t->unsignedInteger($field)->nullable();
            $t->json('features');
            $t->boolean('is_active')->default(true);
            $t->boolean('is_public')->default(true);
            $t->boolean('is_trial')->default(false);
            $t->timestamps();
        });
        Schema::create('business_subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('business_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignUuid('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $t->json('plan_snapshot');
            $t->timestamp('trial_started_at')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->boolean('auto_renew')->default(true);
            $t->timestamp('canceled_at')->nullable();
            $t->string('last_state', 30)->default('trialing');
            $t->timestamps();
            $t->unique(['business_id','id']);
        });
        Schema::create('subscription_invoices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('business_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $t->string('number', 40)->unique();
            $t->string('request_key', 100);
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency', 3);
            $t->json('plan_snapshot');
            $t->enum('status', ['open','paid','void'])->default('open');
            $t->timestamp('due_at');
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('period_starts_at')->nullable();
            $t->timestamp('period_ends_at')->nullable();
            $t->string('void_reason', 500)->nullable();
            $t->timestamps();
            $t->unique(['business_id','request_key']);
            $t->unique(['business_id','id']);
            $t->index(['business_id','status']);
        });
        Schema::create('subscription_periods', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('business_id')->constrained()->restrictOnDelete();
            $t->uuid('invoice_id')->unique();
            $t->foreign(['business_id','invoice_id'])->references(['business_id','id'])->on('subscription_invoices')->restrictOnDelete();
            $t->json('plan_snapshot');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->timestamps();
            $t->index(['business_id','starts_at','ends_at']);
        });
        Schema::create('subscription_payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('business_id')->constrained()->restrictOnDelete();
            $t->uuid('invoice_id');
            $t->foreign(['business_id','invoice_id'])->references(['business_id','id'])->on('subscription_invoices')->restrictOnDelete();
            $t->foreignUuid('submitted_by')->constrained('users')->restrictOnDelete();
            $t->foreign(['business_id','submitted_by'])->references(['business_id','user_id'])->on('business_memberships')->restrictOnDelete();
            $t->foreignUuid('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('request_key', 100);
            $t->unsignedBigInteger('amount_minor');
            $t->char('currency', 3);
            $t->string('method', 30);
            $t->string('reference', 160);
            $t->char('reference_hash', 64);
            $t->date('payment_date');
            $t->string('receipt_path')->nullable();
            $t->enum('status', ['pending','approved','rejected'])->default('pending');
            $t->string('review_note', 1000)->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['business_id','request_key']);
            $t->index(['business_id','reference_hash','status']);
            $t->index(['status','created_at']);
        });
        Schema::create('platform_billing_settings', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->text('payment_instructions');
            $t->string('support_email')->nullable();
            $t->timestamps();
        });
        DB::table('platform_billing_settings')->insert(['id'=>1,'payment_instructions'=>'Contact the platform office for payment details. Include your invoice number with the transfer.','created_at'=>now(),'updated_at'=>now()]);
    }
    public function down(): void
    {
        foreach (['platform_billing_settings','subscription_payments','subscription_periods','subscription_invoices','business_subscriptions','subscription_plans'] as $table) Schema::dropIfExists($table);
    }
};
