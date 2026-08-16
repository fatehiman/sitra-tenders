<?php

namespace App\Filament\Resources\Bids\Pages;

use App\Enums\RoleName;
use App\Enums\SuggestionAttachmentType;
use App\Exceptions\OtpThrottledException;
use App\Filament\Resources\Bids\BidResource;
use App\Filament\Resources\Bids\Schemas\BidForm;
use App\Models\Bid;
use App\Models\BidGoodRequirement;
use App\Models\BidSuggestion;
use App\Models\BidSuggestionAttachment;
use App\Models\User;
use App\Services\OtpService;
use App\Sms\SmsResult;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «ارسال پیشنهاد» — the five-step wizard a user builds their bid in.
 *
 * ---------------------------------------------------------------------------
 * Why this is a full page and not a modal
 * ---------------------------------------------------------------------------
 * The bid used to be a one-field modal on the مناقصات table. It now carries a
 * price for every good the tender asks for, ten attachments, a payment
 * receipt and an SMS confirmation — none of which fits in a dialog, and none
 * of which can be saved as a draft from one, because closing a modal throws
 * its state away. A page has a URL, so a half-finished bid is somewhere the
 * user can come back to.
 *
 * ---------------------------------------------------------------------------
 * The steps
 * ---------------------------------------------------------------------------
 *   1 «قیمت کالاها»      — every «کالای مورد نیاز» of the tender, one row
 *                          each, with a ریال box for the unit price. The line
 *                          total (price × requested quantity) and the grand
 *                          total update as soon as a box loses focus. Leaving
 *                          a box empty means "I am not supplying this good".
 *   2 «توضیحات و پیوست‌ها» — free text, plus up to ten supporting files.
 *   3 «رسید پرداخت»      — the رسید پرداخت / ضمانت‌نامه بانکی.
 *   4 «تایید نهایی»      — shows the account's mobile number and explains
 *                          what happens next. Pressing «بعدی» is what sends
 *                          the SMS, so the code is only spent when the user
 *                          says they have the phone in hand.
 *   5 «کد تایید»         — type the code; submitting finalises the bid and
 *                          issues the 8-digit «کد پیگیری».
 *
 * ---------------------------------------------------------------------------
 * Drafts: the server is the state, not the browser
 * ---------------------------------------------------------------------------
 * Every step transition — and the «ذخیره پیش‌نویس» button in the header —
 * writes the whole form to the database (a `bid_suggestions` row with status
 * «پیش‌نویس», plus its items and attachment rows). Re-opening the page
 * re-fills the wizard from those rows. Nothing depends on the browser
 * keeping anything.
 *
 * A draft is deliberately NOT a bid: staff cannot see it, and it does not
 * lock the tender. See App\Enums\SuggestionStatus::isActive().
 *
 * ---------------------------------------------------------------------------
 * A note on trust
 * ---------------------------------------------------------------------------
 * Livewire state round-trips through the browser, so nothing that arrives
 * from it is believed on its own:
 *   - quantities are re-read from `bid_good_requirements` on every save, so a
 *     tampered quantity cannot inflate a line total;
 *   - a priced row whose requirement does not belong to THIS tender is
 *     dropped;
 *   - the SMS code is checked against the hashed challenge in the database,
 *     not against anything the page remembers;
 *   - "am I allowed to be here at all" is re-answered from the database on
 *     every write, not just when the page was first opened.
 */
class SubmitSuggestion extends Page
{
    use InteractsWithRecord;

    protected static string $resource = BidResource::class;

    // The Blade file that draws the page — see resources/views/filament/.
    protected string $view = 'filament.resources.bids.pages.submit-suggestion';

    // Never a sidebar item: this page is only ever reached from a row button
    // on the مناقصات table, and it makes no sense without a tender.
    protected static bool $shouldRegisterNavigation = false;

    /** Holds the wizard's live values — see ->statePath('data') in form(). */
    public ?array $data = [];

