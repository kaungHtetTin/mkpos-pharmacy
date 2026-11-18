<?php

namespace App\Operations;

use App\Models\User;
final class OfficeAccess
{
    public static function allows(User $user, string $area): bool
    {
        if (!$user->is_platform_admin || $user->getRawOriginal('status') !== 'Active') {
            return false;
        }
        return in_array($area, match ($user->platform_role) {
            'owner' => ['dashboard', 'businesses', 'billing', 'plans', 'support', 'audit', 'email'],
            'admin' => ['dashboard', 'businesses', 'support', 'audit'],
            'billing' => ['dashboard', 'billing', 'plans'],
            'support' => ['dashboard', 'support'],
            default => [],
        }, true);
    }
}
