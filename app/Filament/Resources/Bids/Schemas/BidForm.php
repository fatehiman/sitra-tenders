<?php

namespace App\Filament\Resources\Bids\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class BidForm
{
    /**
     * Attachment types explicitly required by the spec: PDF, Word/Excel/
     * PowerPoint (legacy + OOXML), all image types, all video types, mp3.
     */
    private const ACCEPTED_ATTACHMENT_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'image/*',
        'video/*',
        'audio/mpeg',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label('عنوان')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                RichEditor::make('description')
                    ->label('شرح مناقصه')
                    ->required()
                    ->columnSpanFull(),
                DateTimePicker::make('start_at')
                    ->label('تاریخ و ساعت شروع')
                    ->required()
                    ->native(false)
                    ->seconds(false),
                DateTimePicker::make('expire_at')
                    ->label('تاریخ و ساعت پایان')
                    ->required()
                    ->native(false)
                    ->seconds(false)
                    ->after('start_at'),
                FileUpload::make('new_attachments')
                    ->label('پیوست‌ها')
                    ->helperText('PDF، Word، Excel، PowerPoint، تصویر، ویدیو و فایل صوتی mp3')
                    ->multiple()
                    ->disk('public')
                    ->directory('bid-attachments')
                    ->preserveFilenames()
                    ->acceptedFileTypes(self::ACCEPTED_ATTACHMENT_TYPES)
                    ->maxSize(51200)
                    ->dehydrated()
                    ->columnSpanFull(),
            ]);
    }
}
