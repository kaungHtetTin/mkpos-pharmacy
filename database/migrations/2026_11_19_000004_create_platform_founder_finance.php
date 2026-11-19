<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema, DB};
use Illuminate\Support\Str;
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_founder_settings', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->unsignedInteger('version')->default(0);
        });
        DB::table('platform_founder_settings')->insert(['id' => 1, 'version' => 0]);
        Schema::create('platform_founders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 150);
            $t->unsignedInteger('share_bps')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('platform_bank_books', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 120);
            $t->char('currency', 3);
            $t->string('account_reference', 150)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index('currency');
        });
        Schema::create('platform_finance_currencies', function (Blueprint $t) {
            $t->char('currency', 3)->primary();
            $t->foreignUuid('default_book_id')->nullable()->constrained('platform_bank_books')->restrictOnDelete();
        });
        Schema::create('platform_finance_receipts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('payment_id')->unique()->constrained('subscription_payments')->restrictOnDelete();
            $t->foreignUuid('book_id')->nullable()->constrained('platform_bank_books')->restrictOnDelete();
            $t->char('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->timestamp('received_at');
            $t->foreignUuid('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('assigned_at')->nullable();
            $t->timestamps();
            $t->index(['currency', 'received_at']);
        });
        Schema::create('platform_finance_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('request_key')->unique();
            $t->char('request_hash', 64);
            $t->string('type', 30);
            $t->char('currency', 3);
            $t->bigInteger('amount_minor');
            $t->date('entry_date');
            $t->foreignUuid('book_id')->nullable()->constrained('platform_bank_books')->restrictOnDelete();
            $t->foreignUuid('to_book_id')->nullable()->constrained('platform_bank_books')->restrictOnDelete();
            $t->foreignUuid('founder_id')->nullable()->constrained('platform_founders')->restrictOnDelete();
            $t->foreignUuid('parent_entry_id')->nullable()->constrained('platform_finance_entries')->restrictOnDelete();
            $t->string('description', 500);
            $t->string('category', 100)->nullable();
            $t->string('reference', 150)->nullable();
            $t->string('counterparty', 150)->nullable();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->foreignUuid('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('void_reason', 500)->nullable();
            $t->timestamps();
            $t->index(['currency', 'entry_date']);
        });
        Schema::create('platform_profit_shares', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('entry_id')->constrained('platform_finance_entries')->restrictOnDelete();
            $t->foreignUuid('founder_id')->constrained('platform_founders')->restrictOnDelete();
            $t->string('founder_name', 150);
            $t->unsignedInteger('share_bps');
            $t->unsignedBigInteger('amount_minor');
            $t->unique(['entry_id', 'founder_id']);
            $t->timestamps();
        });
        // Existing approved receipts are imported exactly once and left unassigned.
        DB::table('subscription_payments')->where('status', 'approved')->orderBy('id')->chunk(200, function ($rows) {
            foreach ($rows as $p) {
                DB::table('platform_finance_currencies')->insertOrIgnore(['currency' => $p->currency]);
                DB::table('platform_finance_receipts')->insert(['id' => (string) Str::uuid(), 'payment_id' => $p->id, 'currency' => $p->currency, 'amount_minor' => $p->amount_minor, 'received_at' => $p->reviewed_at ?? $p->created_at, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }
    public function down(): void
    {
        foreach (['platform_profit_shares', 'platform_finance_entries', 'platform_finance_receipts', 'platform_finance_currencies', 'platform_bank_books', 'platform_founders', 'platform_founder_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
