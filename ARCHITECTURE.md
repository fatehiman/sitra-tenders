# Architecture

## Stack

| Concern | Choice | Why |
|---|---|---|
| Framework | Laravel (latest stable resolved by Composer at scaffold time) | Standard fit for Filament; no reason to pin an older LTS |
| Admin/app framework | Filament v4, **single panel** | User explicitly confirmed one shared panel; roles/policies gate resources instead of separate panel apps |
| Roles | `spatie/laravel-permission` | User explicitly confirmed; adding a 4th role later is a data change, not a schema change |
| DB | MySQL 8.0 (already provisioned on target server as `sitra`) | Given |
| Queue/cache | `database` driver (not Redis) | Target VPS has only 3.7GB RAM shared across ~60 tenants; a shared Redis instance exists on the box but is not dedicated to this app — avoiding it sidesteps key-collision/eviction risk with other tenants. Revisit if queue volume grows. |
| File storage | Local disk (`storage/app/public`, symlinked to `public/storage`) | Single-VPS deployment, no S3 requirement given |
| SMS | Provider-agnostic gateway, default driver = **msgway** | User confirmed; see [SMS gateway](#sms-gateway) below |
| Jalali dates | `ariaieboy/filament-jalali` `^2.2` | The maintained, Filament-v4-native option (v2 = Filament 4; v1 = 3; v3 = 5). Covers pickers, table columns, infolist entries and query-builder date constraints in one package — see [Calendar & localization](#calendar--localization) |
| Locale | Persian (`fa`) only, RTL only | Explicit requirement — no language switcher, no LTR fallback |

Laravel/Filament exact versions are whatever `composer create-project
laravel/laravel` and `composer require filament/filament:"^4.0"` resolve to
at scaffold time (both projects are receiving active security/feature
updates as of 2026); pin to a specific tag only if you have a reason to.

## Panel structure

One Filament panel, mounted at the **domain root** (`->path('')`), not
`/admin`. Rationale: registration/login/tenders are the whole product for
end users, not an admin add-on bolted onto a marketing site — there is no
separate public site to protect a `/admin` prefix from.

- `/login` — Filament's login page, customized to authenticate by **mobile
  number**, not email (this app has no email field at all — see
  [DATABASE.md](DATABASE.md#users)).
- `/register` — Filament's own registration page (`->registration()`),
  subclassed as `App\Filament\Auth\Register`. Filament routes it outside the
  panel's auth middleware for us. **This used to be a standalone Livewire
  route with hand-written Tailwind; it is not any more** — see
  [Registration + OTP flow](#registration--otp-flow).
- Panel home (`/`) resolves to the **مناقصات (Bid) list**, not Filament's
  default Dashboard, for every role — the Dashboard page is not registered.
- `/profile` (or similar) — the **change password** page, available to all
  three roles via the panel's user menu.

`routes/web.php` is therefore effectively empty: the panel registers every
route the app has. Link to registration with
`filament()->getRegistrationUrl()`, not `route('register')` — the route name
is Filament's `filament.app.auth.register`.

Navigation items and resource visibility are controlled per-role via
Filament resource `canViewAny()`/policies backed by
`auth()->user()->hasRole(...)`, not by hiding/showing whole panels.

### Layout width

Two panel-level settings in `AppPanelProvider`, both deliberately **not**
CSS:

```php
->sidebarWidth('15.625rem')     // Filament's default is 20rem (320px)
->maxContentWidth(Width::Full)  // default caps a page at 7xl and centres it
```

Filament emits both as CSS custom properties (`--sidebar-width`, the layout's
max width) that its entire layout is built on, so setting them here keeps the
sidebar, topbar offset and content margins consistent — including in the
collapsed-sidebar state, which an override stylesheet would break. The nav
holds five short Persian labels, so 320px left a wide empty gutter; the
content cap wasted most of a wide monitor on the مناقصات and کالاها tables.

Note this does **not** widen `/login` or `/register`: `SimplePage` prefers
its own `getMaxWidth()` over the panel's content width. The registration
wizard sets that itself.

## Registration + OTP flow

A **three-step Filament `Wizard`** on `App\Filament\Auth\Register`, which
subclasses Filament's own `Auth\Pages\Register`:

1. **«شماره موبایل»** — the mobile number, and nothing else. Pressing «بعدی»
   validates the field (format + not already registered) and only then, in
   the step's `afterValidation()` hook:
   - generates a 6-digit numeric code,
   - stores it **hashed** with a 2-minute TTL and an attempt counter in
     `otp_verifications` (keyed by mobile — no `users` row exists yet),
   - sends it through the SMS gateway (msgway built-in Persian template
     `templateID=3`, `کد تایید شما: [code]` — no panel registration needed).

   A send failure throws, so the wizard stays on this step with the
   provider's own reason under the field.
2. **«کد تایید»** — the code, checked against the hash (attempt-capped,
   TTL-checked) in this step's `afterValidation()`. A «ارسال مجدد کد» action
   re-sends, subject to the 60-second cooldown. Success stamps `verified_at`
   on the row.
3. **«اطلاعات کاربر»** — name, family, national ID, person type, the two
   حقوقی fields, password + confirmation. Submitting creates the `users` row
   in one DB transaction, assigns the `user` role, stamps
   `mobile_verified_at`, deletes the OTP row, logs in, and lands on the
   tenders list.

No `users` row exists until step 3 succeeds, so an abandoned or failed
registration leaves nothing to clean up.

### Why the order changed

The original build was a single page, per an explicit requirement at the
time, and the user later asked for the wizard instead. Verifying the number
*before* asking for eight more fields is the substantive gain: the old form
made people fill in everything and only then discover the SMS could not be
delivered (which, given [the msgway block](#current-msgway-status), was every
single attempt).

That page also had a real bug worth recording, because it is easy to
reintroduce: its OTP modal sat **outside** the Livewire component's single
root element. Livewire only ever patches the first root element, so the SMS
went out, `showOtpModal` flipped to `true`, and the markup asking for the
code was discarded before it reached the browser — the visitor saw nothing
happen. Building the form from Filament schema components makes that class of
bug impossible: there is no hand-written markup left.

### The ten-minute window, and what is actually trusted

Passing step 2 gives the visitor `OtpService::REGISTRATION_WINDOW_SECONDS`
(10 minutes) to finish step 3. Past that, submitting sends them back to a
fresh step 1 with an explanation rather than failing obscurely — their proof
of ownership is gone, so there is nothing left on the page to correct.

**That check reads the database, not the component.** A Livewire component's
public state round-trips through the browser, and a Filament `Wizard`'s
current step is tracked client-side in Alpine — so "the wizard says I am on
step 3" proves nothing, and `->skippable(false)` cannot prove you were ever
on step 1. The gate is `OtpService::verifiedWithin($mobile)`: a row for
exactly the submitted number, stamped `verified_at`, inside the window.
Editing the mobile field after passing the OTP step therefore fails closed,
and there is a test pinning that.

Admin-created users/staff (via the Filament `UserResource` "create" form)
**skip this whole flow** — admin sets the password directly and
`mobile_verified_at` is stamped immediately, since the requirement is
explicit that admin-created accounts need no mobile confirmation.

## Display name rule

`User::getDisplayNameAttribute()` (and `getFilamentName()` for Filament's
`HasName` contract):

```php
return $this->company_name ?: trim("{$this->first_name} {$this->last_name}");
```

`person_type` (`individual`/`company`) drives which fields the registration
form requires; `company_name` being non-null is what actually flips display
everywhere (panel header, tenders list "submitted by", etc.) — the two are
kept in sync at write time (company_name is only ever set when
person_type = company), so the accessor only needs to check one column.

## SMS gateway

Provider-agnostic by design (explicit requirement), modeled after Laravel's
own driver-manager pattern:

```
App\Sms\SmsGateway (contract: send(string $to, string $template, array $params): SmsResult)
App\Sms\SmsManager (Laravel Manager — resolves a driver from config/sms.php)
App\Sms\Drivers\MsgwaySmsDriver   (default; per E:\www\=providers\sms\msgway\msgway.md)
App\Sms\Drivers\LogSmsDriver      (local/testing — writes the OTP to the log instead of sending)
```

`config/sms.php`:

```php
return [
    'default' => env('SMS_DRIVER', 'msgway'),
    'drivers' => [
        'msgway' => [
            'api_key'  => env('MSGWAY_API_KEY'),
            'base_url' => env('MSGWAY_BASE_URL', 'https://api.msgway.com'),
            'templates' => [
                'otp' => (int) env('MSGWAY_TEMPLATE_OTP', 3), // built-in fa template, [code] only
            ],
        ],
        'log' => [],
    ],
];
```

Semantic message name (`'otp'`) → template ID mapping lives in config so
swapping providers or templates is a config change, never a call-site
change — directly follows the msgway doc's own recommendation. The msgway
driver implements the two documented gotchas explicitly (positional
`params` array, `[code]` pulled out to the top-level field) and logs
`referenceID`/`traceID` on every call into a `sent_sms_log` table for
support/debugging (see [DATABASE.md](DATABASE.md#sent_sms_log)).

OTP send is rate-limited per-mobile and per-IP (Laravel's rate limiter) to
control cost, since msgway bills per accepted send regardless of delivery.

### Current msgway status

A real `MSGWAY_API_KEY` is now configured and the driver is verified working
end to end — a test send reached msgway, authenticated, and came back with a
structured error rather than a transport or credential failure:

```
code    200101020
message حساب کاربری شما تایید نشده است
traceID 88f7qzuPmPaWzRX
```

That is an **account-level** block on the msgway side (identity verification
/ احراز هویت not completed in their panel), not a balance problem and not
something any code change can route around — a topped-up balance does not
clear it. Until it clears, no SMS will deliver even though
`SMS_DRIVER=msgway` is correct and live. `SentSmsLog` records the failure
with its `traceID` for the support ticket. Re-confirmed live from the server
on 2026-08-14 (traceID `W8d15WYZqVhvNud`) — same code, still blocked.

Because that block is indistinguishable from a bug when the UI only says
"try again", `OtpService::issue()` returns the gateway's `SmsResult` rather
than a bool, and the registration page appends the provider's own reason in
parentheses: «ارسال کد تایید با خطا مواجه شد… (حساب کاربری شما تایید نشده
است)». The reason is whitespace-collapsed and length-capped; `error.code`
and `traceID` stay in `sent_sms_log` rather than on screen.

Local-dev note: PHP 8.4 on the shipping VPS resolves TLS fine, but a Windows
PHP with no `curl.cainfo` / `openssl.cafile` in `php.ini` fails every msgway
call with `cURL error 60: self-signed certificate in certificate chain`. That
is a dev-machine config gap, not an app bug — point PHP at a `cacert.pem`.

## Calendar & localization

- App locale fixed to `fa`; no language switcher, no other locale files
  loaded.
- `<html dir="rtl" lang="fa">` on every page, including the registration
  page (outside the panel) and the panel shell itself
  (`FilamentServiceProvider`/panel `->rtl()` equivalent — Filament v4 handles
  the RTL flip once locale/direction is set).
- **Dates are stored as standard Gregorian `datetime` columns in MySQL —
  never Jalali in the DB.** Jalali/Shamsi is a **display-only** concern.
  Every date the user sees or picks is Jalali; every date the database,
  the query scopes and the validation rules touch is Gregorian. This keeps
  all date arithmetic (`now()->between(...)`, `->after('start_at')`,
  `ORDER BY start_at`) trivially correct.

  This is done with **`ariaieboy/filament-jalali`**, which adds macros to
  Filament's existing components rather than introducing new ones:

  | Where | Call |
  |---|---|
  | Form input | `DateTimePicker::make(...)->jalali()` |
  | Table column | `TextColumn::make(...)->jalaliDateTime()` |
  | Infolist entry | `TextEntry::make(...)->jalaliDateTime()` |
  | Query-builder filter | `DateConstraint::make(...)->jalali()` |

  Formats live in `config/filament-jalali.php` (`Y/m/d`, `Y/m/d H:i`) so
  there is one definition for the whole app. **Digits are Latin**
  (`1405/05/24`, not `۱۴۰۵/۰۵/۲۴`) by explicit decision — every other
  numeric value in the panel (کد ملی, موبایل, تعداد, file sizes) is Latin,
  and mixing the two in one table reads as a rendering fault.

  Two ordering traps in the picker chain, both silent:
  `->native(false)` must come **before** `->jalali()` (a native
  `datetime-local` input can only render a Gregorian calendar), and
  `->displayFormat(...)` must come **after** it, because `->jalali()` sets
  its own format unconditionally — which also means `->seconds(false)`
  alone does not remove the seconds.

  This supersedes the earlier decision to keep a Gregorian picker and format
  only on read with `morilog/jalali`'s `jdate()`. That was a scoping call
  pending "a v4-compatible package worth vetting"; this is that package.
  **`morilog/jalali` has been removed** — nothing referenced it once the
  macros replaced the hand-written `Jalalian::fromDateTime(...)->format(...)`
  calls, and leaving two Jalali libraries installed invites someone to format
  a date with the one that ignores `config/filament-jalali.php`.

  There are no date *filters* on any table today, so there is nowhere the
  `DateConstraint` macro is currently needed — use it if one is added.
- کدملی (national ID, 10 digits) gets a dedicated Laravel validation rule
  (`App\Rules\IranianNationalId`) implementing the standard Iranian
  checksum, not just a digit-count/regex check.
- شناسه ملی (company national ID, 11 digits) deliberately does **not**. It
  is validated as `digits:11` + `unique` and nothing more. The
  commonly-published company checksum rejected real, currently-issued IDs
  and locked legitimate companies out of registering, so the rule class was
  removed rather than left to fail closed on valid input. Both the public
  registration form and the admin `UserForm` share this relaxed rule, and
  `RegistrationTest` pins the behaviour with an 11-digit value that fails
  the old checksum.
- Mobile numbers are stored in local format (`09XXXXXXXXX`, unique,
  validated against the standard Iranian mobile regex) and converted to
  E.164 (`+98XXXXXXXXXX`) only at the SMS-gateway boundary, per the msgway
  doc's required format.

## مناقصات (Bids/Tenders) module

- `Bid` — title, `description` (HTML from Filament's `RichEditor`, which
  supports uploading and inserting images inline out of the box — no
  separate "upload then insert" step, satisfying the requirement directly),
  `start_at`, `expire_at`, `created_by`.
- `BidAttachment` — one row per uploaded file, `hasMany` on `Bid`. Filament's
  multi-file upload component accepts an explicit allow-list of extensions/
  mime types covering PDF, Word/Excel/PowerPoint (both legacy and OOXML),
  all common image types, all common video types, and mp3.
- Visibility scope for the `user` role: `Bid::active()` — a query scope for
  `start_at <= now() AND expire_at > now()` — applied to the resource/table
  query for that role only; admin/staff see everything (including
  scheduled/expired) so they can manage the full lifecycle.
- `BidGoodRequirement` — the «کالاهای مورد نیاز» rows, edited as a
  `Repeater` bound to the `goodRequirements` relationship at the bottom of the
  same create/edit form, so a tender and its goods are defined in one pass
  (Filament writes/updates/deletes the rows itself — the page classes only
  still hand-handle attachments, which predate this).
- The create/edit form declares **`->columns(1)` at the top level** so its two
  Sections («اطلاعات مناقصه» and «کالاهای مورد نیاز») stack vertically. That
  line is load-bearing and easy to delete by accident: a Filament resource
  form schema defaults to *two* columns, which put the two Sections side by
  side and squeezed both the rich-text editor and the goods table into half
  the page. The inner `Section` keeps its own `->columns(2)`.
- Two read-only record actions on the bids table, visible to **every** role:
  an eye icon (title / description / start / end) and a clipboard icon (the
  requirement rows). Both are built from **infolist entries**, not Blade
  views — see [Panel CSS](#panel-css-has-no-tailwind-utilities).
- `BidSuggestion` — **scaffold only**, per the explicit requirement: a
  table + a "ارسال پیشنهاد" button on the tenders list that opens a
  Filament wizard-modal action collecting a single free-text field for now.
  A unique DB constraint on `(bid_id, user_id)` enforces "one suggestion per
  tender" at the data layer even before the full business flow exists.

## کالاها (Goods) module

An admin/staff-only catalogue (`GoodPolicy` gates the whole resource; the
`user` role never sees the menu item) that tenders draw from.

- `Good` — `code` (کد کالا, unique), `name` (شرح کالا), `specifications`
  (ابعاد و مشخصات فنی). **No unit-of-measure column** — quantities are plain
  integer counts, an explicit product decision.
- `GoodDrawing` — نقشه files, `hasMany` on `Good`, PDF/images only. Same
  upload-in-the-form + list-in-a-relation-manager pattern as
  `BidAttachment`, on purpose.
- `Good::getPickerLabelAttribute()` renders «شرح کالا (کد کالا)» and
  `Good::scopeSearch()` matches either half, so one searchable `Select` in
  the bid repeater serves both "I know the name" and "I know the code". The
  picker preloads 50 goods and searches beyond that.

**Deleting a good that a tender already cites is refused.** Three layers,
in the order the operator meets them:

1. `GoodsTable`'s delete action `before()` hook halts with a Persian
   notification naming the offending tenders.
2. `bid_good_requirements.good_id` is `restrictOnDelete` — the DB refuses it
   regardless of which code path tries.
3. `GoodPolicy::delete()` deliberately does **not** encode the in-use check.
   A `false` there would silently hide the delete button and leave the
   operator guessing why; the explanation matters more than the tidiness.

There is no bulk delete on the کالاها table for the same reason — a bulk
action can only report one outcome for a mixed selection.

## Typography: Vazirmatn, self-hosted, no external requests

The app loads **no third-party resources at all** — no Google Fonts, no
Bunny Fonts, no CDN. This is a hard constraint, not a preference: the site
must render identically for a visitor whose network cannot reach those
hosts, and font requests to a third party leak visitor IPs on every page.

One family everywhere: **Vazirmatn v33.0.3** (Saber Rastikerdar, SIL OFL
1.1 — the maintained continuation of the older "Vazir"). `OFL.txt` is
committed next to the font files.

**Tahoma is gone and must not come back.** It was previously the first
entry in the standalone layout's inline `font-family`. Tahoma is a
proprietary Microsoft font, absent on Linux and Android, so it produced a
different — and noticeably worse — Persian rendering per platform, while
also shadowing the webfont on the machines that did have it.

Layout of the pieces:

```
public/fonts/vazirmatn/Vazirmatn-Variable.woff2   ← 111 KB, weights 100–900
public/fonts/vazirmatn/Vazirmatn-{Regular,Medium,SemiBold,Bold}.woff2
public/fonts/vazirmatn/OFL.txt
public/css/vazirmatn.css                          ← the @font-face rules
```

`public/css/vazirmatn.css` is hand-written, static, and deliberately **not**
processed by Vite. That is what lets both halves of the app link the same
single definition with a plain `<link>`:

- the panel, via `->font('Vazirmatn', url: asset('css/vazirmatn.css'),
  provider: LocalFontProvider::class, preload: [...])` in
  `AppPanelProvider`. `LocalFontProvider` is the key part — Filament's
  default provider is `BunnyFontProvider`, which would fetch from a CDN.

There used to be a second consumer: the standalone `/register` layout, which
linked the stylesheet directly and pointed Tailwind's `--font-sans` at the
family in `resources/css/app.css`. Registration is a panel page now, so that
layout is gone and the panel is the only consumer. `resources/css/app.css`
and the Vite build are kept for a possible future non-panel page but are
currently loaded by nothing.

The variable font is the primary face (one file, every weight); the four
static weights are only reached through `@supports not
(font-variation-settings: normal)`, so modern browsers never download them.

`vite.config.js` previously declared `bunny('Instrument Sans', ...)` via
`laravel-vite-plugin/fonts`; that was removed. **Do not add a `fonts:`
option back to the Vite config** — it emits CDN requests.

One known leftover: Filament's published
`public/js/filament/forms/components/markdown-editor.js` contains EasyMDE's
auto-loader for Font Awesome from `maxcdn.bootstrapcdn.com`. This app uses
`RichEditor`, never `MarkdownEditor`, so that asset is never loaded — but if
a Markdown field is ever added, this becomes a live external request and
needs handling.

## Panel CSS has no Tailwind utilities

The panel's compiled stylesheet (`public/css/filament/filament/app.css`)
contains **only Filament's own `fi-*` component classes** — Tailwind
utilities like `flex`, `text-sm`, `gap-4` or `prose` are not in it, because
no custom Filament theme is registered for this panel. (`resources/css/app.css`
is Tailwind, but it served the standalone `/register` page, which no longer
exists — nothing loads it today.)

Filament's own Blade components (`<x-filament::button>`, etc.) *are* styled
by that CSS, so they are fine to use in the rare places raw markup is needed
— the registration wizard's submit button is one, because
`Wizard::submitAction()` takes rendered HTML rather than an `Action` object.

Practical consequence, learned the expensive way while building the bid
detail/goods modals: **do not hand-write Blade with utility classes for
anything inside the panel** — it renders unstyled. Build modal bodies from
Filament's own schema components (`TextEntry`, `RepeatableEntry`, `Section`,
…) instead, which also gets dark mode and RTL for free. If genuinely custom
markup is ever needed, register a custom Filament theme first rather than
sprinkling inline styles.

Rich-text stored by the `RichEditor` is re-rendered through Filament's
`RichContentRenderer` rather than raw `{!! !!}`: it re-serialises the HTML
through Tiptap's allowed node/mark set, so anything outside the editor's own
vocabulary (scripts, event handlers) is dropped on the way out. There is a
test asserting exactly that.

## Deployment topology

Target: `https://sitra.ir`, a Virtualmin-managed Ubuntu 24.04 VPS
(162.55.167.140) already hosting ~60 other tenant sites. Verified read-only
before touching anything:

- Vhost docroot: `/mnt/ger_hd1/www/sitra/public_html` (Apache 2.4, MySQL
  8.0.46, Composer 2.7.2, Node 21/npm10 present).
- The vhost's actual PHP-FPM pool runs **PHP 8.4** (not 8.3 — the pool is
  identified by a numeric socket name, not a version-named file, so this
  needed direct verification) with `gd`, `bcmath`, `intl`, `zip`, `mysqli`,
  `pdo_mysql`, `mbstring`, `opcache` already enabled, no `open_basedir`, no
  `disable_functions`, and default `.user.ini` support enabled — i.e. the
  FPM pool's conservative `memory_limit=128M` / `upload_max_filesize=2M`
  can be raised from inside the docroot via a `.user.ini` file, no root
  needed.
- Apache's `<Directory>` block for the vhost has `AllowOverride All` and
  `Options ... +SymLinksIfOwnerMatch`, which would have allowed a
  no-root symlink-based docroot trick — **the user opted instead to change
  the Virtualmin docroot setting themselves** to point directly at
  `public_html/sitra/public`.
- Chosen layout: the Laravel app is deployed to
  `/mnt/ger_hd1/www/sitra/public_html/sitra/` (app root), with its `public/`
  subfolder at `/mnt/ger_hd1/www/sitra/public_html/sitra/public`. **The user
  will point Virtualmin's document root at that `public` path** — this
  replaces the placeholder `index.html` currently in `public_html`.
- No passwordless sudo for the `sitra` SSH user, and none is needed for any
  step above.
- Connecting from a Windows dev machine to this SSH host non-interactively
  works via `plink.exe -ssh -batch -pw '<password>' sitra@162.55.167.140
  '<command>'` (PuTTY's CLI client, already installed) — `sshpass` is not
  installed and wasn't needed.
- **Port 22 is now reachable directly** (re-verified 2026-08-15) — the dev
  IP was whitelisted, so the HTTP proxy on `127.0.0.1:10809` that the saved
  PuTTY session `Ger1` routes through is **no longer required**. Loading
  that session now *fails* ("Connection refused") whenever the local
  proxy/VPN client isn't running; connect directly instead.
- **How to authenticate:** there is no password or key for the `sitra` SSH
  user on the dev machine (`ssh sitra@...` → `Permission denied`). Use the
  root key instead — `~/.ssh/config` defines `Ger1-root`
  (`id_ed25519_ger1_root`), which works non-interactively. **Run every
  deploy command through `sudo -u sitra`** so nothing lands root-owned;
  after any file operation, `find $APP -not -user sitra -print -quit`
  should print nothing.

### Deploy pitfalls (both hit for real)

- **Never ship `bootstrap/cache/` to the server.** It is generated, and the
  local copy lists dev-only packages. `laravel-lang/lang` is in
  `require-dev`, so the server's `--no-dev` vendor has no
  `LaravelLang\Config\ServiceProvider` — shipping the local `packages.php`
  took the whole site to HTTP 500 on every route until those files were
  deleted. Exclude `bootstrap/cache` from the tarball. (`storage`, `.env`,
  `vendor`, `tests` are already excluded for the same class of reason.)
- **Deleting a class file needs `composer dump-autoload` on the server.**
  Removing `app/Rules/IranianCompanyNationalId.php` left a stale entry in
  the optimized classmap, so any `class_exists()` on it emitted
  `Failed to open stream` warnings. Fix:
  `sudo -u sitra composer dump-autoload --optimize --no-dev`. Note this
  clears the compiled views, so re-run `php artisan optimize` afterwards.

## Security notes

- Registration OTP: hashed at rest, short TTL, capped verify attempts,
  per-mobile/per-IP send rate limit.
- Admin-created accounts bypass OTP but still go through normal password
  hashing/validation.
- File uploads: extension + mime allow-list enforced server-side (not just
  the browser's `accept` attribute), size-capped, stored outside any
  publicly-listable directory index (`Options -Indexes` is already set on
  the vhost).
- `.env`, `storage/`, `bootstrap/cache/`, and the rest of the Laravel app
  stay outside `public/` by construction (standard Laravel layout) —
  nothing beyond `public/` is ever web-exposed regardless of which docroot
  approach is used.
