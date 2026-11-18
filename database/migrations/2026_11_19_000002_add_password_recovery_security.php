<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn(Blueprint $t) => $t->unsignedBigInteger('auth_epoch')->default(0));
        Schema::create('platform_mail_settings', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->boolean('enabled')->default(false);
            $t->string('username')->nullable();
            $t->text('password')->nullable();
            $t->unsignedSmallInteger('port')->default(465);
            $t->string('from_name', 150)->default('Pharmacy POS');
            $t->timestamp('last_tested_at')->nullable();
            $t->string('last_test_status', 20)->nullable();
            $t->timestamp('last_failure_at')->nullable();
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamps();
        });
        DB::table('platform_mail_settings')->insert(['id' => 1, 'enabled' => false, 'port' => 465, 'from_name' => 'Pharmacy POS', 'created_at' => now(), 'updated_at' => now()]);
    }
    public function down(): void
    {
        Schema::dropIfExists('platform_mail_settings');
        Schema::table('users', fn(Blueprint $t) => $t->dropColumn('auth_epoch'));
    }
};
