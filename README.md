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
   type (حقیقی/حقوقی؛ حقوقی adds company name + شناسه ملی — validated as any
   unique 11-digit number, no checksum), password. Mobile
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
6. **کالاها (Goods)** — an admin/staff-only catalogue: کد کالا (unique),
   شرح کالا, ابعاد و مشخصات فنی, and نقشه as one or more attached PDFs or
   images. A good that a tender already cites cannot be deleted.
7. **Tender goods requirements** — the bottom of the tender create/edit form
   carries a «کالاهای مورد نیاز» table: pick a good from a searchable list
   (it matches شرح کالا *or* کد کالا, shown as «نام (کد)»), enter a count,
   add the row. e.g. «۱۰۰۰ عدد پیچ کد ۸۳۷۲۴».
8. **Regular users** only see tenders that have started and not yet expired.
   Every tender row carries an eye icon (title, description, start/end in a
   modal) and a clipboard icon (its goods requirements, with نقشه download
   links). Users can submit **one suggestion per tender** — scaffolded for
   now as a button that opens a wizard-style modal (no deep business logic
   yet).

## Tech stack

- **Laravel** (latest stable at implementation time) + **Filament** (latest
  stable v4) — single shared panel for all three roles, resources gated by
  policies/roles instead of separate panels.
- **spatie/laravel-permission** for roles (admin/staff/user, extensible).
- **MySQL 8** for storage, local disk for file attachments.
- Persian-only UI, RTL layout, Jalali (Shamsi) date display on top of
  Gregorian storage (see [ARCHITECTURE.md](ARCHITECTURE.md#calendar--localization)).
- **Vazirmatn** (SIL OFL) as the single font family, self-hosted from
  `public/fonts/`. The app makes **no external resource requests** — no
  Google/Bunny Fonts, no CDN — and uses no Tahoma anywhere. See
  [ARCHITECTURE.md](ARCHITECTURE.md#typography-vazirmatn-self-hosted-no-external-requests).
- SMS OTP delivery through a **provider-agnostic gateway** with a
  [msgway.com](https://msgway.com) driver as the default implementation.
  The driver is live and verified, but delivery is currently blocked on the
  msgway account's own identity verification — see
  [ARCHITECTURE.md](ARCHITECTURE.md#current-msgway-status).

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

Seeded roles (`admin`, `staff`, `user`) and a default admin account
(`09120000000`) are created by `RoleSeeder` / `AdminUserSeeder`. The seeder
holds **no hard-coded password**: set `ADMIN_SEED_PASSWORD` in `.env`, or
leave it unset and the seeder generates a random one and prints it once —
store it then, it isn't written anywhere else.

## Code conventions

**Comment generously, and write for a beginner.** This is a standing rule
for the project, not a one-off cleanup: assume the next person reading a
file has not used Laravel, Livewire or Filament before. Every class carries
a docblock saying what it is and why it exists, and anything a newcomer
would have to look up — a framework hook, a lifecycle method, a security
measure, a non-obvious method name — gets an inline comment.

Keep explaining the **why** as well as the what. Several comments in this
codebase exist specifically to stop a well-meaning change from undoing a
deliberate decision (the relaxed شناسه ملی rule, the absence of a `status`
column on tenders, the "no Tailwind utilities inside the panel" rule).

## Production target

`https://sitra.ir` — a Virtualmin-managed shared VPS. Deployment specifics
(paths, PHP-FPM pool, why the docroot is being changed) are in
[ARCHITECTURE.md](ARCHITECTURE.md#deployment-topology).
