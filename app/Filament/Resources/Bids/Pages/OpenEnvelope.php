<?php

namespace App\Filament\Resources\Bids\Pages;

use App\Enums\EnvelopeDecision;
use App\Enums\EnvelopeStage;
use App\Enums\RoleName;
use App\Enums\SuggestionAttachmentType;
use App\Filament\Resources\Bids\BidResource;
use App\Models\Bid;
use App\Models\BidGoodRequirement;
use App\Models\BidSuggestion;
use App\Models\BidSuggestionAttachment;
use App\Models\User;
use App\Services\SuggestionResultNotifier;
use Ariaieboy\Jalali\Jalali;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * «بازکردن پاکت الف / ب» — the admin's two-stage, anonymous review of the
 * offers on a finished tender.
 *
 * ---------------------------------------------------------------------------
 * The process this screen implements
 * ---------------------------------------------------------------------------
 * Once a tender has expired, its offers are opened in two "envelopes", the
 * way a paper tender is:
 *
 *   پاکت الف — the TECHNICAL envelope. Every live offer, one at a time, with
 *              its goods, the specifications the bidder says they can supply,
 *              the attachments and the ودیعه payment. NO PRICES AT ALL: the
 *              point of opening الف first is that the technical judgement
 *              cannot be influenced by the amounts.
 *   پاکت ب   — the FINANCIAL envelope. Only the offers approved in الف come
 *              back, this time with unit prices and totals.
 *
 * In both, the admin presses the green «تایید» or the red «رد» on each offer
 * and is moved to the next one; «قبلی»/«بعدی» let them go back and change
 * their mind as often as they like. After the last offer comes a review
 * screen, and only the «ثبت نهایی» button there — behind a checkbox saying
 * the admin understands it cannot be undone — turns those clicks into real
 * outcomes.
 *
 * ---------------------------------------------------------------------------
 * Nothing is final until the last button
 * ---------------------------------------------------------------------------
 * Each click DOES write to the database (`bid_suggestions.envelope_?_decision`)
 * — an admin working through thirty offers must be able to close the browser
 * and come back — but nothing reads those columns until the tender's
 * `envelope_?_submitted_at` is stamped by submit(). So up to that moment the
 * bidders' statuses are untouched, no SMS has been sent, and every decision is
 * still a draft. See Bid::finalizeEnvelope() and the envelope migration.
 *
 * ---------------------------------------------------------------------------
 * The admin never learns whose offer this is
 * ---------------------------------------------------------------------------
 * Every screen here shows «مخفی شده» where a name would go
 * (BidSuggestion::bidderNameForAdmin()). The identity of a bidder becomes
 * visible only for the WINNERS, and only after پاکت ب is finalised — at which
 * point the «تخته برندگان» modal on the مناقصات table shows their full
 * details. Reviewing offers you can attribute is the thing the two-envelope
 * process exists to prevent.
 *
 * ---------------------------------------------------------------------------
 * Why a page, and why Filament's own buttons
 * ---------------------------------------------------------------------------
 * A page (not a modal) because the review can be long, has a URL to come back
 * to, and would lose its place every time a dialog closed. Every control is a
 * Filament Action or schema component — no hand-written markup — because the
 * panel's compiled CSS ships only Filament's own `fi-*` classes (see
 * ARCHITECTURE.md, "Panel CSS has no Tailwind utilities").
 */
class OpenEnvelope extends Page
{
    use InteractsWithRecord;

    protected static string $resource = BidResource::class;

    // Never a sidebar item: this page only makes sense for one tender, reached
    // from the letter icon on the مناقصات table.
    protected static bool $shouldRegisterNavigation = false;

    /**
     * Which envelope, as the raw 'a'/'b' from the URL.
     *
     * A plain string rather than the enum itself: this is a public Livewire
     * property, so it is serialised into the browser and back on every
     * request, and a string is the value that survives that round trip
     * unambiguously. stage() below turns it back into the enum, which is what
     * the rest of the class uses.
     */
    public string $stageValue = 'a';

