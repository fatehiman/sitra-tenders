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
- [x] Iranian کدملی checksum validation rule (شناسه ملی is format-only —
      exactly 11 digits, no checksum; see the note under "Open items")
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
- [x] ~~Public `/register` Livewire page: full form → "ارسال کد تایید" button
      → modal with 6-digit input~~ **superseded in Phase 9** by a three-step
      Filament wizard — on success, create user + assign `user`
      role + auto-login + redirect to tenders list
- [x] Resend cooldown + attempt cap + per-mobile/IP send rate limit

## Phase 3 — مناقصات (Bids)

- [x] `bids` + `bid_attachments` migrations
- [x] `BidResource` (admin/staff create/edit): title, `RichEditor`
      description with inline image upload, multi-file attachment upload
      (PDF/Office/image/video/mp3 allow-list), start/expire date-time.
      ~~Dates use Filament's standard Gregorian picker for input — Jalali is
      display-only via `morilog/jalali`~~ **superseded in Phase 9:** the
      pickers are Jalali too now, and `morilog/jalali` has been removed
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
- [x] Virtualmin document root pointed at `public_html/sitra/public` — the
      user did this themselves. Verified live on 2026-08-12: `https://sitra.ir/`
      → 302 → `/login`, `/login` → 200 with `dir="rtl"`
- [x] Rotate the seeded admin password. **Done 2026-08-12, and it mattered**:
      the literal lived in `AdminUserSeeder`, was committed in every commit,
      and had been seeded into the live DB — so publishing this repo
      publicly would have handed out a working production admin login. The
      live password was rotated out-of-band (the new one was given to the
      user directly, not written to any file) and the seeder no longer
      contains a literal at all: it reads `ADMIN_SEED_PASSWORD`, or
      generates a random one and prints it once. Two tests guard that.
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
- [x] Deployed to `https://sitra.ir` on 2026-08-12: files shipped over SSH
      (root key + `chown sitra:sitra`, since port 22 was firewall-blocked
      until the dev IP was whitelisted), three migrations applied clean
      against the live `sitra` DB, caches cleared and rebuilt. Verified:
      `/goods` and `/goods/create` 302 to `/login` (registered), a bogus
      path still 404s, no new entries in `storage/logs/laravel.log`
- [x] `SMS_DRIVER=msgway` + the real API key set in the server `.env`
      (perms tightened to 600 — it was world-readable). A test send **from
      the server** returns the same `200101020 / حساب کاربری شما تایید نشده
      است` (traceID `yVLU7Pn51NMV5d7`) as from the dev machine, which
      confirms outbound egress and key auth both work and the block is
      purely account-side
- [ ] **Explicitly not in this phase**: unit of measure (واحد) on goods —
      quantities are integer counts by explicit decision; a bulk
      import/export for the goods catalogue; per-row notes on a requirement

## Phase 8 — Vazirmatn, relaxed شناسه ملی, codebase comments (2026-08-15)

