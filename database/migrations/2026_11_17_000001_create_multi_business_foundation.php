<?php

use App\Tenancy\BusinessSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // This project deliberately targets a clean install. Never silently assign live records.
        foreach (array_merge(['users'],BusinessSchema::FOUNDATION_TABLES) as $table) {
            if (DB::table($table)->exists()) throw new RuntimeException('Phase 1 requires a fresh database. Do not run this migration against an existing business installation.');
        }
        Schema::create('businesses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->enum('status', ['Active','Suspended'])->default('Active');
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false);
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['active_branch_id']);
            $table->dropColumn(['branch_id','active_branch_id']);
        });
        Schema::create('business_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['Active','Inactive'])->default('Active');
            $table->uuid('branch_id')->nullable();
            $table->uuid('active_branch_id')->nullable();
            $table->timestamps();
            $table->unique(['business_id','user_id']);
            $table->index(['user_id','status']);
        });
        foreach (BusinessSchema::FOUNDATION_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) { $table->foreignUuid('business_id')->constrained('businesses')->restrictOnDelete(); });
        }
        foreach (['role_user','branch_user','product_tax'] as $name) {
            Schema::table($name, function (Blueprint $table) { $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnDelete(); });
        }
        foreach (array_diff(BusinessSchema::FOUNDATION_TABLES,['pricing_rules','pricing_states']) as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->unique(['business_id','id'], $name.'_business_record_unique'));
        }
        foreach (BusinessSchema::FOUNDATION_TABLES as $name) {
            foreach (BusinessSchema::REFERENCES as $column=>$target) {
                if (Schema::hasColumn($name,$column)) Schema::table($name, fn (Blueprint $table) => $table->foreign(['business_id',$column], substr($name.'_'.$column.'_business_fk',0,64))->references(['business_id','id'])->on($target)->restrictOnDelete());
            }
        }
        foreach (BusinessSchema::FOUNDATION_TABLES as $name) foreach (['user_id','sale_staff_id','actor_id','closed_by_user_id','voided_by_user_id'] as $column) {
            if (Schema::hasColumn($name,$column)) Schema::table($name, fn (Blueprint $table) => $table->foreign(['business_id',$column],substr($name.'_'.$column.'_member_fk',0,64))->references(['business_id','user_id'])->on('business_memberships')->restrictOnDelete());
        }
        foreach (['role_user'=>['role_id'=>'roles'],'branch_user'=>['branch_id'=>'branches'],'product_tax'=>['product_id'=>'products','tax_id'=>'taxes']] as $name=>$references) foreach ($references as $column=>$target) Schema::table($name, fn (Blueprint $table) => $table->foreign(['business_id',$column],$name.'_'.$column.'_business_fk')->references(['business_id','id'])->on($target)->cascadeOnDelete());
        foreach (['role_user','branch_user'] as $name) Schema::table($name, fn (Blueprint $table) => $table->foreign(['business_id','user_id'],$name.'_business_member_fk')->references(['business_id','user_id'])->on('business_memberships')->cascadeOnDelete());
        foreach (['branch_id','active_branch_id'] as $column) Schema::table('business_memberships', fn (Blueprint $table) => $table->foreign(['business_id',$column])->references(['business_id','id'])->on('branches')->restrictOnDelete());
        foreach (['roles'=>'name','products'=>'barcode','sales'=>'invoice_number','expense_categories'=>'name','settings'=>'key'] as $name=>$column) {
            Schema::table($name, function (Blueprint $table) use ($name,$column) { $table->dropUnique($name.'_'.$column.'_unique'); $table->unique(['business_id',$column], $name.'_business_'.$column.'_unique'); });
        }
        foreach (['sales','purchases'] as $name) Schema::table($name, function (Blueprint $table) use ($name) { $table->dropUnique($name.'_client_reference_unique'); $table->unique(['business_id','client_reference'], $name.'_business_client_ref_unique'); });
        foreach (['pricing_states'=>'id','pricing_rules'=>'code'] as $name=>$column) Schema::table($name, function (Blueprint $table) use ($column) { $table->dropPrimary(); $table->primary(['business_id',$column]); });
        Schema::table('personal_access_tokens', fn (Blueprint $table) => $table->foreignUuid('business_id')->nullable()->constrained('businesses')->cascadeOnDelete());
    }

    public function down(): void
    {
        throw new RuntimeException('Multi-business foundation is a fresh-install boundary. Restore a pre-migration backup or use migrate:fresh on a disposable database.');
    }
};