    /**
     * How far through the offers the admin is.
     *
     * 0-based, and one PAST the last offer means "show the review screen" —
     * see isOnReviewScreen(). Set from the `?offer=` query string in mount()
     * and changed only by a REDIRECT to a new `?offer=` (see moveTo()).
     *
     * It has to stay a PUBLIC Livewire property even so: mount() does not run
     * again for the Livewire request a button click makes, so a private
     * property would be back at 0 by the time decide() recorded a verdict —
     * i.e. the verdict would land on the wrong offer. Public means it is
     * carried in the component snapshot, which also means it arrives from the
     * browser and is clamped on every read (currentIndex()) rather than
     * trusted.
     */
    public int $index = 0;

    /** Per-request memo of this envelope's offers, in review order. */
    private ?Collection $suggestions = null;

    /*
     * ---- Opening the page -------------------------------------------------
     */

    /**
     * Both parameters come from the route — /bids/{record}/envelope/{stage}.
     */
    public function mount(int|string $record, string $stage): void
    {
        $this->record = $this->resolveRecord($record);
        $this->stageValue = EnvelopeStage::tryFrom($stage)?->value ?? EnvelopeStage::A->value;

        // Which offer to show. A query parameter rather than a route segment
        // because it is a position in a list, not part of the page's identity —
        // and because it makes the browser's back button and a refresh both do
        // the obvious thing. Clamped on read; see currentIndex().
        $this->index = max(0, (int) request()->query('offer', 0));

        if ($refusal = $this->refusalReason()) {
            Notification::make()->title($refusal)->warning()->send();

            $this->redirect(BidResource::getUrl('index'));

            return;
        }

        /*
         * Opening پاکت ب moves the offers that got through الف to «فرم ب», so
         * the bidders' own «وضعیت» column tells them where their offer is. It
         * writes no verdict — see Bid::markEnvelopeBInProgress().
         */
        if ($this->stage() === EnvelopeStage::B) {
            $this->getRecord()->markEnvelopeBInProgress();
        }
    }

    /**
     * Why this envelope may not be opened right now — or null if it may.
     *
     * Re-run before every write, not just on mount: the page can sit open for
     * a long time, and a second tab (or a second admin) can finalise the same
     * envelope in the meantime.
     */
    private function refusalReason(): ?string
    {
        /** @var Bid $bid */
        $bid = $this->getRecord();

        // Admin only, deliberately not staff: this is the decision that
        // settles a tender. Widen this check if that ever changes.
        if (! $this->currentUser()->hasRole(RoleName::Admin->value)) {
            return 'بازکردن پاکت‌ها فقط برای مدیر امکان‌پذیر است.';
        }

        if ($bid->envelopeIsSubmitted($this->stage())) {
            return "پاکت {$this->stage()->letter()} این مناقصه قبلاً ثبت نهایی شده است.";
        }

        if (! $bid->envelopeIsOpenable($this->stage())) {
            return $this->stage() === EnvelopeStage::B
                ? 'ابتدا باید پاکت الف این مناقصه ثبت نهایی شود.'
                : 'این مناقصه هنوز به پایان نرسیده است یا پیشنهادی برای آن ثبت نشده است.';
        }

        return null;
    }

    /** Stop a write dead if the situation changed since the page loaded. */
    private function guard(): bool
    {
        if ($refusal = $this->refusalReason()) {
            Notification::make()->title($refusal)->danger()->send();

            $this->redirect(BidResource::getUrl('index'));

            return false;
        }

        return true;
    }

    private function currentUser(): User
    {
        return Auth::user();
    }

    public function stage(): EnvelopeStage
    {
        return EnvelopeStage::from($this->stageValue);
    }

    /**
     * This envelope's offers, in review order.
     *
     * Not a cache across requests — a fresh page instance is built for every
     * Livewire round trip, so this is re-read (with its items, specifications
     * and files) once per request.
     *
     * @return Collection<int, BidSuggestion>
     */
    private function suggestions(): Collection
    {
        return $this->suggestions ??= $this->getRecord()->envelopeSuggestions($this->stage());
    }

