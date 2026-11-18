<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('platform_role', 20)->default('owner');
            $t->text('office_mfa_secret')->nullable();
            $t->json('office_recovery_codes')->nullable();
            $t->unsignedBigInteger('office_mfa_counter')->default(0);
        });
        Schema::create('support_tickets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('business_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('opened_by')->constrained('users')->restrictOnDelete();
            $t->string('subject', 150);
            $t->string('status', 20)->default('open');
            $t->foreignUuid('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignUuid('granted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('access_until')->nullable();
            $t->timestamp('diagnostics_requested_at')->nullable();
            $t->timestamps();
            $t->index(['business_id', 'created_at']);
            $t->index(['status', 'updated_at']);
        });
        Schema::create('support_messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('ticket_id')->constrained('support_tickets')->restrictOnDelete();
            $t->foreignUuid('author_id')->constrained('users')->restrictOnDelete();
            $t->text('body');
            $t->timestamp('created_at');
            $t->index(['ticket_id', 'created_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::table('users', fn(Blueprint $t) => $t->dropColumn(['platform_role', 'office_mfa_secret', 'office_recovery_codes', 'office_mfa_counter']));
    }
};