- [x] Self-hosted **Vazirmatn** v33.0.3 (SIL OFL) replaces Tahoma and the
      Bunny Fonts CDN fetch. One static `public/css/vazirmatn.css` carries
      the `@font-face` rules and is linked by both the panel (Filament's
      `LocalFontProvider`, replacing the CDN-backed default) and the
      standalone `/register` layout. See
      [ARCHITECTURE.md](ARCHITECTURE.md#typography-vazirmatn-self-hosted-no-external-requests)
- [x] شناسه ملی relaxed to `digits:11` + `unique`; the checksum rule class
      deleted, two tests added to pin it (see "Open items" below)
- [x] Beginner-oriented comment pass over every source file; recorded as a
      standing convention in README.md
- [x] **Deployed to `https://sitra.ir` 2026-08-15.** Tarball over SSH as
      before. Verified: `/` → 302, `/login` and `/register` → 200,
      `dir="rtl"` intact, `--font-family: 'Vazirmatn'` in the panel shell,
      `/css/vazirmatn.css` and the 111 KB `.woff2` both 200, no Tahoma and
      no external font host anywhere in the live HTML, `digits:11`
      confirmed accepting/rejecting correctly via tinker on the box,
      everything `sitra`-owned, zero log entries after the deploy.
- [x] **Two deploy mistakes worth not repeating** — both now written up
      under [ARCHITECTURE.md](ARCHITECTURE.md#deploy-pitfalls-both-hit-for-real):
      shipping `bootstrap/cache/` 500'd the whole site for ~2 minutes
      (local `packages.php` referenced `laravel-lang/lang`, a `require-dev`
      package absent from the server's `--no-dev` vendor), and deleting a
      class file needed a `composer dump-autoload` to clear the stale
      classmap entry. Both fixed; the log shows errors only inside that
      window.
- [x] Confirmed the SSH route has changed: port 22 is directly reachable
      now, the `Ger1` proxy session no longer works, and deploys go through
      the `Ger1-root` key with every command wrapped in `sudo -u sitra`.

## Phase 9 — layout width, Jalali everywhere, registration wizard (2026-08-15)

- [x] **Panel layout width.** `->sidebarWidth('15.625rem')` (70px narrower
      than Filament's 20rem default) and `->maxContentWidth(Width::Full)` in
      `AppPanelProvider`. Both are panel settings that Filament turns into
      CSS custom properties — no stylesheet involved. Written up in
      `E:\www\=knowledgebase\filament\panel-width-sidebar-and-content.md`
- [x] **Jalali everywhere the user looks.** Added
      `ariaieboy/filament-jalali:^2.2` (the v4-native branch) and switched
      the tender date-time pickers to `->jalali()`, plus every date column
      and infolist entry to `->jalaliDateTime()`. Formats centralised in
      `config/filament-jalali.php`; digits stay Latin by decision. Removed
      `morilog/jalali`, which nothing referenced any more. See
      [ARCHITECTURE.md](ARCHITECTURE.md#calendar--localization)
- [x] **Bid create/edit form stacks.** `->columns(1)` on the form schema —
      a resource form defaults to two columns, which had the tender details
      and the goods table side by side at half width each
- [x] **Registration rebuilt as a three-step Filament wizard**
      (`App\Filament\Auth\Register`, wired up with the panel's
      `->registration()`): mobile → OTP → details, with a 10-minute window
      after verification enforced from `otp_verifications`, not from
      component state. Deleted the standalone Livewire page, its Blade view,
      the standalone layout and the `/register` route.
      This also fixed the reported bug that **the OTP box never appeared**:
      the old view's modal sat outside the Livewire root element, so
      Livewire discarded it on re-render. Nine wizard tests plus a new
      `PageRendersTest` doing real authenticated HTTP GETs
- [x] Two knowledgebase articles added under `E:\www\=knowledgebase\filament`
      (panel width; Jalali + the `goToNextWizardStep()` testing trap), index
      updated

## Phase 10 — bid lifecycle, tender locking, two form bugs (2026-08-15)

- [x] **Enter no longer submits the registration wizard.** Pressing Enter on
      step 1 or 2 ran `register()` (the last step's action), failed the OTP
      window check and bounced back to step 1. One `extraAttributes` handler
      on the `Wizard` makes Enter call the Alpine component's own
      `requestNextStep()` instead — see
      [ARCHITECTURE.md](ARCHITECTURE.md#the-enter-key-means-بعدی-not-ثبتنام)
- [x] **The eye modal now lists the tender's attachments** under the
      description, each with its own download link
- [x] **پیشنهاد lifecycle.** `bid_suggestions` gained `status`,
      `submitted_at`, `cancelled_at`, `cancelled_by`, `cancel_reason`;
      `App\Enums\SuggestionStatus` holds every step, including the four that
      the future admin review screens will set. The tenders table shows the
      user their own submission time and status
- [x] **Read-only «مشاهده پیشنهاد»** for the user, and «پیشنهادهای دریافتی»
      for staff/admin
- [x] **Tenders lock on first bid** — `BidPolicy::update()`/`delete()` refuse
      them (admins included), with a lock icon explaining the missing
      «ویرایش» button rather than a silent gap
- [x] **«لغو» (admin only)** cancels selected bids with an optional reason,
      unlocking the tender and letting those users bid again
- [x] **Admin user form: switching to حقوقی now reveals نام شرکت / شناسه
      ملی.** `->options(PersonType::class)` attaches an enum state cast, so
      `$get('person_type')` returned a `PersonType` object and the
      `=== 'company'` string check never matched
- [x] Six new tests (bid lifecycle + lock + the person-type regression);
      43 passing

## Phase 11 — the priced bid wizard, Tehran time, panel tidy-up (2026-08-16)

- [x] **Registration always lands on مناقصات.** Filament's
      `RegistrationResponse` uses `redirect()->intended()`, and that session
      key survives across visits — a visitor who had earlier hit
      `/goods/1/edit` while logged out was sent there after signing up, to a
      page their new role cannot open. `Register::register()` now clears
      `url.intended` first. See
      [ARCHITECTURE.md](ARCHITECTURE.md#after-registration-always-the-tenders-list)
- [x] **No «داشبورد» sidebar item.** The page still owns `/` and still
      redirects to مناقصات (that is where Filament sends people after
      login); it just returns `false` from `shouldRegisterNavigation()`
- [x] **«تغییر رمز عبور» moved to the bottom** of the sidebar —
      `$navigationSort = 99`. A page with a null sort is treated as 0 and
      sat above all three resources
- [x] **App timezone is `Asia/Tehran`**, not UTC. Pre-existing rows were
      deliberately left as-is; see
      [ARCHITECTURE.md](ARCHITECTURE.md#timezone-asiatehran)
- [x] **«انصراف از پیشنهاد»** — the bidder may delete their own submitted
      bid, permanently and with its files, but only before the tender's
      deadline. Deliberately different from the admin's «لغو», which keeps
      the row and its reason
- [x] **پیشنهاد rebuilt as a five-step wizard** at `/bids/{record}/suggest`:
      per-good ریال pricing with live line and grand totals → text + up to
      10 attachments → رسید پرداخت/ضمانت‌نامه → mobile confirmation → SMS
      code, ending in an 8-digit «کد پیگیری». Every step saves a real
      server-side draft (`bid_suggestion_items`,
      `bid_suggestion_attachments`, status «پیش‌نویس»), and a draft neither
      locks its tender nor is visible to staff
- [x] Quantities and line totals are recomputed from
      `bid_good_requirements` on every save, and rows citing another
      tender's requirement are dropped — both pinned by tests
- [x] Two Filament traps found and documented: `Schema::getState()`
      validates the whole form (so nothing in a draftable wizard may be
      `->required()`), and Filament re-keys repeater items (so an item's
      array key cannot identify its row). Both had produced real bugs —
      draft saves failing on step 1, and prices landing on the wrong good.
      See [ARCHITECTURE.md](ARCHITECTURE.md#two-filament-traps-this-page-hit-both-silent)
- [x] `finalize()` catches `Halt` itself. It is reached through a plain
      `wire:submit`, which has neither of the wrappers Filament puts around
      actions and wizard steps — so an incomplete submit would have been a
      500 instead of a "you are missing a receipt" notice
- [x] Thirteen new tests (wizard happy path, draft restore, tamper guards,
      SMS gating, role/deadline refusals, withdrawal, tracking codes,
      incomplete submit) plus a real-HTTP render check for the new page;
      56 passing
- [x] **Deployed to sitra.ir on 2026-08-16.** Three migrations ran clean;
      `composer dump-autoload --optimize --no-dev` first (four new classes,
      and the server's autoloader is optimized), then `php artisan optimize`.
      Verified live: `/` → 302 `/login`, `/login` and `/register` 200,
      `bids/{record}/suggest` registered, `config('app.timezone')` reports
      `Asia/Tehran`, no new entries in `storage/logs/laravel.log`

## Open items to revisit later (not blocking v1)

- **شناسه ملی is no longer checksum-validated — this was decided, not
  overlooked.** `App\Rules\IranianCompanyNationalId` implemented the
  commonly-cited community checksum, which turned out to reject real,
  currently-issued company IDs and so blocked legitimate registrations. The
  rule class was deleted; both the public registration form and the admin
  `UserForm` now validate `company_national_id` with `digits:11` plus the
  existing uniqueness check, and `RegistrationTest` asserts an arbitrary
  11-digit value is accepted so nobody re-adds the checksum by mistake.
  If a verified algorithm is ever obtained from an authoritative source it
  can go back in — as a warning, ideally, not a hard reject.
  `App\Rules\IranianNationalId` (کدملی) uses the well-documented individual
  checksum and stays.

- Whether a 4th role is needed, and what it can do (mentioned as a
  possibility, not specified)
- Whether OTP is ever needed for **login** (not just registration) or for
  password reset — today password reset has no spec at all; flag to the
  user before building it
- **The admin review flow — Form الف and Form ب — is specified later.** The
  status ladder that flow drives already exists
  (`App\Enums\SuggestionStatus`): opening Form الف for a bid should move it
  to `form_a`, opening Form ب to `form_b`, and accepting/rejecting to
  `approved`/`rejected`. `BidsTable::viewSuggestionsAction()` is where those
  screens attach. **What a پیشنهاد contains is no longer open** — per-good
  ریال pricing, attachments and a payment receipt, all built in Phase 11.
  Still open: notifications to staff/admin when a bid arrives, and whether
  admins should be able to look a bid up by its «کد پیگیری»
- Whether Redis should be adopted for queue/cache if tender volume or
  attachment processing grows enough to matter
