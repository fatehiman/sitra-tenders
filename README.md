# سامانه الکترونیکی مدیریت استعلام پیشنهادات تامین کنندگان

A Persian-only, RTL-only tender/bid management platform built on Laravel +
Filament. Companies and individuals register with mobile OTP verification,
browse and respond to open tenders (مناقصات), and staff/admins publish and
manage tenders from the same panel.

This is the project's entry-point doc. See also:

- [ARCHITECTURE.md](ARCHITECTURE.md) — stack, key decisions, module design, deployment topology
- [PLAN.md](PLAN.md) — phased implementation roadmap and status
- [DATABASE.md](DATABASE.md) — schema reference

## Feature summary

1. **Public registration** — a three-step wizard at `/register`:
   (1) enter the mobile number and receive a 6-digit SMS OTP, (2) confirm the
   code, (3) fill in name, family, national ID (کدملی), person type
   (حقیقی/حقوقی؛ حقوقی adds company name + شناسه ملی — validated as any
   unique 11-digit number, no checksum) and a password. Verifying the number
   first means no SMS is paid for on a number that can't register, and nobody
   fills in eight fields before finding out the code can't be delivered.
   The visitor has 10 minutes after confirming the code to finish; past that
   they go back to step 1. On success the account is created and the user is
   logged straight into the panel. If `company_name` is set, the account is
   treated/displayed as a company account everywhere in the app; otherwise
   the person's name + family is displayed.
2. **Admin** can list/manage all registered users, and can create users or
   staff directly (no OTP required for admin-created accounts). Three roles
   exist today — `admin`, `staff`, `user` — modeled so a fourth role can be
   added later without schema changes.
3. **Project title**: «سامانه الکترونیکی مدیریت استعلام پیشنهادات تامین
   کنندگان» — the full title is `APP_NAME`, used for the browser tab and the
   login/registration headings. The sidebar brand is the short «سامانه مدیریت
   استعلام», because 55 characters would wrap onto three lines in a 250px
   sidebar.
4. **Change password** page available to every authenticated role — last
   item in the sidebar. Locked out entirely? «فراموشی رمز عبور» on the login
   page is a three-step wizard: mobile number → SMS code → new password twice,
   then straight into the panel. There is no email anywhere in this app, so
   Filament's email-link reset is replaced outright.
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
   Every tender row carries an eye icon (title, description, **attachments**,
   start/end in a modal) and a clipboard icon (its goods requirements, with
   نقشه download links).
9. **پیشنهادها (bids/offers)** — a user submits **one per tender**, built in
   a seven-step wizard at `/bids/{id}/suggest`:
   1. **شرایط مناقصه** — the tender's title, description and attachments
      (the same content the «مشاهده» eye icon on مناقصات shows), plus a
      checkbox — «شرایط مناقصه را خواندم و موافق هستم» — that must be ticked
      before continuing.
   2. **پرداخت** — the tender's ودیعه (bid-guarantee deposit) amount is
      shown at the top, then one of three payment methods: **پرداخت
      الکترونیک** (a placeholder link today — no real gateway yet, so
      picking it does not block moving on), **بارگذاری ضمانت‌نامه بانکی**
      (a mandatory PDF/Word/image upload), or **نامه کسر از مطالبات** (a
      fill-in-the-blank version of the official letter, with an optional
      attachment).
   3. **مشخصات فنی کالاها** — the same goods table as the next step, but
      with a **مشخصات فنی قابل تامین** box per good instead of a price.
      Leaving it empty is the normal answer — the placeholder says «مشخصات
      کارفرما را میپذیرم», i.e. "I accept the employer's specification";
      typing something means "I can supply it, to these specifications
      instead". No جمع column and no total: this step is only about
      specifications, which is also why the price step no longer repeats the
      employer's مشخصات فنی column.
   4. **قیمت کالاها** — every «کالای مورد نیاز» of the tender as a table
      row, with a قیمت واحد box in **whole ریال**. The line total (price ×
      requested quantity) and the جمع کل at the bottom update as you leave
      each box. A good you leave empty is simply one you are not supplying.
   5. **توضیحات و پیوست‌ها** — free text plus up to **10** files
      (PDF/Office/images/video/audio).
   6. **تایید نهایی** — your mobile number and what happens next; pressing
      «بعدی» is what texts the code.
   7. **کد تایید** — enter it, submit, and the bid is final. You get an
      8-digit **«کد پیگیری»**, also shown on the tender row and in
      «مشاهده پیشنهاد».

   Around that:
   - **every step saves a draft on the server** — prices, text and files
     all survive closing the browser. The row button reads «ادامه پیش‌نویس»
     when you have one, and there is a «ذخیره پیش‌نویس» button on every
     step, next to a «حذف پیش‌نویس» button that discards the draft outright
     (no confirmation — a draft was never submitted) and returns to
     مناقصات. A draft is not a bid: staff cannot see it and it does not
     lock the tender;
   - the tenders table shows the user their «ارسال پیشنهاد» date-time (a
     dash until they bid), «کد پیگیری», «مبلغ پیشنهاد» and «وضعیت پیشنهاد»:
     ارسال نشده → پیش‌نویس → ارسال شده → دردست بررسی once the tender
     closes → فرم الف → فرم ب → تایید شده / رد شده. The last four are driven
     by the admin's two-envelope review (item 10 below); see
     `App\Enums\SuggestionStatus`;
   - a read-only «مشاهده پیشنهاد» action re-opens a submitted bid with its
     priced goods, totals and files;
   - **«انصراف از پیشنهاد»** lets the bidder delete their own bid outright
     — files included — but only **before** the tender's deadline. Editing
     a submitted bid is never possible;
   - **a tender with a live bid is locked** — nobody, admin included, can
     edit or delete it, so the terms cannot change under a submitted offer;
   - **«لغو» (admin only)** cancels bids on a tender: it unlocks it and
     lets those users bid again. Unlike «انصراف» it keeps the row, with who
     cancelled it, when and why.

