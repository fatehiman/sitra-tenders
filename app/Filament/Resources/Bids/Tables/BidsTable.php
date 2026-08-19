<?php

namespace App\Filament\Resources\Bids\Tables;

use App\Enums\EnvelopeStage;
use App\Enums\RoleName;
use App\Enums\SuggestionAttachmentType;
use App\Filament\Resources\Bids\BidResource;
use App\Models\Bid;
use App\Models\BidAttachment;
use App\Models\BidGoodRequirement;
use App\Models\BidSuggestion;
use App\Models\BidSuggestionAttachment;
use App\Models\GoodDrawing;
use Ariaieboy\Jalali\Jalali;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * The مناقصات list table: its columns, and the buttons on each row.
 *
 * This is the single most role-sensitive file in the app — the same table
 * serves admins, staff and regular users, showing each of them a different
 * set of rows, columns and buttons.
 *
 * Roughly:
 *   user        — only active tenders; sees the state of *their own* bid
 *                 («ارسال پیشنهاد» time + «وضعیت»), can submit one
 *                 and re-open it read-only afterwards.
 *   staff/admin — every tender in every state, how many live bids each has,
 *                 and a lock icon instead of «ویرایش» once anyone has bid.
 *   admin       — additionally «لغو» (the only way to unlock a tender) and the
 *                 letter icons that drive the two-envelope review:
 *                 «بازکردن پاکت الف» → «بازکردن پاکت ب» → «تخته برندگان».
 *
 * One rule cuts across all of it: an admin never sees WHOSE offer they are
 * reading. Every screen here shows «مخفی شده» instead of the bidder's name
 * until that bidder has won a finalised tender — see
 * BidSuggestion::bidderNameForAdmin().
 */
class BidsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * THE most important line here: regular users only ever see
             * tenders that have already started and have not yet expired.
             *
             * Bid::active() is the scope holding those two date conditions.
             * Admin and staff get every row, so they can see scheduled and
             * finished tenders and manage the full lifecycle.
             *
             * The ->with(...) calls are pure performance: without them every
             * row would re-query for its own bid / its lock state, turning
             * one page into dozens of queries (the classic "N+1").
             */
            ->modifyQueryUsing(function (Builder $query): Builder {
                if (Auth::user()->hasRole(RoleName::User->value)) {
                    // The nested loads feed the «مشاهده پیشنهاد» modal, which
                    // now shows the priced goods and the uploaded files as
                    // well as the note — without them, opening it would fire
                    // a query per line.
                    return $query->active()->with([
                        'mySuggestion.items.requirement.good',
                        'mySuggestion.attachments',
                        // Feeds the «مشخصات فنی قابل تامین» list in the
                        // «مشاهده پیشنهاد» modal.
                        'mySuggestion.specifications.requirement.good',
                    ]);
                }

                // 'activeSuggestions' (not a count) because Bid::isLocked()
                // reads the loaded collection when it is there, and the
                // «لغو» modal needs each offer's tracking code anyway.
                //
                // '.bid' looks circular — it is the tender we already have —
                // but the «پیشنهادهای دریافتی» modal asks each SUGGESTION
                // whether its tender's پاکت الف is finalised (that is where the
                // "no prices before الف" rule lives), and without this that
                // would be one extra query per offer in the modal.
                return $query->with(['activeSuggestions.user', 'activeSuggestions.bid']);
            })
            ->columns([
                TextColumn::make('title')
                    ->label('عنوان')
                    // Adds this column to the table's search box.
                    ->searchable(),
                // Reads Bid::getStatusLabelAttribute(). Pointless for regular
                // users, who by definition only ever see «فعال» rows.
                TextColumn::make('status_label')
                    ->label('وضعیت')
                    ->badge()
                    ->visible(fn (): bool => ! self::isUser()),
                /*
                 * Start and end in ONE column, stacked on two lines — the
                 * table carries a lot of columns for the user role and two
                 * separate date columns were what pushed it past the screen
                 * width into a horizontal scrollbar.
                 *
                 * ->state() returns an ARRAY of two strings and
                 * ->listWithLineBreaks() renders one per line, which is the
                 * same pair of methods the attachment lists in the modals use.
                 *
                 * Because the state is built by hand, the ->jalaliDateTime()
                 * macro (which formats a single date column) cannot be used —
                 * self::jalali() below does the identical conversion, reading
                 * the very same config key so the format still lives in one
                 * place.
                 *
                 * Sorting still ORDERs BY the real Gregorian start_at column,
                 * so the order stays chronologically correct rather than
                 * sorting formatted strings.
                 */
                TextColumn::make('start_at')
                    ->label('شروع / پایان')
                    ->state(fn (Bid $record): array => [
                        self::jalali($record->start_at),
                        self::jalali($record->expire_at),
                    ])
                    ->listWithLineBreaks()
                    ->sortable(),
                /*
                 * When the logged-in user sent their bid on this tender, or
                 * a dash if they have not. mySuggestion is the eager-loaded
                 * hasOne narrowed to their own user id — see Bid::mySuggestion().
                 *
                 * ->state() rather than a dotted column name because a
                 * cancelled bid must read as "not sent" here: the user is
                 * free to bid again, so showing the old timestamp would be a
                 * lie. ->placeholder('—') is what renders the null.
                 */
                TextColumn::make('my_suggestion_submitted_at')
                    ->label('ارسال پیشنهاد')
                    ->state(fn (Bid $record) => self::liveSuggestion($record)?->submitted_at)
                    ->jalaliDateTime()
                    ->placeholder('—')
                    ->visible(fn (): bool => self::isUser()),
                /*
                 * The step that bid is at, in Persian. See
                 * BidSuggestion::getStatusLabel() for the full ladder and
                 * App\Enums\SuggestionStatus for what is still TODO.
                 *
                 * A saved-but-unfinished wizard shows «پیش‌نویس» here. Note
                 * that comes from draftSuggestion(), NOT liveSuggestion() —
                 * a draft is deliberately invisible to every other rule in
                 * this file, and this column is the one place it should show.
                 */
                TextColumn::make('my_suggestion_status')
                    // Just «وضعیت»: the word پیشنهاد was costing width, and
                    // for a user every column on this row is already about
                    // their own bid. No clash with the status_label column
                    // above — that one is staff/admin-only, this one is
                    // user-only, so they are never on screen together.
                    ->label('وضعیت')
                    ->badge()
                    ->state(fn (Bid $record): string => self::ownSuggestion($record)?->getStatusLabel() ?? 'ارسال نشده')
                    ->color(fn (Bid $record): string => self::ownSuggestion($record)?->getStatusColor() ?? 'gray')
                    ->visible(fn (): bool => self::isUser()),
                /*
                 * The «کد پیگیری» issued at finalisation. It is the one thing
                 * the user is told to keep, so it belongs on the row and not
                 * only in the success toast that produced it.
                 *
                 * ->copyable() because eight digits is exactly long enough to
                 * transcribe wrong.
                 */
                TextColumn::make('my_suggestion_tracking_code')
                    // Shortened from «کد پیگیری» to fit the row; the full
                    // wording still appears in the «مشاهده پیشنهاد» modal and
                    // in the copy confirmation below.
                    ->label('رهگیری')
                    ->state(fn (Bid $record): ?string => self::liveSuggestion($record)?->tracking_code)
                    ->placeholder('—')
                    ->copyable()
                    ->copyMessage('کد پیگیری کپی شد.')
                    ->visible(fn (): bool => self::isUser()),
                // The bid price — the sum of every priced line. number_format,
                // not ->numeric(), to keep Latin digits like every other
                // number in the panel.
                TextColumn::make('my_suggestion_total')
                    ->label('مبلغ (ریال)')
                    ->state(fn (Bid $record): ?string => filled(self::liveSuggestion($record)?->total_price)
                        ? number_format(self::liveSuggestion($record)->total_price)
                        : null)
                    ->placeholder('—')
                    ->visible(fn (): bool => self::isUser()),
                // How many live bids this tender carries — i.e. also why it
                // is locked, for staff looking at a missing edit button.
                TextColumn::make('active_suggestions_count')
                    ->label('پیشنهادها')
                    ->badge()
                    ->color(fn (Bid $record): string => $record->activeSuggestions->isEmpty() ? 'gray' : 'info')
                    ->state(fn (Bid $record): string => (string) $record->activeSuggestions->count())
                    ->visible(fn (): bool => ! self::isUser()),
                // The dot means "follow the creator relationship, then read
                // display_name from it" — company name, or person's name.
                TextColumn::make('creator.display_name')
                    ->label('ایجادکننده')
                    ->visible(fn (): bool => ! self::isUser()),
            ])
            // Buttons at the end of each row.
            ->recordActions([
                self::viewDetailsAction(),
                self::viewGoodsAction(),
                self::viewMySuggestionAction(),
                self::viewSuggestionsAction(),
                self::openEnvelopeAction(EnvelopeStage::A),
                self::openEnvelopeAction(EnvelopeStage::B),
                self::winnersAction(),
                EditAction::make()
                    // Filament also asks BidPolicy::update() and hides this
                    // by itself once the tender is locked — which is exactly
                    // why lockedAction() below exists to explain the gap.
                    ->visible(fn (): bool => ! self::isUser()),
                self::lockedAction(),
                self::cancelSuggestionsAction(),
                self::withdrawSuggestionAction(),
                self::suggestAction(),
            ])
            // Buttons above the table, acting on checkbox-selected rows.
            ->toolbarActions([
                BulkActionGroup::make([
                    // Locked tenders are skipped here too: DeleteBulkAction
                    // authorizes BidPolicy::delete() per record.
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /*
     * ---- Small shared helpers -------------------------------------------
     */

    /** Is the person looking at the table a plain user (not staff/admin)? */
    private static function isUser(): bool
    {
        return Auth::user()->hasRole(RoleName::User->value);
    }

    /**
     * One stored Gregorian date, as the Jalali string the panel shows.
     *
     * This is exactly what the ->jalaliDateTime() column/entry macro does —
     * spelled out here because the merged «شروع / پایان» column builds its own
     * state and so never passes through that macro. It reads the same
     * config key, so changing the format still changes it everywhere.
     */
    private static function jalali(?CarbonInterface $date): string
    {
        return $date
            ? Jalali::fromCarbon($date)->format(config('filament-jalali.date_time_format'))
            : '—';
    }

    /** Is the person looking at the table an admin? */
    private static function isAdmin(): bool
    {
        return Auth::user()->hasRole(RoleName::Admin->value);
    }

    /**
     * The current user's bid on this tender — but only if it still counts.
     *
     * A cancelled bid returns null everywhere on purpose: to the user, an
     * admin-cancelled bid means "you have not bid on this tender", and the
     * «ارسال پیشنهاد» button comes back. An unfinished DRAFT returns null for
     * the same reason — it is not a bid yet (see SuggestionStatus::isActive()).
     */
    private static function liveSuggestion(Bid $record): ?BidSuggestion
    {
        $suggestion = $record->mySuggestion;

        return $suggestion?->status->isActive() ? $suggestion : null;
    }

    /** The current user's unfinished wizard for this tender, if any. */
    private static function draftSuggestion(Bid $record): ?BidSuggestion
    {
        $suggestion = $record->mySuggestion;

        return $suggestion?->isDraft() ? $suggestion : null;
    }

    /**
     * The row the user actually has here, live or draft — used only by the
     * «وضعیت» column, which is the one place a draft should be
     * visible. Everything else deliberately uses liveSuggestion().
     */
    private static function ownSuggestion(Bid $record): ?BidSuggestion
    {
        return self::liveSuggestion($record) ?? self::draftSuggestion($record);
    }

    /*
     * ---- Read-only modals -------------------------------------------------
     */

    /**
     * Eye icon — read-only title / description / attachments / start / end,
     * for every role.
     *
     * Built from infolist entries rather than a hand-written Blade view on
     * purpose: the panel's compiled CSS ships only Filament's own `fi-*`
     * classes (no Tailwind utilities), so custom markup would render
     * unstyled. The same applies to every other modal in this file.
     */
    private static function viewDetailsAction(): Action
    {
        return Action::make('viewDetails')
            ->label('مشاهده')
            ->icon(Heroicon::OutlinedEye)
            ->iconButton()          // icon only, no text label on the row
            ->modalHeading(fn (Bid $record): string => $record->title)
            ->modalWidth(Width::ThreeExtraLarge)
            // ->schema() here describes the MODAL's contents, not a form.
            ->schema([
                // Same Jalali macro as the table columns, on the infolist
                // side — see the note on the start_at column above.
                TextEntry::make('start_at')
                    ->label('تاریخ و ساعت شروع')
                    ->jalaliDateTime(),
                TextEntry::make('expire_at')
                    ->label('تاریخ و ساعت پایان')
                    ->jalaliDateTime(),
                TextEntry::make('description')
                    ->label('شرح مناقصه')
                    // Re-serialised through Filament's own rich-content
                    // renderer (Tiptap's allowed node/mark set), so markup
                    // outside the editor's vocabulary can't reach the page.
                    ->formatStateUsing(fn (?string $state) => RichContentRenderer::make($state)->toHtml())
                    ->html()
                    ->prose()
                    ->columnSpanFull(),
                /*
                 * The tender's uploaded files, under the description — the
                 * documents are half the tender, so a reader who only gets
                 * the text is missing the point.
                 *
                 * The state is the BidAttachment models themselves rather
                 * than strings, which is what lets each filename carry its
                 * own download link: Filament resolves formatStateUsing()
                 * and url() once per item in the list.
                 */
                TextEntry::make('attachments')
                    ->label('پیوست‌ها')
                    ->placeholder('برای این مناقصه فایلی پیوست نشده است.')
                    ->state(fn (Bid $record): array => $record->attachments->all())
                    ->formatStateUsing(fn (BidAttachment $state): string => $state->original_name)
                    ->url(fn (BidAttachment $state): string => Storage::disk($state->disk)->url($state->path))
                    ->openUrlInNewTab()
                    ->icon(Heroicon::OutlinedPaperClip)
                    // One file per line, instead of a comma-separated run.
                    ->listWithLineBreaks()
                    ->columnSpanFull(),
            ])
            // No save button — this modal is read-only, so the only way out
            // is the «بستن» (close) button.
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('بستن');
    }

    /**
     * Clipboard icon — the tender's کالاهای مورد نیاز rows, for every role.
     */
    private static function viewGoodsAction(): Action
    {
        return Action::make('viewGoods')
            ->label('کالاهای مورد نیاز')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->iconButton()
            ->modalHeading(fn (Bid $record): string => "کالاهای مورد نیاز — {$record->title}")
            ->modalWidth(Width::FiveExtraLarge)
            ->schema([
                // RepeatableEntry renders one block per related row — here,
                // one line per «کالای مورد نیاز», laid out as a table.
                RepeatableEntry::make('goodRequirements')
                    ->hiddenLabel()
                    ->placeholder('برای این مناقصه هنوز کالایی تعریف نشده است.')
                    ->table([
                        TableColumn::make('کد کالا'),
                        TableColumn::make('شرح کالا'),
                        TableColumn::make('ابعاد و مشخصات فنی'),
                        TableColumn::make('تعداد'),
                        TableColumn::make('نقشه'),
                    ])
                    ->schema([
                        TextEntry::make('good.code')->hiddenLabel(),
                        TextEntry::make('good.name')->hiddenLabel(),
                        TextEntry::make('good.specifications')->hiddenLabel(),
                        // number_format, not ->numeric(): the latter localises
                        // to Persian digits (۱٬۰۰۰), which would clash with
                        // the Latin digits every other table in the panel
                        // uses for dates and sizes.
                        TextEntry::make('quantity')
                            ->hiddenLabel()
                            ->formatStateUsing(fn (int $state): string => number_format($state)),
                        // State is the GoodDrawing models themselves, so each
                        // filename can carry its own download link — Filament
                        // resolves formatStateUsing/url per list item.
                        TextEntry::make('good.drawings')
                            ->hiddenLabel()
                            ->placeholder('—')
                            ->state(fn (BidGoodRequirement $record): array => $record->good?->drawings->all() ?? [])
                            ->formatStateUsing(fn (GoodDrawing $state): string => $state->original_name)
                            ->url(fn (GoodDrawing $state): string => Storage::disk($state->disk)->url($state->path))
                            ->openUrlInNewTab()
                            ->listWithLineBreaks(),
                    ]),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('بستن');
    }

    /**
     * User role — re-open your own submitted bid, read-only.
     *
     * Deliberately not editable: a bid is an offer, and letting it be edited
     * after the fact (especially once the tender has closed) would make the
     * submission time on the row meaningless. Changing a bid means asking an
     * admin to cancel it and sending a new one.
     */
    private static function viewMySuggestionAction(): Action
    {
        return Action::make('viewMySuggestion')
            ->label('مشاهده پیشنهاد')
            ->icon(Heroicon::OutlinedDocumentText)
            ->iconButton()
            ->color('info')
            ->visible(fn (Bid $record): bool => self::isUser() && self::liveSuggestion($record) !== null)
            ->modalHeading(fn (Bid $record): string => "پیشنهاد شما — {$record->title}")
            ->modalWidth(Width::FiveExtraLarge)
            ->schema([
                TextEntry::make('my_tracking_code')
                    ->label('کد پیگیری')
                    ->badge()
                    ->color('success')
                    ->placeholder('—')
                    ->copyable()
                    ->state(fn (Bid $record): ?string => self::liveSuggestion($record)?->tracking_code),
                TextEntry::make('my_submitted_at')
                    ->label('تاریخ و ساعت ارسال')
                    ->state(fn (Bid $record) => self::liveSuggestion($record)?->submitted_at)
                    ->jalaliDateTime(),
                TextEntry::make('my_status')
                    ->label('وضعیت')
                    ->badge()
                    ->state(fn (Bid $record): string => self::liveSuggestion($record)?->getStatusLabel() ?? '—')
                    ->color(fn (Bid $record): string => self::liveSuggestion($record)?->getStatusColor() ?? 'gray'),
                /*
                 * The priced lines. The state is the BidSuggestionItem models
                 * themselves, so each row can reach through to the tender's
                 * requirement and from there to the good — which is where the
                 * code, name and requested quantity live. Nothing about the
                 * good is copied onto the item row (see that migration).
                 */
                RepeatableEntry::make('my_items')
                    ->label('کالاهای پیشنهادشده')
                    ->state(fn (Bid $record): array => self::liveSuggestion($record)?->items->all() ?? [])
                    ->placeholder('برای هیچ کالایی قیمت ثبت نشده است.')
                    ->columnSpanFull()
                    ->table([
                        TableColumn::make('کد کالا'),
                        TableColumn::make('شرح کالا'),
                        TableColumn::make('تعداد'),
                        TableColumn::make('قیمت واحد (ریال)'),
                        TableColumn::make('جمع (ریال)'),
                    ])
                    ->schema([
                        TextEntry::make('requirement.good.code')->hiddenLabel(),
                        TextEntry::make('requirement.good.name')->hiddenLabel(),
                        TextEntry::make('requirement.quantity')
                            ->hiddenLabel()
                            ->formatStateUsing(fn (?int $state): string => number_format((int) $state)),
                        TextEntry::make('unit_price')
                            ->hiddenLabel()
                            ->formatStateUsing(fn (?int $state): string => number_format((int) $state)),
                        TextEntry::make('total_price')
                            ->hiddenLabel()
                            ->formatStateUsing(fn (?int $state): string => number_format((int) $state)),
                    ]),
                TextEntry::make('my_total')
                    ->label('جمع کل پیشنهاد (ریال)')
                    ->weight('bold')
                    ->state(fn (Bid $record): string => number_format(
                        (int) self::liveSuggestion($record)?->total_price
                    )),
                /*
                 * The goods the user offered a DIFFERENT specification for, in
                 * the wizard's «مشخصات فنی کالاها» step. Only those goods have
                 * a row (an empty box means «مشخصات کارفرما را میپذیرم»), so an
                 * empty list here reads as "I accepted everything as specified"
                 * — which is what the placeholder says.
                 */
                RepeatableEntry::make('my_specifications')
                    ->label('مشخصات فنی قابل تامین')
                    ->state(fn (Bid $record): array => self::liveSuggestion($record)?->specifications->all() ?? [])
                    ->placeholder('برای همه کالاها مشخصات فنی کارفرما را پذیرفته‌اید.')
                    ->columnSpanFull()
                    ->table([
                        TableColumn::make('کد کالا'),
                        TableColumn::make('شرح کالا'),
                        TableColumn::make('مشخصات فنی قابل تامین'),
                    ])
                    ->schema([
                        TextEntry::make('requirement.good.code')->hiddenLabel(),
                        TextEntry::make('requirement.good.name')->hiddenLabel(),
                        TextEntry::make('specifications')->hiddenLabel(),
                    ]),
                TextEntry::make('my_note')
                    ->label('متن پیشنهاد')
                    ->placeholder('—')
                    ->state(fn (Bid $record): ?string => self::liveSuggestion($record)?->note)
                    ->columnSpanFull(),
                // Same "state is the model, not a string" trick the tender's
                // own attachment list uses, so each filename carries its own
                // download URL.
                TextEntry::make('my_payment_type')
                    ->label('روش پرداخت ودیعه')
                    ->placeholder('—')
                    ->state(fn (Bid $record): ?string => self::liveSuggestion($record)?->payment_type?->getLabel()),
                self::suggestionFilesEntry('my_documents', 'پیوست‌ها', SuggestionAttachmentType::Document),
                self::suggestionFilesEntry('my_bank_guarantee', 'ضمانت‌نامه بانکی', SuggestionAttachmentType::BankGuaranteeLetter),
                self::suggestionFilesEntry('my_claims_decrease_attachment', 'پیوست نامه کسر از مطالبات', SuggestionAttachmentType::ClaimsDecreaseAttachment),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('بستن');
    }

    /**
     * One downloadable file list from the user's own bid.
     *
     * Factored out because the modal shows two of them (documents and
     * receipts) that differ only in which `type` they filter on.
     */
    private static function suggestionFilesEntry(string $name, string $label, SuggestionAttachmentType $type): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->placeholder('فایلی بارگذاری نشده است.')
            ->state(fn (Bid $record): array => self::liveSuggestion($record)
                ?->attachments
                ->where('type', $type)
                ->values()
                ->all() ?? [])
            ->formatStateUsing(fn (BidSuggestionAttachment $state): string => $state->original_name)
            ->url(fn (BidSuggestionAttachment $state): string => $state->url)
            ->openUrlInNewTab()
            ->icon(Heroicon::OutlinedPaperClip)
            ->listWithLineBreaks()
            ->columnSpanFull();
    }

    /**
     * Staff/admin — every live bid on this tender, read-only.
     *
     * The overview, not the review: the decisions themselves are made on
     * App\Filament\Resources\Bids\Pages\OpenEnvelope, reached from the
     * letter icons (openEnvelopeAction() below). Bidders are masked here, and
     * amounts stay hidden until پاکت الف is finalised — see the notes inside.
     */
    private static function viewSuggestionsAction(): Action
    {
        return Action::make('viewSuggestions')
            ->label('پیشنهادهای دریافتی')
            ->icon(Heroicon::OutlinedInboxStack)
            ->iconButton()
            ->color('info')
            ->visible(fn (Bid $record): bool => ! self::isUser() && $record->activeSuggestions->isNotEmpty())
            ->modalHeading(fn (Bid $record): string => "پیشنهادهای دریافتی — {$record->title}")
            ->modalWidth(Width::FiveExtraLarge)
            ->schema([
                RepeatableEntry::make('activeSuggestions')
                    ->hiddenLabel()
                    ->placeholder('هنوز پیشنهادی برای این مناقصه ارسال نشده است.')
                    /*
                     * «مبلغ کل» is deliberately ABSENT until پاکت الف has been
                     * finalised.
                     *
                     * The two-envelope process only means something if the
                     * technical judgement is made without the amounts in view —
                     * and this modal would have shown every offer's total right
                     * next to it. So the column (and the entry below it) appear
                     * only once الف is submitted, i.e. once the financial
                     * envelope is the stage the tender is actually at.
                     */
                    ->table(fn (Bid $record): array => array_values(array_filter([
                        TableColumn::make('پیشنهاددهنده'),
                        TableColumn::make('کد پیگیری'),
                        TableColumn::make('تاریخ و ساعت ارسال'),
                        TableColumn::make('وضعیت'),
                        $record->envelopeIsSubmitted(EnvelopeStage::A)
                            ? TableColumn::make('مبلغ کل (ریال)')
                            : null,
                        TableColumn::make('روش پرداخت ودیعه'),
                        TableColumn::make('متن پیشنهاد'),
                    ])))
                    ->schema([
                        /*
                         * «مخفی شده» — NOT the bidder's name.
                         *
                         * An admin must not be able to tell whose offer they
                         * are looking at while a tender is being reviewed, so
                         * every screen an offer appears on shows the masked
                         * value; only the WINNERS are unmasked, and only once
                         * پاکت ب has been finalised. The rule itself lives in
                         * BidSuggestion::bidderNameForAdmin(), so this modal,
                         * the «لغو» modal and the envelope pages can never
                         * disagree about it.
                         */
                        TextEntry::make('bidder_name')
                            ->hiddenLabel()
                            ->state(fn (BidSuggestion $record): string => $record->bidderNameForAdmin()),
                        TextEntry::make('tracking_code')->hiddenLabel()->placeholder('—'),
                        TextEntry::make('submitted_at')->hiddenLabel()->jalaliDateTime(),
                        TextEntry::make('status')
                            ->hiddenLabel()
                            ->badge()
                            ->state(fn (BidSuggestion $record): string => $record->getStatusLabel())
                            ->color(fn (BidSuggestion $record): string => $record->getStatusColor()),
                        // number_format, not ->numeric(): Latin digits, like
                        // every other number in the panel. Hidden until پاکت
                        // الف is finalised — see the ->table() note above.
                        TextEntry::make('total_price')
                            ->hiddenLabel()
                            ->placeholder('—')
                            ->visible(fn (BidSuggestion $record): bool => (bool) $record->bid
                                ?->envelopeIsSubmitted(EnvelopeStage::A))
                            ->formatStateUsing(fn (?int $state): string => number_format((int) $state)),
                        TextEntry::make('payment_type')
                            ->hiddenLabel()
                            ->placeholder('—')
                            ->state(fn (BidSuggestion $record): ?string => $record->payment_type?->getLabel()),
                        TextEntry::make('note')->hiddenLabel()->placeholder('—'),
                    ]),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('بستن');
    }

    /*
     * ---- The admin's two-envelope review ----------------------------------
     */

    /**
     * The closed-letter icon: «بازکردن پاکت الف», then «بازکردن پاکت ب».
     *
     * ONE action per stage, each with its own ->visible() rule, rather than one
     * action that changes what it does — because an action either navigates
     * (->url()) or opens a modal, and these three states need two of the
     * former and one of the latter (see winnersAction()).
     *
     * What the admin sees on a row, in order over the tender's life:
     *   closed letter, primary  — الف can be opened (tender expired, offers
     *                             exist, الف not finalised);
     *   closed letter, orange   — الف is done, ب is waiting;
     *   open letter, grey       — both done; the icon now opens the win board.
     * The icon stays a CLOSED letter for both stages on purpose: it only opens
     * when the whole review is over, which is what makes "is this tender
     * finished?" readable at a glance down the column.
     *
     * Admin only, like «لغو» — Bid::envelopeIsOpenable() and the page's own
     * guard() re-check that server-side, so hiding the button is a courtesy,
     * not the security boundary.
     */
    private static function openEnvelopeAction(EnvelopeStage $stage): Action
    {
        return Action::make('openEnvelope'.strtoupper($stage->value))
            ->label($stage->openLabel())
            ->tooltip($stage->openLabel())
            ->icon(Heroicon::OutlinedEnvelope)
            ->iconButton()
            // پاکت الف is the ordinary next step; پاکت ب is highlighted in
            // orange to say "this tender is half-reviewed, it needs you again".
            ->color($stage === EnvelopeStage::A ? 'primary' : 'warning')
            ->visible(fn (Bid $record): bool => self::isAdmin() && $record->envelopeIsOpenable($stage))
            // A plain navigation, so there is no ->action() to run: the page on
            // the other end does the whole job.
            ->url(fn (Bid $record): string => BidResource::getUrl('envelope', [
                'record' => $record,
                'stage' => $stage->value,
            ]));
    }

    /**
     * «تخته برندگان» — the open-letter icon, once both envelopes are finalised.
     *
     * This is the ONE screen in the app where an admin sees who a bidder was:
     * the review is over, the winners are decided, and their contact and
     * registration details are exactly what is needed to award the contract.
     * Non-winners stay anonymous forever — they are simply not on this list.
     */
    private static function winnersAction(): Action
    {
        return Action::make('winners')
            ->label('تخته برندگان')
            ->tooltip('تخته برندگان')
            ->icon(Heroicon::OutlinedEnvelopeOpen)
            ->iconButton()
            // Grey: nothing is left to do on this tender.
            ->color('gray')
            ->visible(fn (Bid $record): bool => ! self::isUser() && $record->reviewIsFinished())
            ->modalHeading(fn (Bid $record): string => "برندگان مناقصه — {$record->title}")
            ->modalWidth(Width::SevenExtraLarge)
            ->schema([
                TextEntry::make('envelope_dates')
                    ->label('تاریخ ثبت نهایی پاکت‌ها')
                    ->state(fn (Bid $record): array => [
                        'پاکت الف: '.self::jalali($record->envelope_a_submitted_at),
                        'پاکت ب: '.self::jalali($record->envelope_b_submitted_at),
                    ])
                    ->listWithLineBreaks()
                    ->columnSpanFull(),
                RepeatableEntry::make('winners')
                    ->hiddenLabel()
                    ->state(fn (Bid $record): array => $record->winners()->all())
                    ->placeholder('هیچ پیشنهادی در پاکت ب تایید نشد؛ این مناقصه برنده‌ای ندارد.')
                    ->columnSpanFull()
                    ->table([
                        TableColumn::make('برنده'),
                        TableColumn::make('نام و نام خانوادگی'),
                        TableColumn::make('شماره موبایل'),
                        TableColumn::make('کد ملی / شناسه ملی'),
                        TableColumn::make('کد پیگیری'),
                        TableColumn::make('مبلغ کل (ریال)'),
                    ])
                    ->schema([
                        // display_name is the company name for a حقوقی account
                        // and the person's full name otherwise.
                        TextEntry::make('user.display_name')->hiddenLabel(),
                        TextEntry::make('winner_person_name')
                            ->hiddenLabel()
                            ->state(fn (BidSuggestion $record): string => trim(
                                "{$record->user?->first_name} {$record->user?->last_name}"
                            )),
                        TextEntry::make('user.mobile')
                            ->hiddenLabel()
                            ->copyable(),
                        // Both IDs on two lines: a حقوقی winner has a شناسه
                        // ملی as well as the signatory's own کد ملی, and the
                        // contract needs whichever applies.
                        TextEntry::make('winner_ids')
                            ->hiddenLabel()
                            ->state(fn (BidSuggestion $record): array => array_values(array_filter([
                                filled($record->user?->national_id) ? 'کد ملی: '.$record->user->national_id : null,
                                filled($record->user?->company_national_id) ? 'شناسه ملی: '.$record->user->company_national_id : null,
                            ])))
                            ->listWithLineBreaks(),
                        TextEntry::make('tracking_code')->hiddenLabel()->placeholder('—'),
                        TextEntry::make('total_price')
                            ->hiddenLabel()
                            ->formatStateUsing(fn (?int $state): string => number_format((int) $state)),
                    ]),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('بستن');
    }

    /*
     * ---- Locking and cancelling -------------------------------------------
     */

    /**
     * A lock icon standing where «ویرایش» would be.
     *
     * BidPolicy::update() refuses locked tenders, so Filament removes the
     * edit button on its own — silently. This action exists purely so the
     * absence is explained: clicking it says why the tender is frozen and
     * what to do about it.
     */
    private static function lockedAction(): Action
    {
        return Action::make('locked')
            ->label('قفل‌شده')
            ->icon(Heroicon::OutlinedLockClosed)
            ->iconButton()
            ->color('danger')
            ->visible(fn (Bid $record): bool => ! self::isUser() && $record->isLocked())
            ->modalHeading('این مناقصه قفل شده است')
            ->modalDescription('روی این مناقصه پیشنهاد ثبت شده است، بنابراین ویرایش و حذف آن ممکن نیست؛ در غیر این صورت شرایطی که کاربران بر اساس آن پیشنهاد داده‌اند تغییر می‌کرد. برای باز کردن قفل، مدیر می‌تواند پیشنهادهای ثبت‌شده را لغو کند.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('بستن');
    }

    /**
     * Admin only — cancel one or more bids on this tender.
     *
     * Cancelling is the single escape hatch from the lock: it marks the
     * chosen bids «لغو شده» (keeping who/when/why for support), unfreezes
     * the tender for editing, and lets those users bid again.
     *
     * Staff deliberately do NOT get this button — the requirement says admin
     * access. If that changes, widen the isAdmin() check below and nothing
     * else needs touching.
     */
    private static function cancelSuggestionsAction(): Action
    {
        return Action::make('cancelSuggestions')
            ->label('لغو')
            ->icon(Heroicon::OutlinedXCircle)
            ->iconButton()
            ->color('danger')
            ->visible(fn (Bid $record): bool => self::isAdmin() && $record->activeSuggestions->isNotEmpty())
            ->modalHeading(fn (Bid $record): string => "لغو پیشنهاد — {$record->title}")
            ->modalDescription('پیشنهادهای انتخاب‌شده لغو می‌شوند. کاربر می‌تواند دوباره پیشنهاد بدهد و قفل ویرایش مناقصه برداشته می‌شود.')
            ->modalSubmitActionLabel('لغو پیشنهادها')
            ->schema([
                CheckboxList::make('suggestion_ids')
                    ->label('پیشنهادها')
                    ->required()
                    /*
                     * Options are built per record: id => "who — when".
                     * Jalali::fromCarbon() is the same conversion the
                     * ->jalaliDateTime() macros do; it is spelled out here
                     * because this is a plain string, not a column.
                     */
                    /*
                     * The bidder is masked here too (see the «پیشنهادهای
                     * دریافتی» modal above for why), so each option is
                     * identified by its «کد پیگیری» and submission time —
                     * enough to tell two offers apart without saying whose
                     * they are.
                     */
                    ->options(fn (Bid $record): array => $record->activeSuggestions
                        ->mapWithKeys(fn (BidSuggestion $suggestion): array => [
                            $suggestion->id => $suggestion->bidderNameForAdmin()
                                .' — '
                                .($suggestion->tracking_code ?: '—')
                                .' — '
                                // submitted_at is only null for rows created
                                // before it existed, hence the dash fallback.
                                .($suggestion->submitted_at
                                    ? Jalali::fromCarbon($suggestion->submitted_at)
                                        ->format(config('filament-jalali.date_time_format'))
                                    : '—'),
                        ])
                        ->all()),
                Textarea::make('cancel_reason')
                    ->label('دلیل لغو (اختیاری)')
                    ->maxLength(500)
                    ->rows(3),
            ])
            ->action(function (array $data, Bid $record): void {
                $admin = Auth::user();

                /*
                 * Filtering by the record's own live bids — not just trusting
                 * the submitted ids — is what stops a crafted request from
                 * cancelling a bid on some other tender.
                 */
                $cancelled = $record->activeSuggestions
                    ->whereIn('id', $data['suggestion_ids'])
                    ->each(fn (BidSuggestion $suggestion) => $suggestion->cancel(
                        $admin,
                        $data['cancel_reason'] ?: null,
                    ))
                    ->count();

                Notification::make()
                    ->title("{$cancelled} پیشنهاد لغو شد.")
                    ->success()
                    ->send();
            });
    }

    /*
     * ---- Submitting a bid --------------------------------------------------
     */

    /**
     * User role — open the bid wizard for this tender.
     *
     * A link, not a modal: the bid is a five-step wizard with a price table,
     * ten uploads and an SMS confirmation, and it saves itself as a draft on
     * the server between steps — none of which fits in a dialog. See
     * App\Filament\Resources\Bids\Pages\SubmitSuggestion for the flow.
     *
     * The label changes to «ادامه پیش‌نویس» when there is already a saved,
     * unfinished draft, because "ارسال پیشنهاد" would suggest starting over
     * and losing it — which is exactly the fear that stops people using a
     * draft feature at all.
     *
     * The button hides once the user has a LIVE bid here, and comes back if
     * an admin cancels it or the user withdraws it. The unique
     * (bid_id, user_id) index is the real guarantee regardless of this
     * check — which is also why the wizard reuses the existing row rather
     * than inserting a second one (BidSuggestion::startDraft()).
     */
    private static function suggestAction(): Action
    {
        return Action::make('suggest')
            ->label(fn (Bid $record): string => self::draftSuggestion($record)
                ? 'ادامه پیش‌نویس'
                : 'ارسال پیشنهاد')
            ->icon(fn (Bid $record): Heroicon => self::draftSuggestion($record)
                ? Heroicon::OutlinedPencilSquare
                : Heroicon::OutlinedPaperAirplane)
            ->color(fn (Bid $record): string => self::draftSuggestion($record) ? 'warning' : 'primary')
            ->visible(fn (Bid $record): bool => self::isUser() && self::liveSuggestion($record) === null)
            // A plain navigation, so there is no ->action() to run: the page
            // on the other end does the whole job.
            ->url(fn (Bid $record): string => BidResource::getUrl('suggest', ['record' => $record]));
    }

    /**
     * User role — take your own submitted bid back, permanently.
     *
     * This is NOT the admin's «لغو». That one marks the row «لغو شده» and
     * keeps who/when/why, because it is a correction made to somebody else's
     * bid and there has to be a record of it. This one is the bidder
     * withdrawing their own offer, and the requirement is explicit that it
     * removes the bid entirely — row, priced lines and uploaded files
     * (BidSuggestion::purge()).
     *
     * Only while the tender is still open: after the deadline the offers are
     * being compared against each other, and pulling one out at that point
     * would be a different product. Editing stays forbidden either way — the
     * way to change a bid is to withdraw it and send a new one, which keeps
     * the «ارسال پیشنهاد» timestamp honest.
     */
    private static function withdrawSuggestionAction(): Action
    {
        return Action::make('withdrawSuggestion')
            ->label('انصراف از پیشنهاد')
            ->icon(Heroicon::OutlinedTrash)
            ->iconButton()
            ->color('danger')
            ->visible(fn (Bid $record): bool => self::isUser()
                && (bool) self::liveSuggestion($record)?->isWithdrawable())
            ->requiresConfirmation()
            ->modalHeading('انصراف از پیشنهاد')
            ->modalDescription('پیشنهاد شما به‌طور کامل و برای همیشه حذف می‌شود؛ قیمت‌ها، پیوست‌ها و فایل‌های پرداخت ودیعه نیز پاک خواهند شد. پس از حذف می‌توانید تا پایان مهلت مناقصه پیشنهاد تازه‌ای ارسال کنید.')
            ->modalSubmitActionLabel('بله، حذف کن')
            ->action(function (Bid $record): void {
                $suggestion = self::liveSuggestion($record);

                /*
                 * Re-checked here, not just in ->visible(). The row was
                 * rendered at some point in the past; the deadline can have
                 * passed since, or an admin can have cancelled the bid in the
                 * meantime, and the button would still be sitting on screen.
                 */
                if (! $suggestion?->isWithdrawable()) {
                    Notification::make()
                        ->title('امکان انصراف وجود ندارد.')
                        ->body('مهلت این مناقصه به پایان رسیده است یا پیشنهاد شما دیگر فعال نیست.')
                        ->danger()
                        ->send();

                    return;
                }

                $suggestion->purge();

                Notification::make()
                    ->title('پیشنهاد شما حذف شد.')
                    ->success()
                    ->send();
            });
    }
}
