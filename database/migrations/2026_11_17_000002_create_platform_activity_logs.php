<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_activity_logs',function(Blueprint $table){
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->string('action');$table->json('properties')->nullable();$table->timestamp('created_at');
            $table->index(['business_id','created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('platform_activity_logs'); }
};
