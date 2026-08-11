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

- [ ] Confirm with the user that Virtualmin's docroot now points at
      `public_html/sitra/public` (their action, not ours — see
      [ARCHITECTURE.md](ARCHITECTURE.md#deployment-topology))
- [ ] Remove the placeholder `index.html` from `public_html`
- [ ] Ship the app to `/mnt/ger_hd1/www/sitra/public_html/sitra/`
      (excluding `node_modules`, `.git`, dev-only files)
- [ ] `composer install --no-dev --optimize-autoloader` on the server (PHP
      8.4 FPM pool already has every extension Filament needs — verified,
      see ARCHITECTURE.md)
- [ ] Real `.env` on the server (DB `sitra`, real `MSGWAY_API_KEY`, strong
      `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`)
- [ ] `.user.ini` in the deployed `public/` raising `memory_limit` and
      `upload_max_filesize`/`post_max_size` past the pool's conservative
      defaults (128M / 2M / 8M) — no root needed, verified viable
- [ ] `php artisan migrate --force --seed` against the live `sitra` DB
- [ ] `php artisan storage:link`, `npm run build` (or ship pre-built
      `public/build`), cache config/routes/views for production
- [ ] Verify HTTPS via the existing `sitra.ir` cert already configured on
      the vhost
- [ ] Rotate the seeded local admin password immediately after first
      production login

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
