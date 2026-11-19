<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_payment_methods', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 100);
            $t->string('type', 30);
            $t->string('account_name', 150)->nullable();
            $t->string('account_number', 150)->nullable();
            $t->text('instructions')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('platform_payment_methods');
    }
};
