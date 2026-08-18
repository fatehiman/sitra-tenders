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
     *
     * Public because the user's پیشنهاد wizard accepts exactly the same list
     * (App\Filament\Resources\Bids\Pages\SubmitSuggestion) — the requirement
     * is worded identically for both sides. Sharing the constant means the
     * two can never drift into accepting different things, which would be a
     * confusing bug to chase: "why did my .pptx upload here but not there?"
     */
    public const ACCEPTED_ATTACHMENT_TYPES = [
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
            /*
             * ONE column at the top level, so the two Sections below stack
             * vertically instead of sitting side by side.
             *
             * This line is load-bearing: a resource form schema defaults to
             * TWO columns, so each top-level Section took half the width and
             * both the شرح مناقصه editor and the کالاهای مورد نیاز table were
             * squeezed into a narrow strip. Note the *inner* Section still
             * declares ->columns(2) for its own fields — that is unaffected.
             */
            ->columns(1)
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
                        /*
                         * ودیعه — the deposit a bidder must pay/guarantee
                         * just to be ALLOWED to bid, unrelated to the price
                         * they later quote for the goods. Same plain
                         * TextInput pattern as every other money field in
                         * this app (see the goods-requirement قیمت واحد box)
                         * — no Filament "money" component is used anywhere.
                         */
                        TextInput::make('deposit_amount')
                            ->label('مبلغ ودیعه (ریال)')
                            ->required()
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->step(1)
                            ->extraInputAttributes(['inputmode' => 'numeric']),
                        /*
                         * Jalali (Shamsi) date-time pickers.
                         *
                         * The VALUE is still an ordinary Gregorian datetime —
                         * that is what reaches the model and the database, so
                         * every ->after() comparison, ->active() scope and
                         * now()->between() elsewhere keeps working unchanged.
                         * ->jalali() only swaps the calendar the operator
                         * *sees and clicks*: Persian month names, Saturday as
                         * the first day of the week, 1405/05/24 in the box.
                         *
                         * Order matters in this chain:
                         *  - ->native(false) must come first. It tells
                         *    Filament to use its own JS picker rather than
                         *    the browser's <input type="datetime-local">,
                         *    which can only ever render a Gregorian calendar.
                         *  - ->jalali() then replaces that picker's view with
                         *    the Jalali one and sets a default display format.
                         *  - ->displayFormat(...) comes AFTER ->jalali()
                         *    because jalali() sets its own ('Y/m/d H:i:s');
                         *    putting ours first would just be overwritten.
                         *    ->seconds(false) alone is not enough for the
                         *    same reason.
                         */
                        DateTimePicker::make('start_at')
                            ->label('تاریخ و ساعت شروع')
                            ->required()
                            ->native(false)
                            ->seconds(false)
                            ->jalali()
                            ->displayFormat(config('filament-jalali.date_time_format')),
                        DateTimePicker::make('expire_at')
                            ->label('تاریخ و ساعت پایان')
                            ->required()
                            ->native(false)
                            ->seconds(false)
                            ->jalali()
                            ->displayFormat(config('filament-jalali.date_time_format'))
                            // Rejects an end date at or before the start.
                            // Compares the underlying Gregorian values, so
                            // the Jalali display makes no difference here.
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
