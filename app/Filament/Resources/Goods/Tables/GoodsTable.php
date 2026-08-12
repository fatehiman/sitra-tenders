<?php

namespace App\Filament\Resources\Goods\Tables;

use App\Models\Good;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Morilog\Jalali\Jalalian;

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
                TextColumn::make('drawings_count')
                    ->label('نقشه')
                    ->counts('drawings')
                    ->badge(),
                TextColumn::make('bid_requirements_count')
                    ->label('مناقصات')
                    ->counts('bidRequirements')
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('تاریخ ثبت')
                    ->formatStateUsing(fn ($state) => $state ? Jalalian::fromDateTime($state)->format('Y/m/d H:i') : '-')
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
            ->before(function (Good $record, DeleteAction $action): void {
                $titles = $record->bidRequirements()
                    ->with('bid:id,title')
                    ->get()
                    ->pluck('bid.title')
                    ->filter()
                    ->unique()
                    ->values();

                if ($titles->isEmpty()) {
                    return;
                }

                Notification::make()
                    ->title('این کالا قابل حذف نیست')
                    ->body('این کالا در مناقصات زیر استفاده شده است: '.$titles->implode('، '))
                    ->danger()
                    ->persistent()
                    ->send();

                $action->halt();
            });
    }
}
