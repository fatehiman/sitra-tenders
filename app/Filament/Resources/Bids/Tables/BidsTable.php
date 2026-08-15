<?php

namespace App\Filament\Resources\Bids\Tables;

use App\Enums\RoleName;
use App\Models\Bid;
use App\Models\BidAttachment;
use App\Models\BidGoodRequirement;
use App\Models\BidSuggestion;
use App\Models\GoodDrawing;
use Ariaieboy\Jalali\Jalali;
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
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
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
 *                 («ارسال پیشنهاد» time + «وضعیت پیشنهاد»), can submit one
 *                 and re-open it read-only afterwards.
 *   staff/admin — every tender in every state, how many live bids each has,
 *                 and a lock icon instead of «ویرایش» once anyone has bid.
 *   admin       — additionally «لغو», the only way to unlock a tender.
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
                    return $query->active()->with('mySuggestion');
                }

                // 'activeSuggestions' (not a count) because Bid::isLocked()
                // reads the loaded collection when it is there, and the
                // «لغو» modal needs each bidder's name anyway.
                return $query->with('activeSuggestions.user');
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
                 * Stored Gregorian, shown Jalali. ->jalaliDateTime() is a
                 * macro added to TextColumn by ariaieboy/filament-jalali; it
                 * formats using config('filament-jalali.date_time_format'),
                 * so the format lives in one place for the whole app.
                 *
                 * Sorting is unaffected — it still ORDERs BY the real
                 * Gregorian column, so the order stays chronologically
                 * correct rather than sorting formatted strings.
                 */
                TextColumn::make('start_at')
                    ->label('شروع')
                    ->jalaliDateTime()
                    ->sortable(),
                TextColumn::make('expire_at')
                    ->label('پایان')
                    ->jalaliDateTime()
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
                // The step that bid is at, in Persian. See
                // BidSuggestion::getStatusLabel() for the full ladder and
                // App\Enums\SuggestionStatus for what is still TODO.
                TextColumn::make('my_suggestion_status')
                    ->label('وضعیت پیشنهاد')
                    ->badge()
                    ->state(fn (Bid $record): string => self::liveSuggestion($record)?->getStatusLabel() ?? 'ارسال نشده')
                    ->color(fn (Bid $record): string => self::liveSuggestion($record)?->getStatusColor() ?? 'gray')
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
                EditAction::make()
                    // Filament also asks BidPolicy::update() and hides this
                    // by itself once the tender is locked — which is exactly
                    // why lockedAction() below exists to explain the gap.
                    ->visible(fn (): bool => ! self::isUser()),
                self::lockedAction(),
                self::cancelSuggestionsAction(),
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
     * «ارسال پیشنهاد» button comes back.
     */
    private static function liveSuggestion(Bid $record): ?BidSuggestion
    {
        $suggestion = $record->mySuggestion;

        return $suggestion?->status->isActive() ? $suggestion : null;
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
            ->schema([
                TextEntry::make('my_submitted_at')
                    ->label('تاریخ و ساعت ارسال')
                    ->state(fn (Bid $record) => self::liveSuggestion($record)?->submitted_at)
                    ->jalaliDateTime(),
                TextEntry::make('my_status')
                    ->label('وضعیت')
                    ->badge()
                    ->state(fn (Bid $record): string => self::liveSuggestion($record)?->getStatusLabel() ?? '—')
                    ->color(fn (Bid $record): string => self::liveSuggestion($record)?->getStatusColor() ?? 'gray'),
                TextEntry::make('my_note')
                    ->label('متن پیشنهاد')
                    ->state(fn (Bid $record): ?string => self::liveSuggestion($record)?->note)
                    ->columnSpanFull(),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('بستن');
    }

    /**
     * Staff/admin — every live bid on this tender, read-only.
     *
     * TODO(future): this is where the admin review flow will grow. The
     * requirement is explicit that «فرم الف» and «فرم ب» are specified
     * later; opening each of those forms is what will move a bid's status
     * to FormA/FormB, and accepting/rejecting sets Approved/Rejected. See
     * App\Enums\SuggestionStatus.
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
                    ->table([
                        TableColumn::make('پیشنهاددهنده'),
                        TableColumn::make('تاریخ و ساعت ارسال'),
                        TableColumn::make('وضعیت'),
                        TableColumn::make('متن پیشنهاد'),
                    ])
                    ->schema([
                        // display_name is the company name for a حقوقی
                        // account and the person's full name otherwise.
                        TextEntry::make('user.display_name')->hiddenLabel(),
                        TextEntry::make('submitted_at')->hiddenLabel()->jalaliDateTime(),
                        TextEntry::make('status')
                            ->hiddenLabel()
                            ->badge()
                            ->state(fn (BidSuggestion $record): string => $record->getStatusLabel())
                            ->color(fn (BidSuggestion $record): string => $record->getStatusColor()),
                        TextEntry::make('note')->hiddenLabel(),
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
                    ->options(fn (Bid $record): array => $record->activeSuggestions
                        ->mapWithKeys(fn (BidSuggestion $suggestion): array => [
                            $suggestion->id => $suggestion->user?->display_name
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
     * User role — send a bid on this tender.
     *
     * The content is still a scaffold (one free-text field) per the explicit
     * requirement; the lifecycle around it is not — see
     * App\Enums\SuggestionStatus.
     *
     * The button hides once the user has a live bid here, and comes back if
     * an admin cancels it. Either way the unique (bid_id, user_id) index in
     * the database is the real guarantee, regardless of this check — which
     * is also why re-bidding reuses the existing row via resubmit().
     */
    private static function suggestAction(): Action
    {
        return Action::make('suggest')
            ->label('ارسال پیشنهاد')
            ->visible(fn (Bid $record): bool => self::isUser() && self::liveSuggestion($record) === null)
            ->schema([
                Wizard::make([
                    Step::make('پیشنهاد')
                        ->schema([
                            Textarea::make('note')
                                ->label('متن پیشنهاد')
                                ->required()
                                ->rows(5),
                        ]),
                ]),
            ])
            // ->action() is what runs when the modal is submitted. $data
            // holds the validated field values from the wizard above.
            ->action(function (array $data, Bid $record): void {
                $existing = $record->mySuggestion;

                if ($existing) {
                    // Only reachable when the previous bid was cancelled —
                    // a live one hides this button.
                    $existing->resubmit($data['note']);
                } else {
                    $record->suggestions()->create([
                        // Taken from the session, never from the form.
                        'user_id' => Auth::id(),
                        'note' => $data['note'],
                        'submitted_at' => now(),
                    ]);
                }

                Notification::make()
                    ->title('پیشنهاد شما با موفقیت ثبت شد.')
                    ->success()
                    ->send();
            });
    }
}