10. **The admin's two-envelope review (پاکت الف / پاکت ب)** — once a tender
    has expired, the letter icon on its row walks an admin through the offers
    one at a time:
    - **بازکردن پاکت الف** (closed letter) — every offer, **with no prices
      anywhere**: goods, the «مشخصات فنی قابل تامین» answers (a ⚠ icon marks
      each good whose specification the bidder changed, with the employer's
      original in the tooltip), «توضیحات و پیوست‌ها», payment method and ودیعه
      details. Green «تایید» / red «رد» records the verdict and moves on;
      «قبلی»/«بعدی» let the admin re-read and change their mind.
    - **بازکردن پاکت ب** (the same closed letter, now orange) — only the
      offers approved in الف, this time **with** unit prices, line totals and
      جمع کل.
    - Both stages end on a review list plus a «ثبت نهایی» button behind an
      "I understand this cannot be undone" checkbox. **Nothing the admin
      clicks is final until that button**: the verdicts are saved as they are
      clicked (so a long review survives closing the browser) but no bidder's
      status changes and no SMS is sent before it.
    - Finalising الف moves approved offers to «فرم الف» and declined ones to
      «رد شده». Finalising ب makes approved offers «تایید شده» — the winners —
      and texts **every** bidder the result: template 23572 to winners, 23573
      to everyone else (including those rejected back in الف, who were told
      nothing until the tender had an outcome).
    - **The admin never sees whose offer they are reading.** Every screen
      shows «مخفی شده» instead of the bidder's name, company name or mobile —
      the «پیشنهادهای دریافتی» modal and the «لغو» list included, and «مبلغ
      کل» stays hidden there until الف is finalised. Only winners are
      unmasked, on **تخته برندگان**: the open-letter grey icon that replaces
      the other two once ب is done, showing each winner's name, company,
      mobile, کد ملی / شناسه ملی, «کد پیگیری» and amount. Admin-only, not
      staff.

## Tech stack

- **Laravel** (latest stable at implementation time) + **Filament** (latest
  stable v4) — single shared panel for all three roles, resources gated by
  policies/roles instead of separate panels.
- **spatie/laravel-permission** for roles (admin/staff/user, extensible).
- **MySQL 8** for storage, local disk for file attachments.
- **Tehran time** (`Asia/Tehran`), not UTC — every timestamp the app writes
  or reads is Iran wall-clock. See
  [ARCHITECTURE.md](ARCHITECTURE.md#timezone-asiatehran).
- Persian-only UI, RTL layout, **Jalali (Shamsi) everywhere the user looks** —
  date pickers, table columns and detail views — on top of Gregorian storage,
  via `ariaieboy/filament-jalali`. Digits stay Latin (`1405/05/24`) to match
  every other number in the panel. See
  [ARCHITECTURE.md](ARCHITECTURE.md#calendar--localization).
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
