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

/**
 * The create/edit form for a مناقصه, used by both CreateBid and EditBid.
 *
 * Two sections: the tender's own details on top, and the «کالاهای مورد نیاز»
 * requirement rows underneath, so a tender and the goods it asks for are
 * defined in a single pass.
 */
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
                        // A rich-text editor that also handles uploading and
                        // inserting images inline, so there is no separate
                        // "upload, then paste the link" step. It stores HTML.
                        RichEditor::make('description')
                            ->label('شرح مناقصه')
                            ->required()
                            ->columnSpanFull(),
                        // Dates are entered and stored as ordinary Gregorian
                        // values; only the *display* is converted to Jalali
                        // (see BidsTable). ->native(false) uses Filament's
                        // own picker instead of the browser's, which looks
                        // and behaves the same in every browser.
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
                            // Rejects an end date at or before the start.
                            ->after('start_at'),
                        // Note the field name is 'new_attachments', not
                        // 'attachments': it is NOT a database column. The
                        // page classes pull the uploaded paths out of the
                        // form data and turn them into BidAttachment rows.
                        FileUpload::make('new_attachments')
                            ->label('پیوست‌ها')
                            ->helperText('PDF، Word، Excel، PowerPoint، تصویر، ویدیو و فایل صوتی mp3')
                            ->multiple()
                            ->disk('public')
                            ->directory('bid-attachments')
                            // Keep the operator's original filename, which is
                            // what BidAttachment stores as original_name.
                            ->preserveFilenames()
                            // Enforced server-side, not just as the browser's
                            // "accept" hint — a hint is trivially bypassed.
                            ->acceptedFileTypes(self::ACCEPTED_ATTACHMENT_TYPES)
                            ->maxSize(51200) // kilobytes, i.e. 50 MB per file
                            // Include the value in the submitted data even
                            // though there is no matching model column.
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
                // A Repeater renders a repeating group of fields — one group
                // per row. ->relationship() ties it straight to the model's
                // goodRequirements() relation, so Filament inserts, updates
                // and deletes those rows itself when the form is saved.
                Repeater::make('goodRequirements')
                    ->relationship()
                    ->hiddenLabel()
                    ->addActionLabel('افزودن کالا')
                    ->defaultItems(0)   // start empty, not with a blank row
                    ->reorderable(false) // order carries no meaning here
                    ->columns(3)
                    // The collapsed-row heading: «شرح کالا (کد کالا)».
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
                            // ->options() is the initial list shown before
                            // any typing: the first 50 goods by name.
                            ->options(fn (): array => Good::query()
                                ->orderBy('name')
                                ->limit(self::PICKER_PRELOAD_LIMIT)
                                ->get()
                                ->mapWithKeys(fn (Good $good): array => [$good->id => $good->picker_label])
                                ->all())
                            // ...and this runs as they type, hitting the
                            // database each time so the catalogue can grow
                            // far beyond what the browser could hold.
                            ->getSearchResultsUsing(fn (string $search): array => Good::query()
                                ->search($search)
                                ->orderBy('name')
                                ->limit(self::PICKER_PRELOAD_LIMIT)
                                ->get()
                                ->mapWithKeys(fn (Good $good): array => [$good->id => $good->picker_label])
                                ->all())
                            // Needed when EDITING: the saved good may not be
                            // in the preloaded 50, so Filament asks how to
                            // label the id it already has.
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
