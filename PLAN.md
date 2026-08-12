# Implementation plan

Status legend: `[ ]` pending · `[~]` in progress · `[x]` done. Keep this file
updated as work lands — it's the source of truth for "what's left."

## Phase 0 — Scaffolding

- [x] `composer create-project laravel/laravel .` in this directory, git init
- [x] `composer require filament/filament:"^4.0"`, install the panel
      provider (single panel, mounted at `/`) — resolved Laravel 13.24 +
      Filament v4.12.6
- [x] `composer require spatie/laravel-permission`, publish + run its
      migrations
- [x] Lock app locale to `fa`, force RTL, strip unused Laravel locale
      scaffolding (no language switcher) — Filament v4 ships built-in `fa`
      translations + RTL direction, no extra package needed;
      `laravel-lang/lang` added for core Laravel validation/auth/pagination
      Persian strings
- [x] `.env.example` covers `MSGWAY_API_KEY`, `MSGWAY_BASE_URL`,
      `MSGWAY_TEMPLATE_OTP`, `SMS_DRIVER`, DB creds — **no real secrets
      committed**

## Phase 1 — Users, roles, auth

- [x] `users` migration per [DATABASE.md](DATABASE.md#users) (no email
      column)
- [x] `RoleSeeder` (admin/staff/user) + a throwaway local `AdminUserSeeder`
      (password must be rotated immediately outside of local dev)
- [x] Iranian کدملی / شناسه ملی checksum validation rules
- [x] Custom Filament Login page authenticating by `mobile`
- [x] `UserResource` (admin-only): list all users, create user/staff
      directly with `mobile_verified_at` stamped immediately, no OTP;
      role field restricted to `staff`/`user` (creating another `admin`
      via the UI is out of scope for v1 — do via seeder/tinker if ever
      needed)
- [x] Change-password page, available to all three roles

## Phase 2 — SMS gateway + registration/OTP

