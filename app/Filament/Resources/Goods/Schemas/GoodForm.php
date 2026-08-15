<?php

namespace App\Filament\Resources\Goods\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * The create/edit form for a کالا. Structurally the same as BidForm, minus
 * the requirement rows — including the "upload here, list in the relation
 * manager" split for the نقشه files.
 */
class GoodForm
{
    /**
     * نقشه files are drawings: PDF or image only — deliberately narrower than
     * a bid's attachment allow-list (no Office/video/audio).
     */
    private const ACCEPTED_DRAWING_TYPES = [
        'application/pdf',
        'image/*',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // کد کالا is how operators refer to an item in conversation
                // and on paper, so it must identify exactly one row.
                TextInput::make('code')
                    ->label('کد کالا')
                    ->required()
                    ->maxLength(64)
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'unique' => 'این کد کالا قبلاً ثبت شده است.',
                    ]),
                TextInput::make('name')
                    ->label('شرح کالا')
                    ->required()
                    ->maxLength(255),
                Textarea::make('specifications')
                    ->label('ابعاد و مشخصات فنی')
                    ->required()
                    ->rows(4)
                    ->columnSpanFull(),
                // As in BidForm, 'new_drawings' is not a column — the page
                // classes turn the uploaded paths into GoodDrawing rows.
                FileUpload::make('new_drawings')
                    ->label('نقشه')
                    ->helperText('یک یا چند فایل PDF یا تصویر')
                    ->multiple()
                    ->disk('public')
                    ->directory('good-drawings')
                    ->preserveFilenames()
                    ->acceptedFileTypes(self::ACCEPTED_DRAWING_TYPES)
                    ->maxSize(51200)
                    ->dehydrated()
                    ->columnSpanFull(),
            ]);
    }
}