    /**
     * The index actually shown, clamped into range.
     *
     * $index is a public Livewire property, i.e. it round-trips through the
     * browser and can arrive as anything at all. Clamping here (rather than
     * trusting it) is what stops a crafted value from producing an
     * out-of-bounds read.
     */
    private function currentIndex(): int
    {
        return max(0, min($this->index, $this->suggestions()->count()));
    }

    /** Is the admin past the last offer, i.e. on the final review screen? */
    private function isOnReviewScreen(): bool
    {
        return $this->currentIndex() >= $this->suggestions()->count();
    }

    /** Is the offer on screen the last one before the review screen? */
    private function isOnLastSuggestion(): bool
    {
        return $this->currentIndex() === $this->suggestions()->count() - 1;
    }

    /** The offer being looked at, or null on the review screen. */
    private function currentSuggestion(): ?BidSuggestion
    {
        return $this->suggestions()->values()->get($this->currentIndex());
    }

    /*
     * ---- The screen -------------------------------------------------------
     */

    /**
     * A Filament Page renders whatever content() returns — that is why this
     * page needs no Blade file of its own.
     */
    public function content(Schema $schema): Schema
    {
        if ($this->suggestions()->isEmpty()) {
            return $schema->components([$this->emptySection()]);
        }

        return $schema->components([
            $this->isOnReviewScreen()
                ? $this->reviewSection()
                : $this->suggestionSection(),
        ]);
    }

    /**
     * The "there is nothing here" case.
     *
     * Reachable in پاکت ب when every single offer was rejected in الف: the
     * envelope is legitimately openable, and finalising it with no winners is
     * a valid outcome that still has to be recorded (and still texts the
     * rejected bidders).
     */
    private function emptySection(): Section
    {
        return Section::make("پاکت {$this->stage()->letter()}")
            ->description('پیشنهادی برای بررسی در این پاکت وجود ندارد.')
            ->schema([
                TextEntry::make('empty_notice')
                    ->hiddenLabel()
                    ->state($this->stage() === EnvelopeStage::B
                        ? 'هیچ پیشنهادی از پاکت الف تایید نشده است. با ثبت نهایی، این مناقصه بدون برنده پایان می‌یابد.'
                        : 'برای این مناقصه پیشنهاد فعالی ثبت نشده است.'),
                SchemaActions::make([
                    $this->submitEnvelopeAction(),
                    $this->backToListAction(),
                ]),
            ]);
    }

