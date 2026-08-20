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

## Phase 11 — deleting user accounts (2026-08-16)

- [x] **«حذف» on کاربران, admin-only and permanent.** The confirmation
      counts the account's پیشنهادها («این کاربر 3 پیشنهاد ثبت کرده است…»)
      or says there are none, so nothing disappears unannounced. Drafts
      count too — they are rows with files behind them
- [x] **Admins are undeletable**, including by themselves
      (`UserPolicy::delete()`), with a shield icon on those rows explaining
      the missing button rather than leaving a silent gap
- [x] **An account that published مناقصات is refused**, with a notification
      naming the tenders. `bids.created_by` cascades, so deleting a
      کارشناس would have taken their tenders *and* every other user's bid
      on them. Chosen over reassigning tenders or deleting them silently
- [x] `User::purge()` deletes the uploaded FILES of every پیشنهاد (via
      `BidSuggestion::purge()`) and the account's `otp_verifications` rows
      before the row goes — the DB cascade takes rows only. No bulk delete,
      for the same per-record reasons as کالاها. See
      [ARCHITECTURE.md](ARCHITECTURE.md#deleting-a-user-account)
- [x] **Global search box removed** (`->globalSearch(false)`): no resource
      declares globally-searchable attributes, so the topbar field could
      never return a result
- [x] Three new tests (files-and-rows really go, admins refused, tender
      owner refused); 59 passing
- [x] **Deployed to sitra.ir on 2026-08-16.** No migrations and no new
      classes, so a file ship plus `php artisan optimize` was enough.
      Verified live: `/` → 302, `/login` → 200, everything `sitra`-owned,
      no new entries in `storage/logs/laravel.log`

## Phase 12 — ودیعه deposit, terms step, and the پرداخت step (2026-08-18)

- [x] **`bids.deposit_amount`** — admin sets a whole-ریال ودیعه (bid-guarantee
      deposit) on the tender create/edit form; shown to the bidder at the top
      of the wizard's «پرداخت» step
- [x] **پیشنهاد wizard grew from five steps to six.** New step 1 «شرایط
      مناقصه» reuses the «مشاهده» eye icon's title/description/attachments
      content and gates on a «شرایط مناقصه را خواندم و موافق هستم» checkbox
      before anything else can be filled in. New step 2 «پرداخت» replaces
      the old step 3 «رسید پرداخت» with three payment methods: پرداخت
      الکترونیک (a placeholder link — no real gateway exists yet), بارگذاری
      ضمانت‌نامه بانکی (mandatory file), or نامه کسر از مطالبات
      (fill-in-the-blank letter, optional attachment). قیمت کالاها and
      توضیحات و پیوست‌ها moved down to steps 3 and 4; تایید نهایی and کد
      تایید are unchanged at 5 and 6
- [x] **The checkbox and the payment-method fields carry no `->required()`**,
      for the same reason nothing else in this wizard does — see
      [ARCHITECTURE.md](ARCHITECTURE.md#two-filament-traps-this-page-hit-both-silent).
      Each new step's own `afterValidation()` halts navigation instead, and
      `assertReadyToFinalize()` (via the new `paymentProblem()` helper) is
      the final gate before the SMS and at submit time
- [x] **`claims_decrease_org_name` is never taken from the browser.** Every
      draft save overwrites it with `Auth::user()->display_name` directly —
      the same trust rule `bid_good_requirements.quantity` follows. Pinned by
      a test that tries to smuggle a different name through `fillForm()`
- [x] **«حذف پیش‌نویس»** next to «ذخیره پیش‌نویس» — deletes the draft and its
      files outright, no confirmation (a draft was never submitted), and
      re-checks `isDraft()` itself before calling `purge()` in case another
      tab finalised the same bid meanwhile
- [x] **`SuggestionAttachmentType::PaymentReceipt` renamed to
      `BankGuaranteeLetter`**, plus a new `ClaimsDecreaseAttachment` case.
      Because this app was already live on sitra.ir, a data migration
      relabels any existing `payment_receipt` row to `bank_guarantee_letter`
      first — removing the enum case outright would have thrown on the next
      read of an old row instead of silently losing it
- [x] Ten new/updated wizard tests (terms gate, payment-method gate, the
      claims-decrease org-name trust rule, delete-draft, plus the existing
      happy-path/draft-restore/tamper-guard tests updated for the new step
      order); 63 passing
- [x] **Deployed to sitra.ir on 2026-08-18.** Three new migrations ran;
      the attachment-type relabel first failed live —
      `bid_suggestion_attachments.type` was `varchar(20)`, too narrow for
      `bank_guarantee_letter`/`claims_decrease_attachment` — fixed by
      widening the column to `varchar(30)` in the same migration, verified
      against the two real `payment_receipt` rows already on the box (both
      now `bank_guarantee_letter`), then `composer dump-autoload --optimize
      --no-dev` (new `PaymentType` class) and `php artisan optimize`.
      `/login` and `/` verified live, no new log entries after the fix

## Phase 13 — specifications step, password reset, and the two-envelope review (2026-08-19)

- [x] **Upload speed investigated and closed as NOT an app problem.** Measured
      rather than guessed: 3 MB to `sitra.ir` at ~1.72 MB/s vs ~1.35 MB/s to
      `speed.cloudflare.com` on the same connection, i.e. the server beats the
      general internet baseline and the ceiling is the client's own ~14 Mbit/s
      uplink. Nothing in the request path adds work (no image processing, PHP
      limits already raised via `public/.user.ini`), so no code changed — see
      [ARCHITECTURE.md](ARCHITECTURE.md#upload-speed-measured-and-not-the-app-2026-08-19)
- [x] **New wizard step 3 «مشخصات فنی کالاها»**, before «قیمت کالاها»: the same
      goods table with a «مشخصات فنی قابل تامین» box per good, placeholder
      «مشخصات کارفرما را میپذیرم». Empty = accepts the employer's spec and
      stores NO row (`bid_suggestion_specifications`); typed = the spec they
      can supply. No جمع column, no total. The employer's «ابعاد و مشخصات فنی»
      column was removed from the price step — that question is asked and
      answered on the new step
- [x] **«فراموشی رمز عبور»** (`App\Filament\Auth\ForgotPassword`): mobile →
      OTP → new password twice → logged straight in. Extends Filament's
      `RequestPasswordReset` for the auth chrome and rate limiter but replaces
      its email-link flow entirely (no email column exists); registered via
      `->passwordReset(...)`, which also puts the link under the login form
- [x] **Project title** is now «سامانه الکترونیکی مدیریت استعلام پیشنهادات
      تامین کنندگان» (`APP_NAME`), with `->brandName('سامانه مدیریت استعلام')`
      for the 250px sidebar. Note Filament uses the BRAND name for both the
      sidebar and the `<title>` suffix, so the tab reads the short form; the
      full title lives in `APP_NAME` (and in the `.env` on the server)
- [x] **Bidder identity is masked from admins** — «مخفی شده» in the
      «پیشنهادهای دریافتی» modal, the «لغو» list (offers identified by «کد
      پیگیری» + time instead) and both envelope screens, via the single rule
      `BidSuggestion::bidderNameForAdmin()`. Only winners of a finalised tender
      are unmasked. «مبلغ کل» is also hidden in that modal until پاکت الف is
      finalised, or the whole point of opening الف first would be lost
- [x] **The two-envelope admin review** (`OpenEnvelope`, at
      `/bids/{record}/envelope/{a|b}`): one offer at a time, تایید/رد +
      قبلی/بعدی, a review list, and a «ثبت نهایی» behind an "cannot be undone"
      checkbox. Verdicts are saved as drafts in
      `bid_suggestions.envelope_?_decision`; only `bids.envelope_?_submitted_at`
      (written with the statuses in one transaction) makes them real. الف hides
      prices completely; ب shows them and only contains الف-approved offers.
      Letter icon: closed/primary → closed/orange → open/grey «تخته برندگان»
- [x] **Result SMS**: templates 23572 (`bid_won`) and 23573 (`bid_declined`) in
      `config/sms.php`, sent by `App\Services\SuggestionResultNotifier` after
      پاکت ب commits — winners get 23572, **every other live bidder** gets
      23573 (including those rejected in الف). Params: bidder's name + family,
      then the tender title. Failures are logged, never thrown: a provider
      outage must not roll back an irreversible review
- [x] Two migrations (`bid_suggestion_specifications`, the four envelope
      columns) and 17 new/updated tests — the wizard's step numbers shifted,
      plus new suites for the password-reset wizard and both envelopes;
      80 passing
- [x] **Deployed to sitra.ir on 2026-08-19.** Tarball over SSH as before
      (port 22 was unreachable while this phase was built and came back before
      the deploy). Both migrations ran clean, `composer dump-autoload
      --optimize --no-dev` picked up the new enum/model/service/page classes,
      `php artisan optimize` re-cached. The live `.env` gained
      `MSGWAY_TEMPLATE_BID_WON=23572` / `MSGWAY_TEMPLATE_BID_DECLINED=23573`
      and the new `APP_NAME` (old copy backed up under `/root`). Verified:
      `/` → 302, `/login`, `/register` and `/password-reset/request` → 200 with
      all three reset steps rendering, the login page linking to it, the brand
      showing «سامانه مدیریت استعلام», everything `sitra`-owned, and no log
      entries after the deploy finished. **One transient error is in the log at
      15:37**: a request served in the gap between extracting the code and
      running `artisan optimize` hit the stale route cache and threw
      `Route [filament.app.auth.password-reset.request] not defined` — the new
      login page linking to a route the cached table did not have yet. Harmless
      once cached, but the lesson generalises: **when a deploy adds a ROUTE,
      re-run `php artisan optimize` in the same breath as the extract**

## Phase 13.1 — envelope navigation fix + نقشه on the specifications step (2026-08-19)

- [x] **Bug, reported from production: deciding the last offer went nowhere.**
      On `/bids/4/envelope/a` (a tender with one offer) تایید/رد wrote the
      verdict but left the old body on screen with only قبلی/بعدی, and «قبلی»
      on the review screen vanished instead of going back. Cause: those buttons
      are schema actions living *inside* the section the decision replaces, so
      Filament had to finish an action whose own schema component had just been
      deleted. Fixed by making the offer position a `?offer=N` query parameter
      and every button a REDIRECT (`OpenEnvelope::moveTo()`), which ends the
      request instead of re-rendering it — one page load per click, and the URL
      becomes a real position (refresh/back work). Written up under
      [ARCHITECTURE.md](ARCHITECTURE.md#every-click-is-a-page-load-and-that-is-the-fix-for-a-real-bug)
- [x] **Two labels that were misread**: «بعدی» on the last offer now says
      «مرور و ثبت نهایی», and «قبلی» on the review screen says «بازگشت و تغییر
      تصمیم‌ها» — a bare «قبلی» beside the orange finalise button read as a
      stray control
- [x] **«نقشه» column on wizard step 3** «مشخصات فنی کالاها»: each good's
      drawings as links that open in a new tab, since reading the drawing is how
      a bidder decides whether they can supply to the employer's specification.
      `requirements()` now eager-loads `good.drawings`, so the column costs no
      extra query per row
- [x] Three regression tests for the navigation bug (decide-the-only-offer,
      go-back-from-review, out-of-range `?offer=`) plus the نقشه assertion on
      the render test; 83 passing
- [x] **Deployed to sitra.ir on 2026-08-19** (second deploy of the day)

## Phase 13.2 — admins are managed from the panel (2026-08-20)

- [x] **«مدیر سیستم» added to the کاربران form's listbox**, now labelled
      «سطح دسترسی» (the table column and its filter too). Admins are created
      through the UI; `AdminUserSeeder` only makes the *first* one
- [x] **Other admins are deletable** — `UserPolicy::delete()` no longer
      refuses every admin row, only your own account and the last admin left
      (`User::isLastAdmin()`)
- [x] **Your own account is locked instead**: «سطح دسترسی» and «فعال» are
      `->disabled()` on your own record, and delete is refused. Since you can
      only ever demote/deactivate/delete *another* admin, the account you are
      signed into always survives — that is the whole "at least one admin must
      exist" guarantee, with no counting and no error paths. Written up in
      [ARCHITECTURE.md](ARCHITECTURE.md#managing-admins-and-why-you-cannot-demote-yourself)
- [x] A disabled field is not dehydrated, so `EditUser` now treats a missing
      `role` key as "leave the role as it is" — `syncRoles([null])` would have
      stripped the admin's own role on a self-save
- [x] The shield icon moved from admin rows to the viewer's **own** row, where
      it now explains all three locks at once
- [x] Tests: create-an-admin, delete-another-admin, self-save keeps role and
      is_active, another admin's role is editable, last-admin guard; 87 passing
- [x] **Deployed to sitra.ir on 2026-08-20**

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
- Whether OTP is ever needed for **login** as well. Password reset now uses
  it (Phase 13); login still does not
- **The admin review flow is built** (Phase 13 — پاکت الف / پاکت ب), and it
  writes the whole status ladder. Still open around it: notifications to
  staff/admin when a bid ARRIVES (only the result SMS to bidders exists),
  whether admins should be able to look a bid up by its «کد پیگیری», and
  whether a finalised envelope should ever be re-openable by some
  higher-privileged route (today it is deliberately never re-openable)
- Whether Redis should be adopted for queue/cache if tender volume or
  attachment processing grows enough to matter
