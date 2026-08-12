<?php

namespace App\Filament\Resources\Bids\Schemas;

use App\Models\Good;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
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

    /**
     * How many goods the picker preloads before the operator types anything.
     * Past this, they search — the picker matches on شرح کالا and کد کالا.
     */
    private const PICKER_PRELOAD_LIMIT = 50;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('اطلاعات مناقصه')
                    ->columns(2)
                    ->schema([
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
                    ]),

                self::goodRequirementsSection(),
            ]);
    }

    /**
     * The «کالاهای مورد نیاز» table at the bottom of the create/edit form, so
     * a tender and its goods are defined in one pass. Backed directly by the
     * `goodRequirements` relationship — Filament writes/updates/deletes the
     * rows itself, no manual sync in the page classes.
     */
    private static function goodRequirementsSection(): Section
    {
        return Section::make('کالاهای مورد نیاز')
            ->description('کالای مورد نیاز را از فهرست انتخاب کنید (جست‌وجو با شرح کالا یا کد کالا) و تعداد را وارد کنید.')
            ->schema([
                Repeater::make('goodRequirements')
                    ->relationship()
                    ->hiddenLabel()
                    ->addActionLabel('افزودن کالا')
                    ->defaultItems(0)
                    ->reorderable(false)
                    ->columns(3)
                    ->itemLabel(fn (array $state): ?string => filled($state['good_id'] ?? null)
                        ? Good::find($state['good_id'])?->picker_label
                        : null)
                    ->schema([
                        Select::make('good_id')
                            ->label('کالا')
                            ->required()
                            ->searchable()
                            ->columnSpan(2)
                            // Same good twice in one tender is meaningless —
                            // blocked in the UI and by the unique
                            // (bid_id, good_id) index.
                            ->distinct()
                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                            ->options(fn (): array => Good::query()
                                ->orderBy('name')
                                ->limit(self::PICKER_PRELOAD_LIMIT)
                                ->get()
                                ->mapWithKeys(fn (Good $good): array => [$good->id => $good->picker_label])
                                ->all())
                            ->getSearchResultsUsing(fn (string $search): array => Good::query()
                                ->search($search)
                                ->orderBy('name')
                                ->limit(self::PICKER_PRELOAD_LIMIT)
                                ->get()
                                ->mapWithKeys(fn (Good $good): array => [$good->id => $good->picker_label])
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => Good::find($value)?->picker_label),
                        TextInput::make('quantity')
                            ->label('تعداد')
                            ->required()
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->step(1),
                    ]),
            ]);
    }
}