    /**
     * One offer, anonymously.
     */
    private function suggestionSection(): Section
    {
        $suggestion = $this->currentSuggestion();
        $position = $this->currentIndex() + 1;
        $total = $this->suggestions()->count();

        return Section::make("پیشنهاد {$position} از {$total} — پاکت {$this->stage()->letter()}")
            ->description('نام و مشخصات پیشنهاددهنده در این مرحله نمایش داده نمی‌شود.')
            ->schema([
                TextEntry::make('bidder')
                    ->label('پیشنهاددهنده')
                    // Always the masked value here: no offer can be a "winner"
                    // while the envelope it is in is still open.
                    ->state(fn (): string => $suggestion->bidderNameForAdmin())
                    ->badge()
                    ->color('gray'),
                TextEntry::make('tracking_code')
                    ->label('کد پیگیری')
                    ->state(fn (): ?string => $suggestion->tracking_code)
                    ->placeholder('—')
                    ->copyable(),
                TextEntry::make('submitted_at')
                    ->label('تاریخ و ساعت ارسال')
                    ->state(fn (): string => self::jalali($suggestion->submitted_at)),
                TextEntry::make('decision')
                    ->label("تصمیم شما در پاکت {$this->stage()->letter()}")
                    ->badge()
                    ->state(fn (): string => $suggestion->decisionFor($this->stage())?->getLabel() ?? 'تصمیم‌گیری نشده')
                    ->color(fn (): string => $suggestion->decisionFor($this->stage())?->getColor() ?? 'gray'),

                $this->goodsEntry($suggestion),

                // Money exists only in پاکت ب.
                TextEntry::make('total_price')
                    ->label('جمع کل پیشنهاد (ریال)')
                    ->weight('bold')
                    ->visible($this->stage()->showsPrices())
                    ->state(fn (): string => number_format((int) $suggestion->total_price))
                    ->columnSpanFull(),

                TextEntry::make('note')
                    ->label('متن پیشنهاد')
                    ->placeholder('—')
                    ->state(fn (): ?string => $suggestion->note)
                    ->columnSpanFull(),
                $this->filesEntry($suggestion, 'documents', 'پیوست‌ها', SuggestionAttachmentType::Document),

                TextEntry::make('payment_type')
                    ->label('روش پرداخت ودیعه')
                    ->placeholder('—')
                    ->state(fn (): ?string => $suggestion->payment_type?->getLabel()),
                TextEntry::make('deposit_amount')
                    ->label('مبلغ ودیعه مناقصه (ریال)')
                    ->state(fn (): string => number_format((int) $this->getRecord()->deposit_amount)),
                $this->filesEntry($suggestion, 'bank_guarantee', 'ضمانت‌نامه بانکی', SuggestionAttachmentType::BankGuaranteeLetter),
                // The «نامه کسر از مطالبات» letter, as the bidder filled it in.
                TextEntry::make('claims_decrease')
                    ->label('متن نامه کسر از مطالبات')
                    ->placeholder('—')
                    ->state(fn (): ?HtmlString => $this->claimsDecreaseSummary($suggestion))
                    ->html()
                    ->columnSpanFull(),
                $this->filesEntry($suggestion, 'claims_attachment', 'پیوست نامه کسر از مطالبات', SuggestionAttachmentType::ClaimsDecreaseAttachment),

                SchemaActions::make([
                    $this->decideAction(EnvelopeDecision::Approved),
                    $this->decideAction(EnvelopeDecision::Declined),
                    $this->previousAction(),
                    $this->nextAction(),
                ]),
            ]);
    }

    /**
     * The tender's goods, with what this bidder said about each of them.
     *
     * The rows are the TENDER's requirement rows, not the bidder's priced
     * lines, so a good the bidder skipped is still visible as a gap rather
     * than silently missing from the table.
     *
     * The specification column is ONE column, as specified: it shows the
     * specification that would actually be supplied — the bidder's wording if
     * they offered a different one, the employer's otherwise — and only adds a
     * warning icon (with the employer's original text in its tooltip) when the
     * bidder changed it. See BidSuggestion::suppliableSpecificationFor().
     */
    private function goodsEntry(BidSuggestion $suggestion): RepeatableEntry
    {
        $columns = [
            TableColumn::make('کد کالا'),
            TableColumn::make('شرح کالا'),
            TableColumn::make('تعداد'),
            TableColumn::make('مشخصات فنی قابل تامین'),
        ];

        $entries = [
            TextEntry::make('good.code')->hiddenLabel(),
            TextEntry::make('good.name')->hiddenLabel(),
            TextEntry::make('quantity')
                ->hiddenLabel()
                // number_format, not ->numeric(): Latin digits, like every
                // other number in the panel.
                ->formatStateUsing(fn (?int $state): string => number_format((int) $state)),
            TextEntry::make('effective_specifications')
                ->hiddenLabel()
                ->state(fn (BidGoodRequirement $record): string => $suggestion->suppliableSpecificationFor($record->id)
                    ?? ($record->good?->specifications ?? '—'))
                ->icon(fn (BidGoodRequirement $record) => $suggestion->suppliableSpecificationFor($record->id) !== null
                    ? Heroicon::OutlinedExclamationTriangle
                    : null)
                ->iconColor('warning')
                ->tooltip(fn (BidGoodRequirement $record): ?string => $suggestion->suppliableSpecificationFor($record->id) !== null
                    ? 'مشخصات فنی توسط پیشنهاددهنده تغییر داده شده است. مشخصات کارفرما: '.($record->good?->specifications ?: '—')
                    : null),
        ];

        // Prices are added only in پاکت ب — in الف they are not on the page at
        // all, which is the whole point of opening الف first.
        if ($this->stage()->showsPrices()) {
            $columns[] = TableColumn::make('قیمت واحد (ریال)');
            $columns[] = TableColumn::make('جمع (ریال)');

            $entries[] = TextEntry::make('unit_price')
                ->hiddenLabel()
                ->state(fn (BidGoodRequirement $record): string => $this->priceText($suggestion, $record, 'unit_price'));
            $entries[] = TextEntry::make('line_total')
                ->hiddenLabel()
                ->state(fn (BidGoodRequirement $record): string => $this->priceText($suggestion, $record, 'total_price'));
        }

        return RepeatableEntry::make('goods')
            ->label('کالاها')
            ->state(fn (): array => $this->getRecord()->goodRequirements()->with('good')->orderBy('id')->get()->all())
            ->placeholder('برای این مناقصه کالایی تعریف نشده است.')
            ->columnSpanFull()
            ->table($columns)
            ->schema($entries);
    }

