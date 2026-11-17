<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::table('business_memberships')->select('user_id')->groupBy('user_id')->havingRaw('COUNT(*) > 1')->exists()
            || DB::table('business_memberships')->join('users', 'users.id', '=', 'business_memberships.user_id')->where('users.is_platform_admin', true)->exists()) {
            throw new RuntimeException('Accounts must belong to one business, and platform accounts must remain separate. Resolve test accounts before applying this migration; no assignments have been changed.');
        }
        Schema::table('business_memberships', fn (Blueprint $table) => $table->unique('user_id', 'business_memberships_one_business_per_user'));
    }

    public function down(): void
    {
        Schema::table('business_memberships', fn (Blueprint $table) => $table->dropUnique('business_memberships_one_business_per_user'));
    }
};
