# سامانه مناقصات سیترا (Sitra Tenders System)

A Persian-only, RTL-only tender/bid management platform built on Laravel +
Filament. Companies and individuals register with mobile OTP verification,
browse and respond to open tenders (مناقصات), and staff/admins publish and
manage tenders from the same panel.

This is the project's entry-point doc. See also:

- [ARCHITECTURE.md](ARCHITECTURE.md) — stack, key decisions, module design, deployment topology
- [PLAN.md](PLAN.md) — phased implementation roadmap and status
- [DATABASE.md](DATABASE.md) — schema reference

## Feature summary

1. **Public registration** — name, family, mobile, national ID (کدملی), person
   type (حقیقی/حقوقی؛ حقوقی adds company name + شناسه ملی), password. Mobile
   is verified via a 6-digit SMS OTP sent from a button + modal (no multi-step
   wizard/page change). On success the account is created and the user is
   logged straight into the panel. If `company_name` is set, the account is
   treated/displayed as a company account everywhere in the app; otherwise
   the person's name + family is displayed.
2. **Admin** can list/manage all registered users, and can create users or
   staff directly (no OTP required for admin-created accounts). Three roles
   exist today — `admin`, `staff`, `user` — modeled so a fourth role can be
   added later without schema changes.
3. **Project title**: سامانه مناقصات سیترا, shown as the panel/brand name.
4. **Change password** page available to every authenticated role.
5. **مناقصات (Tenders) list** is the default/home page after login for every
   role. Admin and staff can create tenders: title, rich-text description
   (with inline image upload directly from the editor), many attachments of
   mixed types (PDF/Office/images/video/audio), and a start/expire
   date-time.
6. **Regular users** only see tenders that have started and not yet expired,
   and can submit **one suggestion per tender** — scaffolded for now as a
   button that opens a wizard-style modal (no deep business logic yet).

## Tech stack

- **Laravel** (latest stable at implementation time) + **Filament** (latest
  stable v4) — single shared panel for all three roles, resources gated by
  policies/roles instead of separate panels.
- **spatie/laravel-permission** for roles (admin/staff/user, extensible).
- **MySQL 8** for storage, local disk for file attachments.
- Persian-only UI, RTL layout, Jalali (Shamsi) date display on top of
  Gregorian storage (see [ARCHITECTURE.md](ARCHITECTURE.md#calendar--localization)).
- SMS OTP delivery through a **provider-agnostic gateway** with a
  [msgway.com](https://msgway.com) driver as the default implementation.

## Local development

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build   # or `npm run dev` while iterating
php artisan serve
```

Seeded roles (`admin`, `staff`, `user`) and a default admin account are
created by `RoleSeeder` / `AdminUserSeeder` — see [PLAN.md](PLAN.md) for
credentials-handling notes (never commit real secrets; local seeder uses a
throwaway password you must change immediately).

## Production target

`https://sitra.ir` — a Virtualmin-managed shared VPS. Deployment specifics
(paths, PHP-FPM pool, why the docroot is being changed) are in
[ARCHITECTURE.md](ARCHITECTURE.md#deployment-topology).