    /**
     * One money cell of the goods table: the bidder's figure, or «—» for a
     * good they chose not to supply (no priced line exists for it).
     */
    private function priceText(BidSuggestion $suggestion, BidGoodRequirement $requirement, string $column): string
    {
        $item = $suggestion->items->firstWhere('bid_good_requirement_id', $requirement->id);

        return $item ? number_format((int) $item->{$column}) : '—';
    }

    /**
     * The «نامه کسر از مطالبات» fields as one readable block, or null when
     * that is not the payment method the bidder chose.
     */
    private function claimsDecreaseSummary(BidSuggestion $suggestion): ?HtmlString
    {
        if (blank($suggestion->claims_decrease_addressee)
            && blank($suggestion->claims_decrease_tender_number)
            && blank($suggestion->claims_decrease_subject)) {
            return null;
        }

        // e() escapes the values: these are strings a bidder typed, and they
        // are about to be rendered as HTML by ->html() above.
        return new HtmlString(implode('<br>', [
            'واحد محترم خرید: '.e((string) $suggestion->claims_decrease_addressee),
            'شماره مناقصه: '.e((string) $suggestion->claims_decrease_tender_number),
            'موضوع: '.e((string) $suggestion->claims_decrease_subject),
            'از محل مطالبات: '.e((string) $suggestion->claims_decrease_org_name),
        ]));
    }

    /**
     * One downloadable file list from the offer.
     *
     * The state is the attachment MODELS rather than strings, which is what
     * lets each filename carry its own download link — Filament resolves
     * formatStateUsing() and url() once per list item. Same trick the مناقصات
     * table's modals use.
     */
    private function filesEntry(
        BidSuggestion $suggestion,
        string $name,
        string $label,
        SuggestionAttachmentType $type,
    ): TextEntry {
        return TextEntry::make($name)
            ->label($label)
            ->placeholder('فایلی بارگذاری نشده است.')
            ->state(fn (): array => $suggestion->attachments->where('type', $type)->values()->all())
            ->formatStateUsing(fn (BidSuggestionAttachment $state): string => $state->original_name)
            ->url(fn (BidSuggestionAttachment $state): string => $state->url)
            ->openUrlInNewTab()
            ->icon(Heroicon::OutlinedPaperClip)
            ->listWithLineBreaks()
            ->columnSpanFull();
    }