- [x] `App\Sms` contract + manager + `LogSmsDriver` + `MsgwaySmsDriver`
      (implements both documented gotchas: positional `params`, top-level
      `code`) per [ARCHITECTURE.md](ARCHITECTURE.md#sms-gateway)
- [x] `otp_verifications` + `sent_sms_log` migrations
- [x] Public `/register` Livewire page: full form → "ارسال کد تایید" button
      → modal with 6-digit input → on success, create user + assign `user`
      role + auto-login + redirect to tenders list
- [x] Resend cooldown + attempt cap + per-mobile/IP send rate limit

## Phase 3 — مناقصات (Bids)

- [x] `bids` + `bid_attachments` migrations
- [x] `BidResource` (admin/staff create/edit): title, `RichEditor`
      description with inline image upload, multi-file attachment upload
      (PDF/Office/image/video/mp3 allow-list), start/expire date-time.
      Dates use Filament's standard Gregorian picker for input — Jalali is
      display-only via `morilog/jalali` (see ARCHITECTURE.md's calendar
      section for why the input picker wasn't swapped)
- [x] `active()` scope wired into the `user`-role table query (started,
      not expired); admin/staff see full lifecycle
- [x] Bids list set as the panel's home page for every role (custom
      `App\Filament\Pages\Dashboard` owns `/` and redirects to
      `BidResource::getUrl()`)

## Phase 4 — Suggestions (scaffold only, per explicit scope)

- [x] `bid_suggestions` migration with `(bid_id, user_id)` unique
      constraint
- [x] "ارسال پیشنهاد" button on the tenders list (user role, active bids
      only) opening a Filament wizard-modal action with one free-text field
- [x] Button disabled/hidden once the user already has a suggestion for
      that bid
- [ ] **Explicitly not in this phase**: review/approval workflow,
      notifications, suggestion editing/withdrawal — flag these as future
      work if/when the user asks for the full flow

## Phase 5 — Local verification

- [x] `php artisan migrate --seed` clean run
- [x] Automated coverage in `tests/Feature` (registration + OTP happy/sad
      path, admin-created staff skip OTP, active-bid scope, one-suggestion-
      per-user DB constraint, role-gated resource access) — 9/9 passing.
      This caught a real bug: `mobile_verified_at`/`created_by` were
      missing from `User`'s `#[Fillable(...)]` list, so both the
      registration flow and admin-created accounts were silently getting a
      `null` verified timestamp. Fixed.
- [x] Manual smoke test via `php artisan serve` + curl: `/` redirects
      guests to `/login`, `/login` and `/register` render with
      `dir="rtl"` and correct Persian title
- [x] `npm run build` succeeds, RTL/Persian renders correctly (no leftover
      LTR Filament chrome)

## Phase 6 — Deployment to sitra.ir

- [x] Ship the app to `/mnt/ger_hd1/www/sitra/public_html/sitra/` via a
      tarball over `pscp`/`plink` (excluding `node_modules`, `.git`,
      `vendor`, `tests`, dev-only files; `public/build` shipped pre-built
      from the local `npm run build` so Node never had to run on the
      shared box)
- [x] `composer install --no-dev --optimize-autoloader` on the server — hit
      one snag: `storage/framework/{views,sessions,cache/data,testing}`
      were excluded from the tarball (kept only via `.gitignore` locally),
      so Blade's compiler failed with "Please provide a valid cache path."
      Fixed by `mkdir -p`-ing them on the server before re-running.
- [x] Real `.env` on the server: DB `sitra` (mysql, 127.0.0.1), `APP_ENV
      =production`, `APP_DEBUG=false`, `APP_URL=https://sitra.ir`,
      `SESSION_SECURE_COOKIE=true`, fresh `APP_KEY` generated on the
      server. `SMS_DRIVER` is still `log` in production — **msgway isn't
      live yet** because no real `MSGWAY_API_KEY` has been provided (see
      "Open items" below); OTP codes are only written to the server's log
      until that key is supplied.
- [x] `.user.ini` uploaded into `public/` (`memory_limit=256M`,
      `upload_max_filesize=60M`, `post_max_size=64M`,
      `max_execution_time=60`) — confirmed no root needed, per the
      pre-deploy verification in ARCHITECTURE.md
- [x] `php artisan migrate --force --seed` ran clean against the live
      `sitra` DB (roles + the throwaway admin account seeded)
- [x] `php artisan storage:link`, `php artisan optimize` (config/route/
      view/Filament caches) — all cached with no errors
- [x] Smoke-tested via PHP's built-in server bound to the app's `public/`
      directly on the box (bypassing Apache, since the vhost docroot
      hasn't been switched yet): `/` → 302 to `/login`, `/login` → 200,
      `/register` → 200, no new entries in `storage/logs/laravel.log`
- [x] Removed the placeholder `index.html` from `public_html` (explicitly
      authorized)
- [ ] **Still needed from the user**: point Virtualmin's document root at
      `public_html/sitra/public` — they opted to do this step themselves
      (see [ARCHITECTURE.md](ARCHITECTURE.md#deployment-topology)); the
      site is not reachable at `https://sitra.ir` until this happens, even
      though everything behind it is ready and verified
- [ ] Rotate the seeded local admin password (`09120000000` /
      `ChangeMe123!`) immediately after first production login — this is
      a throwaway credential, not meant to survive contact with a real
      environment
- [x] Supply a real `MSGWAY_API_KEY` and flip `SMS_DRIVER=msgway` — done;
      the key authenticates and the driver is verified end to end against
      the live API
- [ ] **Blocked on the user, outside this codebase**: complete msgway
      account verification (احراز هویت) in the msgway panel. Test sends
      currently come back `200101020 / حساب کاربری شما تایید نشده است`
      (traceID `88f7qzuPmPaWzRX`) — an account-level block, not a balance or
      code problem. No OTP will deliver until it clears. See
      [ARCHITECTURE.md](ARCHITECTURE.md#current-msgway-status)

## Phase 7 — کالاها (Goods) + bid requirement rows

- [x] `goods` + `good_drawings` + `bid_good_requirements` migrations per
      [DATABASE.md](DATABASE.md#goods-کالاها)
- [x] `GoodResource` (admin/staff): کد کالا (unique), شرح کالا, ابعاد و
      مشخصات فنی, and نقشه as one-or-more PDF/image uploads.
      `GoodPolicy` hides the whole menu item from the `user` role
- [x] Deleting a good that a tender already cites is refused, with a Persian
      notification naming the tenders — `restrictOnDelete` FK as the
      backstop, no bulk delete on the table
- [x] «کالاهای مورد نیاز» `Repeater` at the bottom of the bid create/edit
      form: searchable good picker (matches شرح کالا **or** کد کالا, shown
      as «نام (کد)») + integer quantity + add-row, bound straight to the
      `goodRequirements` relationship. Same good twice per tender blocked
      in the UI and by a unique index
- [x] Two read-only record actions on the bids list for **every** role: eye
      icon → title/description/dates modal, clipboard icon → the goods
      table (with per-good نقشه download links)
- [x] 14 tests covering the picker label/search, uniqueness, the delete
      guard (DB + UI), create/edit round-trip of requirement rows, both
      modals rendering, role gating, and that the description modal strips
      markup the editor could not have produced
- [ ] **Explicitly not in this phase**: unit of measure (واحد) on goods —
      quantities are integer counts by explicit decision; a bulk
      import/export for the goods catalogue; per-row notes on a requirement

## Open items to revisit later (not blocking v1)

- `App\Rules\IranianCompanyNationalId` (شناسه ملی checksum) is implemented
  from commonly-cited community references, not verified against an
  authoritative source — sanity-check it against known-valid شناسه ملی
  values before trusting it to hard-reject real company registrations.
  `App\Rules\IranianNationalId` (کدملی) uses the well-documented individual
  checksum and is on firmer ground.

- Whether a 4th role is needed, and what it can do (mentioned as a
  possibility, not specified)
- Whether OTP is ever needed for **login** (not just registration) or for
  password reset — today password reset has no spec at all; flag to the
  user before building it
- Full پیشنهاد (suggestion) workflow beyond the scaffold (review states,
  notifications to staff/admin, editing/withdrawal rules)
- Whether Redis should be adopted for queue/cache if tender volume or
  attachment processing grows enough to matter
