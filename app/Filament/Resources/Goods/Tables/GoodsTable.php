<?php

namespace App\Filament\Resources\Goods\Tables;

use App\Models\Good;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The کالاها list table.
 *
 * The one genuinely interesting thing here is the delete guard at the
 * bottom: a good that a tender already cites must not disappear.
 */
class GoodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('code')
                    ->label('کد کالا')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('شرح کالا')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('specifications')
                    ->label('ابعاد و مشخصات فنی')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(),
                // ->counts() adds a single COUNT sub-query to the table's
                // one query, instead of loading every related row per record
                // — the difference between 1 query and hundreds.
                TextColumn::make('drawings_count')
                    ->label('نقشه')
                    ->counts('drawings')
                    ->badge(),
                // Doubles as a warning: a non-zero value here means the
                // delete guard below will refuse to remove this good.
                TextColumn::make('bid_requirements_count')
                    ->label('مناقصات')
                    ->counts('bidRequirements')
                    ->badge(),
                // Jalali display, Gregorian storage and sorting — see the
                // matching column in UsersTable for the details.
                TextColumn::make('created_at')
                    ->label('تاریخ ثبت')
                    ->jalaliDateTime()
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                self::guardedDeleteAction(),
            ]);
        // No bulk delete: deletion has to run the "is this good already used
        // in a tender?" check per-record, and a bulk action can only report a
        // single outcome for the whole selection.
    }

    /**
     * Deleting a good that a tender already cites would silently rewrite that
     * tender's requirement list, so it is refused up-front with a message
     * naming the offending tenders. The `restrictOnDelete` FK on
     * `bid_good_requirements` is the backstop if anything ever gets here by
     * another route.
     */
    private static function guardedDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            // ->before() runs after the operator confirms but before the row
            // is actually deleted — the last chance to call it off.
            ->before(function (Good $record, DeleteAction $action): void {
                // Collect the titles of every tender citing this good.
                // with('bid:id,title') loads the parent tenders in one extra
                // query and fetches only the two columns we need.
                $titles = $record->bidRequirements()
                    ->with('bid:id,title')
                    ->get()
                    ->pluck('bid.title')
                    ->filter()
                    ->unique()
                    ->values();

                // Not used anywhere — deleting is fine, let it proceed.
                if ($titles->isEmpty()) {
                    return;
                }

                Notification::make()
                    ->title('این کالا قابل حذف نیست')
                    ->body('این کالا در مناقصات زیر استفاده شده است: '.$titles->implode('، '))
                    ->danger()
                    // Stays on screen until dismissed — the list of tenders
                    // is something the operator has to read and act on.
                    ->persistent()
                    ->send();

                // Cancels the deletion. Without this the row would be
                // deleted anyway, right after showing the warning.
                $action->halt();
            });
    }
}
