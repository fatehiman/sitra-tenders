<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\RoleName;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The کاربران list table: columns, filters, and the edit/delete buttons.
 *
 * The interesting part is the bottom half — deleting an account is
 * permanent and takes that person's پیشنهادها with it, so it is wrapped in
 * a confirmation that says exactly how much is about to disappear, and a
 * guard that refuses outright when the account published tenders.
 */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // display_name is computed in PHP (company name, or person's
                // name), so there is no column to search. The explicit list
                // tells the table which real columns to search instead.
                TextColumn::make('display_name')
                    ->label('نام / نام شرکت')
                    ->searchable(['first_name', 'last_name', 'company_name']),
                TextColumn::make('mobile')
                    ->label('موبایل')
                    ->searchable(),
                TextColumn::make('national_id')
                    ->label('کد ملی')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('person_type')
                    ->label('نوع شخص')
                    ->badge(),
                // Roles live in their own table (spatie/laravel-permission),
                // so this follows the relationship and translates the stored
                // English name into its Persian label. tryFrom() returns null
                // for an unknown value rather than throwing, and the ?? then
                // falls back to showing the raw name.
                TextColumn::make('roles.name')
                    ->label('نقش')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? (RoleName::tryFrom($state)?->label() ?? $state) : '-'),
                // mobile_verified_at holds a date or null; ->boolean() wants
                // true/false, so getStateUsing converts "has a date" into a
                // tick or a cross.
                IconColumn::make('mobile_verified_at')
                    ->label('موبایل تاییدشده')
                    ->boolean()
                    ->getStateUsing(fn ($record): bool => (bool) $record->mobile_verified_at),
                IconColumn::make('is_active')
                    ->label('فعال')
                    ->boolean(),
                // Jalali display, Gregorian storage and sorting — the macro
                // comes from ariaieboy/filament-jalali and reads its format
                // from config/filament-jalali.php. It renders nothing at all
                // for a null value, so ->placeholder() supplies the dash.
                TextColumn::make('created_at')
                    ->label('تاریخ ثبت')
                    ->jalaliDateTime()
                    ->placeholder('-')
                    ->sortable(),
            ])
            // Dropdown filters shown above the table.
            ->filters([
                SelectFilter::make('roles')
                    ->label('نقش')
                    ->relationship('roles', 'name')
                    // Builds ['admin' => 'مدیر سیستم', ...] straight from the
                    // enum, so adding a role never needs editing this file.
                    ->options(array_combine(
                        array_map(fn (RoleName $r) => $r->value, RoleName::cases()),
                        array_map(fn (RoleName $r) => $r->label(), RoleName::cases()),
                    )),
                SelectFilter::make('is_active')
                    ->label('وضعیت')
                    ->options(['1' => 'فعال', '0' => 'غیرفعال']),
            ])
            ->recordActions([
                EditAction::make(),
                self::guardedDeleteAction(),
                self::protectedAction(),
            ]);
        /*
         * No bulk delete, on purpose. Every deletion here has to run a
         * per-record check (does this account own tenders?) and show a
         * per-record count in its confirmation — a bulk action can do
         * neither, and can only report one outcome for a mixed selection.
         * Same reasoning as the کالاها table.
         */
    }

    /*
     * ---- Deleting an account ----------------------------------------------
     */

    /**
     * The «حذف» button.
     *
     * UserPolicy::delete() already decides WHO may be deleted (not yourself,
     * not another admin). This adds the two things a policy cannot express:
     *
     *  - a confirmation that counts what is about to be destroyed, because
     *    the requirement is that the admin sees how many پیشنهاد go with the
     *    account;
     *  - a hard refusal when the account published مناقصات, since
     *    `bids.created_by` cascades and would take other people's bids down
     *    with it (see User::purge()).
     */
    private static function guardedDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalHeading(fn (User $record): string => "حذف کاربر — {$record->display_name}")
            // Computed per record, so the number is this account's own.
            ->modalDescription(fn (User $record): string => self::deletionSummary($record))
            ->modalSubmitActionLabel('حذف قطعی')
            // ->before() runs after the operator confirms but before anything
            // is deleted — the last chance to call it off.
            ->before(function (User $record, DeleteAction $action): void {
                if (! $record->ownsTenders()) {
                    return;
                }

                // Name them: "you cannot delete this" without saying what is
                // in the way leaves the operator with nothing to do next.
                $titles = $record->bids()->pluck('title');

                Notification::make()
                    ->title('این کاربر قابل حذف نیست')
                    ->body(
                        'این کاربر '.$titles->count().' مناقصه ثبت کرده است و حذف او آن مناقصه‌ها و همه پیشنهادهای سایر کاربران روی آن‌ها را نیز حذف می‌کند: '
                        .$titles->implode('، ')
                        .'. ابتدا این مناقصه‌ها را حذف یا به کاربر دیگری منتقل کنید.'
                    )
                    ->danger()
                    // Stays until dismissed — this is a list to act on.
                    ->persistent()
                    ->send();

                $action->halt();
            })
            /*
             * Replaces Filament's own delete with ours. The default calls
             * $record->delete(), which would leave every uploaded file of
             * every پیشنهاد orphaned on disk — purge() is what cleans those
             * up. ->successNotificationTitle() still fires afterwards.
             */
            ->action(function (User $record, DeleteAction $action): void {
                $record->purge();

                $action->success();
            })
            ->successNotificationTitle('کاربر حذف شد');
    }

    /**
     * The sentence inside the delete confirmation.
     *
     * Drafts are counted alongside sent bids: from the admin's point of view
     * both are rows that vanish, and a draft still has uploaded files behind
     * it. Latin digits, like every other number in the panel.
     */
    private static function deletionSummary(User $record): string
    {
        $count = $record->bidSuggestions()->count();

        $bids = $count > 0
            ? "این کاربر {$count} پیشنهاد ثبت کرده است که همگی به همراه فایل‌های پیوست آن‌ها حذف می‌شوند. "
            : 'این کاربر هیچ پیشنهادی ثبت نکرده است. ';

        return $bids.'حذف کاربر قطعی است و امکان بازگرداندن آن وجود ندارد.';
    }

    /**
     * A shield icon standing where «حذف» would be, on admin rows.
     *
     * UserPolicy::delete() returns false for admins, so Filament removes the
     * button on its own — silently. This action exists purely so the absence
     * is explained, the same way BidsTable's lock icon explains a missing
     * «ویرایش». It is not shown on the viewer's own row: "you cannot delete
     * yourself" needs no explaining.
     */
    private static function protectedAction(): Action
    {
        return Action::make('protected')
            ->label('حذف‌نشدنی')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->iconButton()
            ->color('gray')
            ->visible(fn (User $record): bool => $record->isAdmin() && auth()->user()?->isNot($record))
            ->modalHeading('این کاربر قابل حذف نیست')
            ->modalDescription('حساب‌های «مدیر سیستم» حذف نمی‌شوند. اگر واقعاً باید حذف شود، ابتدا نقش او را به «کارشناس» یا «کاربر» تغییر دهید.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('بستن');
    }
}