    /**
     * Receipts are a scan or a photo of a piece of paper, so the list is
     * much narrower than step 2's: no video, no audio, no spreadsheets.
     */
    private const ACCEPTED_RECEIPT_TYPES = [
        'application/pdf',
        'image/*',
    ];

    /**
     * Per-request memo of the tender's requirement rows, keyed by id.
     *
     * The repeater asks for a good's code/name/quantity once per row per
     * render, and those all come from here rather than from the form state:
     * a quantity that arrives from the browser is a quantity someone could
     * have edited. Not a cache across requests — a fresh page instance is
     * built for every Livewire round trip.
     *
     * @var Collection<int, BidGoodRequirement>|null
     */
    private ?Collection $requirements = null;

    /** Per-request memo of the draft row. */
    private ?BidSuggestion $suggestion = null;

    /*
     * ---- Opening the page -------------------------------------------------
     */

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        // Anything that should stop this person bidding sends them back to
        // the list with an explanation, rather than a bare 403 — every one
        // of these is a normal situation (deadline passed, already bid), not
        // an attack.
        if ($refusal = $this->refusalReason()) {
            Notification::make()->title($refusal)->warning()->send();

            $this->redirect(BidResource::getUrl('index'));

            return;
        }

        $this->form->fill($this->buildFormState());
    }

    /**
     * Why this user may not build a bid on this tender right now — or null
     * if they may.
     *
     * Re-run before every write, not just on mount: the page can sit open
     * across the tender's deadline, and an admin can cancel or a second tab
     * can submit in the meantime.
     */
    private function refusalReason(): ?string
    {
        /** @var Bid $bid */
        $bid = $this->getRecord();

        if (! $this->currentUser()->hasRole(RoleName::User->value)) {
            // Staff and admins publish tenders; they do not bid on them.
            return 'ارسال پیشنهاد فقط برای کاربران امکان‌پذیر است.';
        }

        if (! $bid->isOpen()) {
            return 'مهلت ارسال پیشنهاد برای این مناقصه به پایان رسیده است.';
        }

        $existing = $bid->mySuggestion;

        if ($existing && $existing->status->isActive()) {
            return 'شما قبلاً برای این مناقصه پیشنهاد ثبت کرده‌اید.';
        }

        return null;
    }

    /** Stop a write dead if the situation changed since the page loaded. */
    private function guard(): void
    {
        if ($refusal = $this->refusalReason()) {
            Notification::make()->title($refusal)->danger()->send();

            $this->redirect(BidResource::getUrl('index'));

            // Halt is Filament's "stop this action, quietly" signal — it
            // aborts the save without an error page, which is right here
            // because the redirect above is already carrying the message.
            throw new Halt;
        }
    }

    private function currentUser(): User
    {
        return Auth::user();
    }

    /**
     * This user's draft row for this tender, created on first access.
     *
     * Created in mount() as a side effect of the first save rather than
     * eagerly, so merely LOOKING at the wizard and walking away does not
     * leave a row behind.
     */
    private function suggestion(): BidSuggestion
    {
        return $this->suggestion ??= BidSuggestion::startDraft($this->getRecord(), $this->currentUser());
    }

    /** @return Collection<int, BidGoodRequirement> */
    private function requirements(): Collection
    {
        return $this->requirements ??= $this->getRecord()
            ->goodRequirements()
            ->with('good')
            ->get()
            ->keyBy('id');
    }

    /** One requirement row by id, or null if it is not this tender's. */
    private function requirement(mixed $id): ?BidGoodRequirement
    {
        return $this->requirements()->get((int) $id);
    }

    /*
     * ---- The wizard -------------------------------------------------------
     */

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    $this->pricesStep(),
                    $this->detailsStep(),
                    $this->receiptStep(),
                    $this->confirmStep(),
                    $this->otpStep(),
                ])
                    // Rendered HTML rather than an Action object, because
                    // Wizard::submitAction() drops markup straight into the
                    // final step's button row. <x-filament::button> is one of
                    // Filament's own Blade components, so the panel's compiled
                    // CSS styles it — hand-written Tailwind would not be (see
                    // ARCHITECTURE.md, "Panel CSS has no Tailwind utilities").
                    ->submitAction(new HtmlString(Blade::render(
                        '<x-filament::button type="submit">ثبت نهایی پیشنهاد</x-filament::button>'
                    )))
                    // Must stay off: skippable() would let someone jump
                    // straight to the last step, and Wizard::nextStep() only
                    // runs a step's afterValidation() hook when it is NOT
                    // skippable — which is where the draft is saved and the
                    // SMS is sent.
                    ->skippable(false)
                    /*
                     * Enter means «بعدی», not «ثبت نهایی» — the identical fix
                     * the registration wizard needed, and for the identical
                     * reason: Enter in a text input submits the surrounding
                     * <form>, whose handler is the LAST step's action. Without
                     * this, typing a price and pressing Enter would try to
                     * finalise the bid. See App\Filament\Auth\Register.
                     */
                    ->extraAttributes([
                        'x-on:keydown.enter' => 'if (! isLastStep()) { $event.preventDefault(); requestNextStep() }',
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Step 1 — a price box against every good the tender asks for.
     */
    private function pricesStep(): Step
    {
        return Step::make('قیمت کالاها')
            ->description('برای هر کالا قیمت واحد را به ریال وارد کنید. کالایی که نمی‌خواهید تامین کنید را خالی بگذارید.')
            ->icon(Heroicon::OutlinedCurrencyDollar)
            ->schema([
                /*
                 * A Repeater with adding, deleting and reordering all turned
                 * off is doing something unusual: it is not a list the user
                 * builds, it is a FIXED grid of the tender's requirement rows
                 * with one editable cell each. The rows come from the
                 * database (see buildFormState()), and the user can only ever
                 * change the price column.
                 *
                 * ->table() lays each item out as a table row instead of a
                 * stacked card, which is what makes it read as a price list.
                 */
                Repeater::make('items')
                    ->hiddenLabel()
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->table([
                        TableColumn::make('کد کالا'),
                        TableColumn::make('شرح کالا'),
                        TableColumn::make('ابعاد و مشخصات فنی'),
                        TableColumn::make('تعداد'),
                        TableColumn::make('قیمت واحد (ریال)'),
                        TableColumn::make('جمع (ریال)'),
                    ])
                    ->schema([
                        /*
                         * Which requirement this row is about.
                         *
                         * It has to be a real field rather than the item's
                         * array key, because FILAMENT RE-KEYS REPEATER ITEMS:
                         * the keys buildFormState() sets are replaced with
                         * the repeater's own on hydration, so by the time a
                         * row comes back the key says nothing about which
                         * good it belongs to. Reading the key instead of this
                         * field silently attached prices to the wrong goods.
                         *
                         * It is still only ever used as a LOOKUP KEY —
                         * everything read through it (quantity, code, name)
                         * comes from the database, and a value that is not
                         * one of this tender's requirements is dropped.
                         *
                         * A Hidden component inside a table repeater does not
                         * consume a column — Filament skips it when matching
                         * components to headers.
                         */
                        Hidden::make('requirement_id'),
                        Placeholder::make('good_code')
                            ->hiddenLabel()
                            ->content(fn (Get $get): string => $this->requirement($get('requirement_id'))?->good?->code ?? '—'),
                        Placeholder::make('good_name')
                            ->hiddenLabel()
                            ->content(fn (Get $get): string => $this->requirement($get('requirement_id'))?->good?->name ?? '—'),
                        Placeholder::make('specifications')
                            ->hiddenLabel()
                            ->content(fn (Get $get): string => $this->requirement($get('requirement_id'))?->good?->specifications ?? '—'),
                        Placeholder::make('quantity')
                            ->hiddenLabel()
                            // number_format, not ->numeric(): the latter
                            // renders Persian digits (۱٬۰۰۰), which would
                            // clash with the Latin digits used everywhere
                            // else in the panel.
                            ->content(fn (Get $get): string => number_format(
                                $this->requirement($get('requirement_id'))?->quantity ?? 0
                            )),
                        TextInput::make('unit_price')
                            ->hiddenLabel()
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->step(1)
                            // Nothing in this wizard is ->required(): the
                            // whole point is that a half-filled form can be
                            // saved. What is actually needed to FINALISE is
                            // checked in assertReadyToFinalize() instead.
                            ->placeholder('—')
                            ->extraInputAttributes(['inputmode' => 'numeric'])
                            /*
                             * onBlur rather than on every keystroke: the line
                             * total and the grand total below both recompute
                             * on the server, so per-keystroke would be a
                             * Livewire round trip per digit typed.
                             */
                            ->live(onBlur: true),
                        Placeholder::make('row_total')
                            ->hiddenLabel()
                            ->content(fn (Get $get): string => number_format($this->lineTotal(
                                $get('requirement_id'),
                                $get('unit_price'),
                            ))),
                    ]),

                /*
                 * The bid price: the sum of every line above. Recomputed on
                 * the server from the same rules as the line totals, so the
                 * number the user reads here is the number that gets stored.
                 */
                Placeholder::make('grand_total')
                    ->label('جمع کل پیشنهاد (ریال)')
                    ->content(fn (Get $get): HtmlString => new HtmlString(
                        '<strong>'.number_format($this->grandTotal((array) $get('items'))).'</strong> ریال'
                    )),
            ])
            ->afterValidation(fn () => $this->saveDraft(notify: false));
    }

    /**
     * Step 2 — free text plus up to ten supporting files.
     */
    private function detailsStep(): Step
    {
        return Step::make('توضیحات و پیوست‌ها')
            ->description('توضیحات پیشنهاد و فایل‌های پشتیبان (حداکثر ۱۰ فایل).')
            ->icon(Heroicon::OutlinedDocumentText)
            ->schema([
                Textarea::make('note')
                    ->label('متن پیشنهاد')
                    ->rows(6)
                    ->maxLength(5000)
                    ->columnSpanFull(),
                FileUpload::make('documents')
                    ->label('پیوست‌ها')
                    ->helperText('PDF، Word، Excel، PowerPoint، تصویر، ویدیو و فایل صوتی mp3 — حداکثر ۱۰ فایل، هر فایل تا ۵۰ مگابایت.')
                    ->multiple()
                    ->maxFiles(BidSuggestion::MAX_DOCUMENTS)
                    ->disk('public')
                    ->directory('bid-suggestion-documents')
                    ->preserveFilenames()
                    // Enforced server-side, not just as the browser's
                    // "accept" hint — a hint is trivially bypassed. Shared
                    // with the tender's own upload field so the two can never
                    // drift apart.
                    ->acceptedFileTypes(BidForm::ACCEPTED_ATTACHMENT_TYPES)
                    ->maxSize(51200) // kilobytes, i.e. 50 MB per file
                    ->openable()
                    ->downloadable()
                    ->columnSpanFull(),
            ])
            ->afterValidation(fn () => $this->saveDraft(notify: false));
    }

    /**
     * Step 3 — proof of payment.
     */
    private function receiptStep(): Step
    {
        return Step::make('رسید پرداخت')
            ->description('رسید پرداخت یا ضمانت‌نامه بانکی خود را بارگذاری کنید.')
            ->icon(Heroicon::OutlinedBanknotes)
            ->schema([
                FileUpload::make('receipts')
                    ->label('رسید پرداخت یا ضمانت‌نامه بانکی')
                    ->helperText('PDF یا تصویر — حداکثر ۱۰ مگابایت برای هر فایل.')
                    ->multiple()
                    ->maxFiles(5)
                    ->disk('public')
                    ->directory('bid-suggestion-receipts')
                    ->preserveFilenames()
                    ->acceptedFileTypes(self::ACCEPTED_RECEIPT_TYPES)
                    ->maxSize(10240) // 10 MB
                    ->openable()
                    ->downloadable()
                    ->columnSpanFull(),
            ])
            ->afterValidation(fn () => $this->saveDraft(notify: false));
    }

    /**
     * Step 4 — "we are about to text you". No fields at all.
     *
     * The SMS is sent by this step's afterValidation(), i.e. when «بعدی» is
     * pressed, for the same reason the registration wizard sends it there:
     * every send costs money, and this is the exact moment the user has said
     * "yes, I am holding that phone".
     */
    private function confirmStep(): Step
    {
        return Step::make('تایید نهایی')
            ->description('پیش از ثبت نهایی، شماره موبایل خود را بررسی کنید.')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->schema([
                Placeholder::make('confirm_notice')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        'با فشردن دکمه «بعدی» یک کد تایید به شماره موبایل شما پیامک می‌شود. '
                        .'پس از وارد کردن کد و ثبت نهایی، پیشنهاد شما قطعی می‌شود و '
                        .'<strong>دیگر قابل ویرایش نخواهد بود</strong>. '
                        .'تنها تا پیش از پایان مهلت مناقصه می‌توانید آن را به‌طور کامل حذف کنید.'
                    ))
                    ->columnSpanFull(),
                Placeholder::make('mobile_notice')
                    ->label('شماره موبایل')
                    ->content(fn (): string => $this->currentUser()->mobile),
                Placeholder::make('total_notice')
                    ->label('جمع کل پیشنهاد (ریال)')
                    ->content(fn (Get $get): string => number_format($this->grandTotal((array) $get('items')))),
            ])
            ->afterValidation(function (): void {
                // Save first, so nothing typed is lost if the send fails and
                // the wizard stays put.
                $this->saveDraft(notify: false);

                // Everything the bid needs must be in place BEFORE money is
                // spent on an SMS.
                $this->assertReadyToFinalize();

                $this->issueOtp();
            });
    }

    /**
     * Step 5 — the code, and the submit button.
     */
    private function otpStep(): Step
    {
        return Step::make('کد تایید')
            ->description('کد ۶ رقمی پیامک‌شده را وارد کنید.')
            ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
            ->schema([
                /*
                 * THIS FIELD DELIBERATELY CARRIES NO VALIDATION RULES, and
                 * that is load-bearing — do not add ->required() back.
                 *
                 * Saving a draft goes through Schema::getState(), which
                 * validates the ENTIRE form, not just the step being looked
                 * at. A ->required() here therefore made every «ذخیره
                 * پیش‌نویس» and every step transition fail with "enter the
                 * code" — on step 1, before a code had even been sent. The
                 * first version of this page had exactly that bug.
                 *
                 * The code is checked in finalize() instead, against the
                 * hashed challenge in the database, which is where it has to
                 * happen anyway: a rule here would only ever have checked
                 * that the box was six digits long.
                 */
                TextInput::make('otp_code')
                    ->label('کد تایید')
                    // Lets phones show a numeric keypad and offer the code
                    // straight from the SMS notification.
                    ->extraInputAttributes(['inputmode' => 'numeric'])
                    ->autocomplete('one-time-code')
                    // Never dehydrated: it has no business being in the data
                    // that gets persisted as the draft.
                    ->dehydrated(false),
                // A schema-level action: clicking it calls the closure over
                // Livewire without submitting the form.
                SchemaActions::make([
                    Action::make('resendSuggestionOtp')
                        ->label('ارسال مجدد کد')
                        ->link()
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->action(fn () => $this->issueOtp(notify: true)),
                ]),
            ]);
    }

    /*
     * ---- Money ------------------------------------------------------------
     */

    /**
     * unit price × the requested quantity, in ریال.
     *
     * The quantity comes from the DATABASE, never from the row's state — a
     * quantity that round-tripped through the browser is a quantity somebody
     * could have edited on the way. A requirement id that is not one of this
     * tender's yields 0 and, on save, no row at all.
     */
    private function lineTotal(mixed $requirementId, mixed $unitPrice): int
    {
        $price = (int) ($unitPrice ?: 0);

        if ($price <= 0) {
            return 0;
        }

        return $price * (int) ($this->requirement($requirementId)?->quantity ?? 0);
    }

    /**
     * The bid price — every line total added up.
     *
     * @param  array<mixed>  $items  the repeater's raw state
     */
    private function grandTotal(array $items): int
    {
        $total = 0;

        foreach ($items as $row) {
            // Only the row's own requirement_id field — never its array key.
            // See the Hidden component in pricesStep() for why.
            $total += $this->lineTotal(
                $row['requirement_id'] ?? null,
                $row['unit_price'] ?? null,
            );
        }

        return $total;
    }

    /*
     * ---- Saving --------------------------------------------------------
     */

    /**
     * Build the wizard's initial state from whatever is already stored.
     *
     * The items array is keyed by requirement id for readability only —
     * Filament replaces those keys with its own on hydration. What actually
     * ties a row to a good is the `requirement_id` field inside it.
     *
     * @return array<string, mixed>
     */
    private function buildFormState(): array
    {
        $suggestion = $this->suggestion();
        $prices = $suggestion->items()->pluck('unit_price', 'bid_good_requirement_id');

        return [
            'items' => $this->requirements()
                ->mapWithKeys(fn (BidGoodRequirement $requirement): array => [
                    (string) $requirement->id => [
                        'requirement_id' => $requirement->id,
                        'unit_price' => $prices[$requirement->id] ?? null,
                    ],
                ])
                ->all(),
            'note' => $suggestion->note,
            // FileUpload's multiple() state is a path => path map; the keys
            // are Filament's own bookkeeping and are regenerated on render.
            'documents' => $suggestion->documents()->pluck('path', 'path')->all(),
            'receipts' => $suggestion->receipts()->pluck('path', 'path')->all(),
        ];
    }

    /**
     * Write the whole form to the database as a draft.
     *
     * Called from the header button AND from every step transition, so a
     * user who walks away mid-wizard loses at most the step they were on.
     *
     * getState() is what moves the uploaded files out of Livewire's
     * temporary storage and onto the disk, which is why the file paths only
     * become real here.
     */
    public function saveDraft(bool $notify = true): void
    {
        $this->guard();

        $state = $this->form->getState();
        $suggestion = $this->suggestion();

        $suggestion->forceFill(['note' => $state['note'] ?? null])->save();

        $this->syncItems((array) ($state['items'] ?? []));

        BidSuggestionAttachment::sync(
            $suggestion,
            SuggestionAttachmentType::Document,
            (array) ($state['documents'] ?? []),
        );
        BidSuggestionAttachment::sync(
            $suggestion,
            SuggestionAttachmentType::PaymentReceipt,
            (array) ($state['receipts'] ?? []),
        );

        $suggestion->recalculateTotal();

        if ($notify) {
            Notification::make()
                ->title('پیش‌نویس پیشنهاد ذخیره شد.')
                ->body('می‌توانید بعداً از فهرست مناقصات ادامه دهید.')
                ->success()
                ->send();
        }
    }

    /**
     * Make the stored price lines match the form exactly.
     *
     * Three rules, all of which exist because the incoming array came from
     * the browser:
     *   - a row whose requirement is not this tender's is IGNORED, not
     *     rejected loudly — the only way to produce one is to craft it;
     *   - an empty or zero price writes no row, because "not priced" is the
     *     absence of a row (see the migration);
     *   - the line total is computed here from the database's quantity, so
     *     the stored figure cannot be dictated by the client.
     *
     * @param  array<mixed>  $rows
     */
    private function syncItems(array $rows): void
    {
        $suggestion = $this->suggestion();
        $keptRequirementIds = [];

        foreach ($rows as $row) {
            $requirement = $this->requirement($row['requirement_id'] ?? null);

            if (! $requirement) {
                continue;
            }

            $unitPrice = (int) (($row['unit_price'] ?? null) ?: 0);

            if ($unitPrice <= 0) {
                continue;
            }

            $suggestion->items()->updateOrCreate(
                ['bid_good_requirement_id' => $requirement->id],
                [
                    'unit_price' => $unitPrice,
                    'total_price' => $unitPrice * $requirement->quantity,
                ],
            );

            $keptRequirementIds[] = $requirement->id;
        }

        // Anything the user cleared this time round. An empty keep-list
        // correctly deletes everything — that is a user who blanked the
        // whole price table.
        $suggestion->items()
            ->whereNotIn('bid_good_requirement_id', $keptRequirementIds)
            ->delete();
    }

    /*
     * ---- Finalising -------------------------------------------------------
     */

    /**
     * The two things a bid cannot be submitted without.
     *
     * Checked here rather than with ->required() on the fields, because a
     * required field would also block SAVING A DRAFT — and a draft that
     * refuses to save until it is complete is not a draft.
     *
     * Run twice on purpose: once before the SMS is sent (no point spending a
     * message on a bid that cannot be accepted) and once at submit time (the
     * page could have been open a long time, and the browser could have been
     * asked to skip a step).
     *
     * The complaint arrives as a NOTIFICATION rather than a field error,
     * because both offending fields live on earlier steps: an inline error
     * attached to `data.items` would be rendered inside step 1, which the
     * person reading it is not looking at.
     */
    private function assertReadyToFinalize(): void
    {
        $suggestion = $this->suggestion();

        $problem = match (true) {
            (int) $suggestion->total_price <= 0 => 'برای حداقل یکی از کالاها قیمت وارد کنید (مرحله «قیمت کالاها»).',
            $suggestion->receipts()->isEmpty() => 'بارگذاری رسید پرداخت یا ضمانت‌نامه بانکی الزامی است (مرحله «رسید پرداخت»).',
            default => null,
        };

        if ($problem === null) {
            return;
        }

        Notification::make()
            ->title('پیشنهاد هنوز کامل نیست')
            ->body($problem)
            ->danger()
            ->send();

        throw new Halt;
    }

    /**
     * The form's submit handler (see the Blade view's wire:submit).
     *
     * The try/catch is not decoration. Filament swallows Halt for it in the
     * two places it raises one itself — inside an Action
     * (InteractsWithActions) and inside a wizard step transition
     * (Wizard::nextStep) — but this method is reached through a plain
     * `wire:submit`, which has neither wrapper. An uncaught Halt here would
     * surface as a 500 and, worse, would throw away the redirect and the
     * notification that guard()/assertReadyToFinalize() had already queued
     * to explain what went wrong.
     */
    public function finalize(): void
    {
        try {
            $this->guard();

            // Persists the last step's uploads/prices and re-runs the
            // completeness rules against what is actually stored.
            $this->saveDraft(notify: false);
            $this->assertReadyToFinalize();
        } catch (Halt) {
            return;
        }

        $mobile = $this->currentUser()->mobile;
        $code = trim((string) ($this->data['otp_code'] ?? ''));

        // The "you typed nothing" case is separated out only so the message
        // is useful — verify() would answer 'invalid' and say the code is
        // wrong, which is a confusing thing to read when you typed no code.
        if ($code === '') {
            throw ValidationException::withMessages([
                'data.otp_code' => 'کد تایید را وارد کنید.',
            ]);
        }

        $status = app(OtpService::class)->verify($mobile, $code);

        if ($status !== 'ok') {
            throw ValidationException::withMessages([
                'data.otp_code' => match ($status) {
                    'expired' => 'کد تایید منقضی شده است. کد جدید درخواست کنید.',
                    'too_many_attempts' => 'تعداد تلاش‌های مجاز به پایان رسیده است. کد جدید درخواست کنید.',
                    'not_found' => 'کدی برای این شماره یافت نشد. دوباره درخواست دهید.',
                    default => 'کد تایید نادرست است.',
                },
            ]);
        }

        $trackingCode = $this->suggestion()->finalize();

        // The challenge has done its job — delete it so the same code can
        // never be replayed against another tender.
        app(OtpService::class)->forget($mobile);

        // ->persistent() because this notification carries the one thing the
        // user actually needs to write down; an auto-dismissing toast would
        // take the tracking code away while they were still reading it. It
        // is also on the row and in the «مشاهده پیشنهاد» modal, so losing it
        // here is recoverable either way.
        Notification::make()
            ->title('پیشنهاد شما ثبت نهایی شد.')
            ->body("کد پیگیری: {$trackingCode}")
            ->success()
            ->persistent()
            ->send();

        $this->redirect(BidResource::getUrl('index'));
    }

    /*
     * ---- The SMS ----------------------------------------------------------
     */

    /**
     * Text a fresh code to the logged-in account's mobile number.
     *
     * Mirrors App\Filament\Auth\Register::issueOtp(), including showing the
     * provider's own reason for a failure: an unexplained "try again" is
     * indistinguishable from a bug to the person staring at it, and msgway's
     * messages are already Persian and readable.
     *
     * $notify picks where the outcome is shown. The step-4 «بعدی» must BLOCK
     * by throwing (advancing to a step that asks for a code which will never
     * arrive would be worse than useless); the «ارسال مجدد کد» link is a
     * standalone button and reports through a notification instead.
     */
    private function issueOtp(bool $notify = false): void
    {
        $mobile = $this->currentUser()->mobile;

        try {
            $result = app(OtpService::class)->issue(
                $mobile,
                request()?->ip(),
                OtpService::PURPOSE_BID_SUGGESTION,
            );
        } catch (OtpThrottledException $e) {
            $this->reportOtpFailure(
                "لطفاً {$e->retryAfterSeconds} ثانیه دیگر دوباره تلاش کنید.",
                $notify,
            );

            return;
        }

        if (! $result->ok) {
            $this->reportOtpFailure(
                'ارسال کد تایید با خطا مواجه شد. لطفاً دوباره تلاش کنید.'.$this->providerReason($result),
                $notify,
            );

            return;
        }

        if ($notify) {
            Notification::make()->title('کد تایید دوباره ارسال شد.')->success()->send();
        }
    }

    private function reportOtpFailure(string $message, bool $notify): void
    {
        if ($notify) {
            Notification::make()->title($message)->danger()->send();

            return;
        }

        throw ValidationException::withMessages(['data.otp_code' => $message]);
    }

    /** The SMS provider's own reason, collapsed to one line and capped. */
    private function providerReason(SmsResult $result): string
    {
        $reason = trim((string) ($result->errorMessage ?: $result->errorCode));

        if ($reason === '') {
            return '';
        }

        return ' ('.Str::limit(preg_replace('/\s+/u', ' ', $reason), 160).')';
    }

    /*
     * ---- Chrome -----------------------------------------------------------
     */

    /** The «ذخیره پیش‌نویس» button, available from every step. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveDraft')
                ->label('ذخیره پیش‌نویس')
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->color('gray')
                ->action(fn () => $this->saveDraft()),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return 'ارسال پیشنهاد';
    }

    public function getHeading(): string|Htmlable
    {
        return "ارسال پیشنهاد — {$this->getRecord()->title}";
    }

    public function getBreadcrumb(): string
    {
        return 'ارسال پیشنهاد';
    }
}
