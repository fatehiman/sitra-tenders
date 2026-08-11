<?php

namespace App\Filament\Resources\Bids\Tables;

use App\Enums\RoleName;
use App\Models\Bid;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Morilog\Jalali\Jalalian;

class BidsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => Auth::user()->hasRole(RoleName::User->value)
                ? $query->active()
                : $query)
            ->columns([
                TextColumn::make('title')
                    ->label('عنوان')
                    ->searchable(),
                TextColumn::make('status_label')
                    ->label('وضعیت')
                    ->badge()
                    ->visible(fn () => ! Auth::user()->hasRole(RoleName::User->value)),
                TextColumn::make('start_at')
                    ->label('شروع')
                    ->formatStateUsing(fn ($state) => Jalalian::fromDateTime($state)->format('Y/m/d H:i'))
                    ->sortable(),
                TextColumn::make('expire_at')
                    ->label('پایان')
                    ->formatStateUsing(fn ($state) => Jalalian::fromDateTime($state)->format('Y/m/d H:i'))
                    ->sortable(),
                TextColumn::make('creator.display_name')
                    ->label('ایجادکننده')
                    ->visible(fn () => ! Auth::user()->hasRole(RoleName::User->value)),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => ! Auth::user()->hasRole(RoleName::User->value)),
                self::suggestAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Scaffold only, per the explicit requirement: a wizard modal with a
     * single free-text field. The button hides once the user already has
     * a suggestion for this bid — enforced again at the DB layer by the
     * unique (bid_id, user_id) constraint regardless of this check.
     */
    private static function suggestAction(): Action
    {
        return Action::make('suggest')
            ->label('ارسال پیشنهاد')
            ->visible(function (Bid $record): bool {
                $user = Auth::user();

                return $user->hasRole(RoleName::User->value)
                    && ! $record->suggestions()->where('user_id', $user->id)->exists();
            })
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
            ->action(function (array $data, Bid $record): void {
                $record->suggestions()->create([
                    'user_id' => Auth::id(),
                    'note' => $data['note'],
                ]);

                Notification::make()
                    ->title('پیشنهاد شما با موفقیت ثبت شد.')
                    ->success()
                    ->send();
            });
    }
}
