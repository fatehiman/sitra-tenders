<?php

namespace App\Enums;

/**
 * Canonical role names backing the spatie/laravel-permission `roles` table.
 * Adding a fourth role later means adding a case here + seeding it — no
 * schema change.
 */
enum RoleName: string
{
    case Admin = 'admin';
    case Staff = 'staff';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'مدیر سیستم',
            self::Staff => 'کارشناس',
            self::User => 'کاربر',
        };
    }
}
