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
- `/register` — a **custom, non-panel Livewire full-page route** (not a
  Filament resource/page) rendering the public registration form. It has to
  live outside the panel's auth middleware since the visitor isn't
  authenticated yet and Filament's own auth pages don't model a "send
  OTP → confirm in modal → create + login" flow.
- Panel home (`/`) resolves to the **مناقصات (Bid) list**, not Filament's
  default Dashboard, for every role — the Dashboard page is not registered.
- `/profile` (or similar) — the **change password** page, available to all
  three roles via the panel's user menu.

Navigation items and resource visibility are controlled per-role via
Filament resource `canViewAny()`/policies backed by
`auth()->user()->hasRole(...)`, not by hiding/showing whole panels.

## Registration + OTP flow

Single page, no wizard/page navigation, per the explicit requirement:

1. Visitor fills the whole form (name, family, mobile, national ID, person
   type, [company name + شناسه ملی if حقوقی], password + confirmation) in
   one Livewire component.
2. Clicking **"ارسال کد تایید"** validates the entire form client- and
   server-side first (so we never send an OTP for a form that can't
   register anyway), then:
   - generates a 6-digit numeric code,
   - stores it **hashed** with a short TTL and an attempt counter in
     `otp_verifications` (keyed by mobile — no `users` row exists yet),
   - sends it through the SMS gateway (msgway built-in Persian template
     `templateID=3`, `کد تایید شما: [code]` — no panel registration needed),
   - opens a modal asking for the 6-digit code, **without a page
     transition** (same Livewire component, just a `showOtpModal = true`
     state flip).
3. On submit, the code is checked against the hash (rate-limited attempts,
   TTL-checked). On success, inside one DB transaction: create the `users`
   row from the already-validated form data, assign the `user` role, mark
   `mobile_verified_at`, delete the OTP row, `Auth::login()`, redirect to
   the tenders list.
4. On failure, the modal shows the error and lets the visitor retry or
   request a new code (basic resend cooldown, e.g. 60s) — no partial user
   record ever exists on failed/abandoned attempts.

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
something any code change can route around. Until it clears, no SMS will
deliver even though `SMS_DRIVER=msgway` is correct and live. `SentSmsLog`
records the failure with its `traceID` for the support ticket.

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
  never Jalali in the DB.** Jalali/Shamsi is a **display-only** concern:
  Filament's standard Gregorian `DateTimePicker` is used for input (tender
  start/expire) to avoid depending on a custom picker component of
  uncertain Filament v4 compatibility, while every read-only rendering of a
  date (table columns, detail views) is formatted as Jalali via
  `morilog/jalali`'s `jdate()`. This keeps all date arithmetic
  (`now()->between(...)`) trivially correct and is a deliberate scoping
  call, not something the user asked for explicitly — a Jalali-aware input
  picker can replace the Gregorian one later if it matters enough to
  justify vetting a v4-compatible package.
- کدملی (national ID, 10 digits) and شناسه ملی (company national ID, 11
  digits) get dedicated Laravel validation rules implementing the standard
  Iranian checksum algorithms, not just a digit-count/regex check.
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

## Panel CSS has no Tailwind utilities

The panel's compiled stylesheet (`public/css/filament/filament/app.css`)
contains **only Filament's own `fi-*` component classes** — Tailwind
utilities like `flex`, `text-sm`, `gap-4` or `prose` are not in it, because
no custom Filament theme is registered for this panel. (`resources/css/app.css`
is Tailwind, but it only serves the non-panel pages: `/register` and the
standalone layout.)

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
