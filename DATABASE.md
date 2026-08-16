# Database

MySQL 8. All dates/times stored as Gregorian (`datetime`); Jalali is a
display/input-layer concern only (see [ARCHITECTURE.md](ARCHITECTURE.md#calendar--localization)).
The values written are **Tehran wall-clock time** — `config('app.timezone')`
is `Asia/Tehran`, not UTC (see
[ARCHITECTURE.md](ARCHITECTURE.md#timezone-asiatehran)). MySQL `datetime`
stores no timezone either way, so this is a change in what the numbers mean,
not in the column types.
This reflects the schema as currently planned/implemented — update this file
whenever a migration changes it.

## `users`

No email column — login is by mobile number, not email (see
[ARCHITECTURE.md](ARCHITECTURE.md#panel-structure)).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `first_name` | string | نام |
| `last_name` | string | نام خانوادگی |
| `mobile` | string, unique | local format `09XXXXXXXXX`; converted to E.164 only at the SMS boundary |
| `national_id` | string(10), unique | کدملی — validated with the Iranian checksum algorithm, not just digit count |
| `person_type` | enum(`individual`,`company`) | حقیقی / حقوقی |
| `company_name` | string, nullable | required (and only settable) when `person_type = company`; presence drives display everywhere — see accessor in ARCHITECTURE.md |
| `company_national_id` | string(11), nullable | شناسه ملی — required (and unique) when `person_type = company`. **Format-only validation: any 11-digit number is accepted, no checksum** — the published company checksum rejects real IDs. See PLAN.md's open items. |
| `password` | string (hashed) | |
| `mobile_verified_at` | timestamp, nullable | set on OTP success, or immediately for admin-created accounts |
| `created_by` | bigint FK → users.id, nullable | set when an admin creates the account directly (registration-flow accounts leave this null) |
| `is_active` | boolean, default true | lets admin disable an account without deleting it |
| `created_at` / `updated_at` | timestamp | |

**There is no soft delete on this table.** Deleting an account from کاربران
is a real `DELETE`, and the foreign keys decide what goes with it:
`bid_suggestions.user_id` cascades (their پیشنهادها, price lines and
attachment rows), while `users.created_by`, `goods.created_by` and
`bid_suggestions.cancelled_by` are all `nullOnDelete` (those rows survive,
just orphaned). `bids.created_by` cascades too, which is exactly why an
account that published tenders may **not** be deleted — see
[ARCHITECTURE.md](ARCHITECTURE.md#deleting-a-user-account).

Roles (`admin` / `staff` / `user`, extensible) live in spatie/laravel-permission's
own tables (`roles`, `permissions`, `model_has_roles`,
`model_has_permissions`, `role_has_permissions`) — not a column on `users`,
so adding a fourth role later is a data change only.

## `otp_verifications`

Registration OTP challenges. Keyed by mobile, not `user_id` — the `users`
row doesn't exist yet when the code is sent.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `mobile` | string, indexed | |
| `code_hash` | string | never store the plaintext code |
| `attempts` | unsigned tinyint, default 0 | capped (e.g. 5) before the code is invalidated |
| `expires_at` | timestamp | short TTL (e.g. 2 minutes) |
| `verified_at` | timestamp, nullable | **Load-bearing, not just an audit field.** It is what the registration wizard's final submit checks: the account is only created if a row for that exact mobile is stamped here within the last 10 minutes (`OtpService::verifiedWithin()`). The wizard's own step state lives in the browser and is not trusted — see [ARCHITECTURE.md](ARCHITECTURE.md#the-ten-minute-window-and-what-is-actually-trusted) |
| `ip_address` | string, nullable | for rate limiting / abuse review |
| `created_at` | timestamp | |

Rows are deleted (or left to expire and get pruned) once consumed; not a
long-term audit log.

## `sent_sms_log`

Per the msgway integration doc's own operational recommendation — most
delivery incidents get diagnosed from this table plus the provider's
`traceID`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `mobile` | string | |
| `purpose` | string | e.g. `otp_registration` |
| `provider` | string | e.g. `msgway` |
| `template` | string | semantic template name, e.g. `otp` |
| `status` | enum(`sent`,`failed`) | based on the provider's `status` field, not HTTP status |
| `reference_id` | string, nullable | provider's `referenceID` (success) |
| `error_code` | string, nullable | |
| `error_message` | string, nullable | |
| `trace_id` | string, nullable | provider's `traceID` (failure) — always logged, per the doc |
| `created_at` | timestamp | |

## `bids` (مناقصات)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `title` | string | |
| `description` | longtext | HTML from Filament's RichEditor (inline image upload supported natively) |
| `start_at` | datetime | |
| `expire_at` | datetime | |
| `created_by` | bigint FK → users.id | admin or staff who published it |
| `created_at` / `updated_at` | timestamp | |

Query scope `active()`: `start_at <= now() AND expire_at > now()` — applied
to the `user`-role view only; admin/staff see the full lifecycle
(scheduled, active, expired).

## `bid_attachments`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `bid_id` | bigint FK → bids.id, cascade delete | |
| `disk` | string | e.g. `public` |
| `path` | string | storage path |
| `original_name` | string | original upload filename |
| `mime_type` | string | server-verified, not trusted from the client |
| `size` | unsigned int | bytes |
| `created_at` | timestamp | |

Allowed types: PDF, Word/Excel/PowerPoint (legacy `.doc/.xls/.ppt` and OOXML
`.docx/.xlsx/.pptx`), all common image formats, all common video formats,
and `.mp3`.

## `goods` (کالاها)

The catalogue admin/staff maintain and then cite from tenders. There is
deliberately **no unit-of-measure column** — quantities are plain integer
counts (explicit product decision; adding a واحد later is a migration plus a
form field, nothing structural).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `code` | string(64), unique | کد کالا — free-form, not numeric-only |
| `name` | string, indexed | شرح کالا (the Persian name) |
| `specifications` | text | ابعاد و مشخصات فنی |
| `created_by` | bigint FK → users.id, nullable, null-on-delete | who catalogued it |
| `created_at` / `updated_at` | timestamp | |

`name` is indexed because the bid form's picker searches it alongside `code`
(which already has a unique index) — see `Good::scopeSearch()`.

## `good_drawings` (نقشه)

Same shape as [`bid_attachments`](#bid_attachments), deliberately, so both use
the identical upload-then-list pattern. Allowed types are narrower: **PDF and
images only**.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `good_id` | bigint FK → goods.id, cascade delete | |
| `disk` | string | e.g. `public` |
| `path` | string | storage path |
| `original_name` | string | original upload filename |
| `mime_type` | string | server-verified, not trusted from the client |
| `size` | unsigned bigint | bytes |
| `created_at` | timestamp | |

## `bid_good_requirements` (کالاهای مورد نیاز مناقصه)

The rows of «we need N of good X» defined at the bottom of the bid
create/edit form.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `bid_id` | bigint FK → bids.id, **cascade** delete | deleting a tender drops its requirement rows |
| `good_id` | bigint FK → goods.id, **restrict** delete | a good cited by a tender cannot be deleted |
| `quantity` | unsigned int | integer count, min 1 |
| `created_at` / `updated_at` | timestamp | |

Unique constraint on `(bid_id, good_id)` — the same good can't be listed
twice on one tender, enforced at the data layer as well as in the repeater
(`->distinct()`).

The `restrictOnDelete` is the backstop, not the user-facing behaviour: the
کالاها table's delete action checks first and halts with a Persian message
naming the tenders that use the good (see
[ARCHITECTURE.md](ARCHITECTURE.md#کالاها-goods-module)).

## `bid_suggestions` (پیشنهادات)

A user's priced offer on a tender, built up over the five-step wizard (see
[ARCHITECTURE.md](ARCHITECTURE.md#پیشنهاد-bidoffer-lifecycle)). The row is
created as a **draft** the moment the wizard is opened and is rewritten on
every step; finalising it stamps `submitted_at` and `tracking_code`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `bid_id` | bigint FK → bids.id | |
| `user_id` | bigint FK → users.id | |
| `note` | text, nullable | «متن پیشنهاد» — step 2's free text |
| `total_price` | unsigned bigint, nullable | the bid price in **whole ریال**: the sum of `bid_suggestion_items.total_price`. Stored rather than summed on read because the tenders table and the «پیشنهادهای دریافتی» modal both show it per row. Only ever written by `BidSuggestion::recalculateTotal()`. `unsignedBigInteger`, not `integer`, because a signed int overflows at ~2.1 billion ریال — well inside the range of a real tender |
| `tracking_code` | varchar(8), nullable, **unique** | the «کد پیگیری» shown to the user. Issued **only** at finalisation, so "has a code" and "was finalised" are the same question. Stored as a string: leading zeros are part of it |
| `status` | varchar(20), indexed, default `submitted` | `App\Enums\SuggestionStatus`: `draft`, `submitted`, `form_a`, `form_b`, `approved`, `rejected`, `cancelled`. A string, not a MySQL ENUM, so adding a review step later is a code change |
| `submitted_at` | timestamp, nullable | when the user finalised — **not** `created_at`, because the row exists as a draft first and is reused if a cancelled bid is re-sent |
| `otp_verified_at` | timestamp, nullable | when the SMS challenge that finalised this bid was passed. Audit only — the challenge itself is checked against `otp_verifications` at submit time |
| `cancelled_at` | timestamp, nullable | set by the admin's «لغو» action |
| `cancelled_by` | bigint FK → users.id, nullable, nullOnDelete | which admin cancelled it |
| `cancel_reason` | varchar(500), nullable | optional free text from the cancel modal |
| `created_at` | timestamp | row creation; no `updated_at` |

Unique constraint on `(bid_id, user_id)` — enforces "only once per tender"
at the data layer regardless of what the UI does. **This is why re-bidding
does not create a second row:** a user bidding again on a tender whose
previous bid was cancelled reuses the same row (`BidSuggestion::startDraft()`
turns it back into a draft), which overwrites the previous cancellation's
who/when/why. If a full audit trail is ever required, add a history table —
do not drop this index.

Three rules read this table rather than storing their answer:

- **«ارسال نشده» is the absence of a row** (or a cancelled one), not a
  status value.
- **«دردست بررسی» is `submitted` + the tender's `expire_at` in the past** —
  derived on read for the same reason `bids` has no `status` column.
- **A draft is not a bid.** `draft` and `cancelled` are both excluded from
  `BidSuggestion::scopeActive()`, so a draft neither locks its tender nor
  appears to staff. Were it otherwise, any user could freeze any tender
  indefinitely just by opening the wizard and walking away.

### `bid_suggestion_items`

One priced line: "for requirement row X, I quote Y ریال each".

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `bid_suggestion_id` | bigint FK → bid_suggestions.id, cascade delete | |
| `bid_good_requirement_id` | bigint FK → bid_good_requirements.id, **cascade** delete | points at the tender's requirement, **not** at the good: the quantity being priced belongs to the requirement, and the same good can be required by many tenders at different quantities |
| `unit_price` | unsigned bigint | whole ریال per unit |
| `total_price` | unsigned bigint | `unit_price` × the requirement's quantity, **stored, not computed on read** — it freezes what the user actually saw and agreed to, instead of silently re-pricing their offer if staff change the requested quantity later |
| `created_at` / `updated_at` | timestamp | |

Unique constraint on `(bid_suggestion_id, bid_good_requirement_id)`.

**A good the user does not want to supply has NO ROW** — not a zero and not
a null price. "Priced" and "not priced" is the presence or absence of a row,
the same "absence is the state" idea «ارسال نشده» uses above.

### `bid_suggestion_attachments`

Deliberately the same shape as [`bid_attachments`](#bid_attachments) and
[`good_drawings`](#good_drawings-نقشه), so all three use the identical
upload-then-list pattern.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `bid_suggestion_id` | bigint FK → bid_suggestions.id, cascade delete | |
| `type` | varchar(20), indexed, default `document` | `App\Enums\SuggestionAttachmentType`: `document` (step 2's پیوست‌ها, max 10) or `payment_receipt` (step 3's رسید پرداخت / ضمانت‌نامه بانکی). One table rather than two because everything else about them is identical |
| `disk` | string | e.g. `public` |
| `path` | string | storage path |
| `original_name` | string | original upload filename |
| `mime_type` | string | server-verified, not trusted from the client |
| `size` | unsigned bigint | bytes |
| `created_at` | timestamp | |

Allowed types differ by `type`: documents take the same list as
`bid_attachments` (shared as `BidForm::ACCEPTED_ATTACHMENT_TYPES`), receipts
take **PDF and images only**.

Unlike the other two attachment tables, these rows are **reconciled**, not
just appended: a draft is re-saved many times and the user can add and
remove files in between. `BidSuggestionAttachment::sync()` does that, and it
deletes the file from disk as well as the row so abandoned drafts do not fill
the disk. The DB cascade cannot do that part — it fires no model events —
which is also why `BidSuggestion::purge()` deletes files explicitly.

## Standard Laravel tables (unchanged)

`password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`,
`job_batches`, `failed_jobs` — kept as Laravel's defaults; queue and cache
both run on the `database` driver (see [ARCHITECTURE.md](ARCHITECTURE.md#stack)
for why Redis was passed over despite being available on the box).

`personal_access_tokens` (Sanctum) is **not installed** — there's no API
consumer today; add it only if one shows up.
