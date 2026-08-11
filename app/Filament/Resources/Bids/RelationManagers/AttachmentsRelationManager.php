<?php

namespace App\Filament\Resources\Bids\RelationManagers;

use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only listing of files already attached to the bid — new attachments
 * are added through the main form's "پیوست‌ها" upload field, not here.
 */
class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'پیوست‌ها';

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
