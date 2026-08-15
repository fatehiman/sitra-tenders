<?php

namespace App\Enums;

/**
 * Canonical role names backing the spatie/laravel-permission `roles` table.
 * Adding a fourth role later means adding a case here + seeding it — no
 * schema change.
 *
 * Roles are rows in a database table, not columns on `users`. This enum
 * exists so the rest of the code writes `RoleName::Admin->value` instead of
 * the bare string 'admin' — a typo in a literal fails silently and grants
 * or denies the wrong access, while a typo here is a fatal error.
 *
 * Who is who: `admin` runs the system (manages users), `staff` publishes
 * and manages tenders and goods, `user` browses tenders and submits offers.
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
