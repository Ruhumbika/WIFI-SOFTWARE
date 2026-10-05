<?php
namespace App\Support;

final class AdminPermissions
{
    public const KEYS = ['dashboard.view', 'plans.view', 'plans.manage', 'vouchers.view', 'vouchers.manage', 'payments.view', 'sessions.view', 'sessions.disconnect', 'router.view', 'router.manage', 'router.advanced', 'support.view', 'support.manage', 'logs.view', 'users.view', 'users.manage', 'businesses.view', 'businesses.manage'];

    public static function catalog(): array
    {
        return array_map(fn ($key) => ['key' => $key, 'name' => ucwords(str_replace(['plans.', '.'], ['packages ', ' '], $key))], self::KEYS);
    }
}
