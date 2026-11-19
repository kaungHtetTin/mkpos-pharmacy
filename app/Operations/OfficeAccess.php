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
            'owner' => ['dashboard', 'businesses', 'billing', 'plans', 'support', 'audit', 'email', 'users', 'admins', 'calendar', 'reports', 'payment-methods', 'health', 'finance', 'bank-books', 'founders'],
            'admin' => ['dashboard', 'businesses', 'support', 'audit', 'users', 'health'],
            'billing' => ['dashboard', 'businesses', 'billing', 'plans', 'calendar', 'reports', 'payment-methods', 'finance', 'bank-books', 'founders'],
            'support' => ['dashboard', 'support'],
            default => [],
        }, true);
    }
}