    /**
     * The final screen: every verdict in one list, then the irreversible
     * button.
     */
    private function reviewSection(): Section
    {
        return Section::make("مرور و ثبت نهایی پاکت {$this->stage()->letter()}")
            ->description('تصمیم‌های خود را بررسی کنید. تا زمانی که دکمه ثبت نهایی را نزنید، هیچ‌کدام قطعی نیست و می‌توانید با «قبلی» بازگردید و تغییر دهید.')
            ->schema([
                RepeatableEntry::make('decisions')
                    ->hiddenLabel()
                    ->state(fn (): array => $this->suggestions()->values()->all())
                    ->columnSpanFull()
                    ->table([
                        TableColumn::make('ردیف'),
                        TableColumn::make('پیشنهاددهنده'),
                        TableColumn::make('کد پیگیری'),
                        TableColumn::make('تصمیم'),
                    ])
                    ->schema([
                        // The offer's position in the review order, so a row on
                        // this list can be matched to the screen it came from.
                        TextEntry::make('row_number')
                            ->hiddenLabel()
                            ->state(fn (BidSuggestion $record): string => (string) ($this->suggestions()
                                ->values()
                                ->search(fn (BidSuggestion $item): bool => $item->id === $record->id) + 1)),
                        TextEntry::make('bidder_masked')
                            ->hiddenLabel()
                            ->state(fn (BidSuggestion $record): string => $record->bidderNameForAdmin()),
                        TextEntry::make('tracking_code')->hiddenLabel()->placeholder('—'),
                        TextEntry::make('row_decision')
                            ->hiddenLabel()
                            ->badge()
                            ->state(fn (BidSuggestion $record): string => $record->decisionFor($this->stage())?->getLabel() ?? 'تصمیم‌گیری نشده')
                            ->color(fn (BidSuggestion $record): string => $record->decisionFor($this->stage())?->getColor() ?? 'gray'),
                    ]),
                SchemaActions::make([
                    $this->submitEnvelopeAction(),
                    $this->previousAction(),
                ]),
            ]);
    }

    /*
     * ---- The buttons ------------------------------------------------------
     */

    /**
     * «تایید» (green) / «رد» (red) — record the verdict and move on.
     *
     * Moving on automatically is what makes a long review bearable: the admin
     * reads an offer, presses one button, and the next offer is already on
     * screen. Going back is «قبلی», and re-pressing a button on a revisited
     * offer simply overwrites the verdict.
     */
    private function decideAction(EnvelopeDecision $decision): Action
    {
        return Action::make('decide'.ucfirst($decision->value))
            ->label($decision === EnvelopeDecision::Approved ? 'تایید' : 'رد')
            ->icon($decision === EnvelopeDecision::Approved
                ? Heroicon::OutlinedCheckCircle
                : Heroicon::OutlinedXCircle)
            ->color($decision->getColor())
            ->action(fn () => $this->decide($decision->value));
    }

    /**
     * Record a verdict on the offer currently on screen and move to the next.
     *
     * A public method rather than a closure inside the action, so the whole
     * step is one testable unit (and so the two buttons share one code path).
     * The decision arrives as its string value because that is what survives
     * a Livewire call; an unknown value is ignored rather than trusted.
     */
    public function decide(string $decision): void
    {
        if (! $this->guard()) {
            return;
        }

        $verdict = EnvelopeDecision::tryFrom($decision);
        $suggestion = $this->currentSuggestion();

        if (! $verdict || ! $suggestion) {
            return;
        }

        $suggestion->recordDecision($this->stage(), $verdict);

        // One past the last offer is the review screen — see isOnReviewScreen().
        $this->moveTo($this->currentIndex() + 1);
    }

    /** Move back one offer, if there is one behind. */
    public function previous(): void
    {
        $this->moveTo($this->currentIndex() - 1);
    }

    /** Move forward one offer, or onto the review screen. */
    public function next(): void
    {
        $this->moveTo($this->currentIndex() + 1);
    }

