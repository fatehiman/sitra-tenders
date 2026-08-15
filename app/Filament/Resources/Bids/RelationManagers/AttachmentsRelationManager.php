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
 *
 * A "relation manager" is a table for related records, shown underneath the
 * edit form of its parent — here, the files belonging to one tender.
 */
class AttachmentsRelationManager extends RelationManager
{
    // The method name on the Bid model that returns these records.
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'پیوست‌ها';

    /** Empty on purpose: files are uploaded on the main form, not here. */
    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            // Which field names a record in confirmation dialogs
            // («آیا از حذف … مطمئن هستید؟»).
            ->recordTitleAttribute('original_name')
            ->columns([
                // Storage::disk(...)->url(...) builds the public link from
                // the disk and path recorded on the row, so this keeps
                // working if the storage location ever changes.
                TextColumn::make('original_name')
                    ->label('نام فایل')
                    ->url(fn ($record) => Storage::disk($record->disk)->url($record->path))
                    ->openUrlInNewTab(),
                TextColumn::make('mime_type')
                    ->label('نوع فایل'),
                // `size` is stored in bytes; show it in kilobytes.
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
