<?php

namespace App\Filament\Resources\Bids\Tables;

use App\Enums\RoleName;
use App\Models\Bid;
use App\Models\BidGoodRequirement;
use App\Models\GoodDrawing;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
                self::viewDetailsAction(),
                self::viewGoodsAction(),
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
     * Eye icon — read-only title / description / start / end, for every role.
     *
     * Built from infolist entries rather than a hand-written Blade view on
     * purpose: the panel's compiled CSS ships only Filament's own `fi-*`
     * classes (no Tailwind utilities), so custom markup would render
     * unstyled. The same applies to viewGoodsAction below.
     */
    private static function viewDetailsAction(): Action
    {
        return Action::make('viewDetails')
            ->label('مشاهده')
            ->icon(Heroicon::OutlinedEye)
            ->iconButton()
            ->modalHeading(fn (Bid $record): string => $record->title)
            ->schema([
                TextEntry::make('start_at')
                    ->label('تاریخ و ساعت شروع')
                    ->formatStateUsing(fn ($state) => Jalalian::fromDateTime($state)->format('Y/m/d H:i')),
                TextEntry::make('expire_at')
                    ->label('تاریخ و ساعت پایان')
                    ->formatStateUsing(fn ($state) => Jalalian::fromDateTime($state)->format('Y/m/d H:i')),
                TextEntry::make('description')
                    ->label('شرح مناقصه')
                    // Re-serialised through Filament's own rich-content
                    // renderer (Tiptap's allowed node/mark set), so markup
                    // outside the editor's vocabulary can't reach the page.
                    ->formatStateUsing(fn (?string $state) => RichContentRenderer::make($state)->toHtml())
                    ->html()
                    ->prose()
                    ->columnSpanFull(),
            ])
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