    /**
     * Show a different offer — by RELOADING the page at `?offer=N`, not by
     * changing `$index` and re-rendering in place.
     *
     * ---------------------------------------------------------------------
     * THE BUG THIS FIXES — do not "optimise" it back into a plain assignment
     * ---------------------------------------------------------------------
     * The تایید/رد/قبلی/بعدی buttons are schema actions that live INSIDE the
     * very section they replace: pressing تایید on the last offer swaps the
     * offer section for the review section, which deletes the schema component
     * the pressed button belongs to. Filament still has to finish that action's
     * own lifecycle (unmount it, re-resolve it from the schema), and it cannot
     * find it any more — so the click committed its verdict to the database
     * while the page kept the old body and lost the buttons. Reported from
     * production as "I press تایید and nothing happens except the buttons
     * change".
     *
     * Redirecting ends that request instead of re-rendering it: the action
     * finishes against the schema it was mounted from, and the next offer (or
     * the review screen) arrives as a fresh page whose mount() re-reads
     * everything from the database. It costs one page load per click, which is
     * the right trade for a screen where each click is a deliberate decision —
     * and it makes the URL a real position, so refresh and the back button both
     * behave.
     *
     * navigate: false forces a real browser load rather than Livewire's SPA
     * navigation, so nothing of the old page's DOM or component state survives.
     */
    private function moveTo(int $offer): void
    {
        $this->redirect(BidResource::getUrl('envelope', [
            'record' => $this->getRecord(),
            'stage' => $this->stageValue,
            // Unknown parameters become query string — hence ?offer=N.
            'offer' => max(0, min($offer, $this->suggestions()->count())),
        ]), navigate: false);
    }

    /**
     * «قبلی» — hidden on the first offer, where there is nothing behind.
     *
     * On the review screen it says «بازگشت و تغییر تصمیم‌ها» instead. A bare
     * «قبلی» sitting next to «ثبت نهایی پاکت الف» read as a wizard control
     * that had no business being there; spelling out what it does makes it
     * obvious that it is the way back in to change a verdict.
     */
    private function previousAction(): Action
    {
        return Action::make('previousSuggestion')
            ->label(fn (): string => $this->isOnReviewScreen()
                ? 'بازگشت و تغییر تصمیم‌ها'
                : 'قبلی')
            ->icon(Heroicon::OutlinedArrowRight)
            ->color('gray')
            ->visible(fn (): bool => $this->currentIndex() > 0)
            ->action(fn () => $this->previous());
    }

    /**
     * «بعدی» — skip past an offer without deciding it.
     *
     * Allowed on purpose: an admin may want to read the whole field before
     * committing to anything. The undecided offers are listed as
     * «تصمیم‌گیری نشده» on the review screen, and submit() refuses to finalise
     * while any of them remain.
     *
     * On the LAST offer it says «مرور و ثبت نهایی», because that is where it
     * goes — «بعدی» on the final offer looks like a dead end otherwise, which
     * is exactly how it was read on a tender that had only one offer.
     */
    private function nextAction(): Action
    {
        return Action::make('nextSuggestion')
            ->label(fn (): string => $this->isOnLastSuggestion()
                ? 'مرور و ثبت نهایی'
                : 'بعدی')
            ->icon(fn (): Heroicon => $this->isOnLastSuggestion()
                ? Heroicon::OutlinedClipboardDocumentCheck
                : Heroicon::OutlinedArrowLeft)
            ->color('gray')
            ->action(fn () => $this->next());
    }

    /** «بازگشت به فهرست مناقصات» — only used on the empty-envelope screen. */
    private function backToListAction(): Action
    {
        return Action::make('backToList')
            ->label('بازگشت به مناقصات')
            ->color('gray')
            ->url(BidResource::getUrl('index'));
    }

    /**
     * «ثبت نهایی» — the irreversible one.
     *
     * The warning and the "I understand" checkbox live in this action's own
     * confirmation modal, which is Filament's own dialog: a required,
     * ->accepted() checkbox there is what forces the admin to acknowledge
     * before the button will do anything.
     */
    private function submitEnvelopeAction(): Action
    {
        $letter = $this->stage()->letter();

        return Action::make('submitEnvelope')
            ->label("ثبت نهایی پاکت {$letter}")
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('primary')
            ->modalHeading("ثبت نهایی پاکت {$letter}")
            ->modalDescription($this->stage() === EnvelopeStage::A
                ? 'با ثبت نهایی، پیشنهادهای تاییدشده به پاکت ب راه می‌یابند و پیشنهادهای ردشده رد نهایی می‌شوند. این عملیات قابل بازگشت نیست.'
                : 'با ثبت نهایی، پیشنهادهای تاییدشده برنده مناقصه می‌شوند و برای همه پیشنهاددهندگان پیامک نتیجه ارسال می‌شود. این عملیات قابل بازگشت نیست.')
            ->modalSubmitActionLabel("ثبت نهایی پاکت {$letter}")
            ->schema([
                Checkbox::make('understood')
                    ->label('می‌دانم این عملیات قطعی است و امکان بازگشت یا تغییر آن وجود ندارد.')
                    // ->accepted() is Laravel's "must be ticked" rule; without
                    // it the box would be decoration.
                    ->accepted()
                    ->validationMessages([
                        'accepted' => 'برای ثبت نهایی باید این مورد را تایید کنید.',
                    ]),
            ])
            ->action(fn () => $this->submit());
    }

