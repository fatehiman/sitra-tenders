# Database

MySQL 8. All dates/times stored as Gregorian (`datetime`); Jalali is a
display/input-layer concern only (see [ARCHITECTURE.md](ARCHITECTURE.md#calendar--localization)).
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
| `company_national_id` | string(11), nullable | شناسه ملی — required when `person_type = company`, own checksum algorithm |
| `password` | string (hashed) | |
| `mobile_verified_at` | timestamp, nullable | set on OTP success, or immediately for admin-created accounts |
| `created_by` | bigint FK → users.id, nullable | set when an admin creates the account directly (registration-flow accounts leave this null) |
| `is_active` | boolean, default true | lets admin disable an account without deleting it |
| `created_at` / `updated_at` | timestamp | |

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
| `verified_at` | timestamp, nullable | |
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

## `bid_suggestions` (پیشنهادات — scaffold only)

Per the explicit requirement, this is intentionally minimal for now: enough
to record that a suggestion was made and enforce "once per tender", not a
full suggestion-review workflow.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `bid_id` | bigint FK → bids.id | |
| `user_id` | bigint FK → users.id | |
| `note` | text, nullable | free-text field collected by the scaffold modal |
| `created_at` | timestamp | |

Unique constraint on `(bid_id, user_id)` — enforces "only once per tender"
at the data layer regardless of what the UI does.

## Standard Laravel tables (unchanged)

`password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`,
`job_batches`, `failed_jobs` — kept as Laravel's defaults; queue and cache
both run on the `database` driver (see [ARCHITECTURE.md](ARCHITECTURE.md#stack)
for why Redis was passed over despite being available on the box).

`personal_access_tokens` (Sanctum) is **not installed** — there's no API
consumer today; add it only if one shows up.
