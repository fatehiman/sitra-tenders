<?php

namespace App\Filament\Resources\Goods\RelationManagers;

use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only listing of the نقشه files already attached to the good — new
 * drawings are added through the main form's "نقشه" upload field, not here.
 * Mirrors Bids\RelationManagers\AttachmentsRelationManager.
 */
class DrawingsRelationManager extends RelationManager
{
    // The method on the Good model that returns these records.
    protected static string $relationship = 'drawings';

    protected static ?string $title = 'نقشه‌ها';

    /** Empty on purpose: drawings are uploaded on the main form. */
    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_name')
            ->columns([
                TextColumn::make('original_name')
                    ->label('نام فایل')
                    ->url(fn ($record) => Storage::disk($record->disk)->url($record->path))
                    ->openUrlInNewTab(),
                TextColumn::make('mime_type')
                    ->label('نوع فایل'),
                // Stored in bytes, displayed in kilobytes.
                TextColumn::make('size')
                    ->label('حجم')
                    ->formatStateUsing(fn (int $state): string => number_format($state / 1024, 1).' KB'),
            ])
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