    /*
     * ---- Finalising -------------------------------------------------------
     */

    /**
     * Turn this envelope's draft verdicts into real outcomes.
     *
     * Order matters:
     *   1. re-check that this envelope may still be finalised at all;
     *   2. refuse while any offer is undecided — a finalised envelope with a
     *      blank verdict would silently reject that bidder;
     *   3. Bid::finalizeEnvelope() writes the statuses and stamps the tender
     *      inside ONE transaction;
     *   4. only for پاکت ب, and only AFTER that transaction has committed,
     *      text every bidder the result. A texting failure must never roll
     *      back a decision the admin has just confirmed — see
     *      App\Services\SuggestionResultNotifier.
     */
    public function submit(): void
    {
        if (! $this->guard()) {
            return;
        }

        $stage = $this->stage();
        $undecided = $this->suggestions()
            ->filter(fn (BidSuggestion $suggestion): bool => $suggestion->decisionFor($stage) === null);

        if ($undecided->isNotEmpty()) {
            Notification::make()
                ->title('برای همه پیشنهادها تصمیم ثبت نشده است.')
                ->body("{$undecided->count()} پیشنهاد بدون تصمیم است. با دکمه‌های «قبلی» و «بعدی» به آن‌ها بازگردید و تایید یا رد کنید.")
                ->danger()
                ->send();

            return;
        }

        /** @var Bid $bid */
        $bid = $this->getRecord();
        $decided = $bid->finalizeEnvelope($stage);

        if ($stage === EnvelopeStage::B) {
            // The offers rejected back in پاکت الف are not in $decided (پاکت ب
            // only ever contained the الف-approved ones), but the requirement
            // is that EVERY non-winning bidder hears the result at this
            // moment — so the notifier is handed every live offer on the
            // tender, not just the ones this envelope decided.
            $sent = app(SuggestionResultNotifier::class)->notifyAll(
                $bid->activeSuggestions()->with(['user', 'bid'])->get(),
            );

            Notification::make()
                ->title('پاکت ب ثبت نهایی شد.')
                ->body("نتیجه مناقصه قطعی شد و {$sent} پیامک نتیجه ارسال شد.")
                ->success()
                ->send();
        } else {
            $approved = $decided->filter(fn (BidSuggestion $s): bool => $s->passedEnvelopeA())->count();

            Notification::make()
                ->title('پاکت الف ثبت نهایی شد.')
                ->body("{$approved} پیشنهاد به پاکت ب راه یافت.")
                ->success()
                ->send();
        }

        $this->redirect(BidResource::getUrl('index'));
    }

    /*
     * ---- Chrome -----------------------------------------------------------
     */

    /**
     * One stored Gregorian date, as the Jalali string the panel shows — the
     * same conversion the ->jalaliDateTime() macro does, spelled out because
     * these entries build their own state. Reads the same config key, so the
     * format still lives in one place.
     */
    private static function jalali(mixed $date): string
    {
        return $date
            ? Jalali::fromCarbon($date)->format(config('filament-jalali.date_time_format'))
            : '—';
    }

    public function getTitle(): string|Htmlable
    {
        return "بازکردن پاکت {$this->stage()->letter()}";
    }

    public function getHeading(): string|Htmlable
    {
        return "بازکردن پاکت {$this->stage()->letter()} — {$this->getRecord()->title}";
    }

    public function getBreadcrumb(): string
    {
        return "پاکت {$this->stage()->letter()}";
    }
}
