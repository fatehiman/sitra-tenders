<?php

namespace App\Filament\Resources\Bids;

use App\Filament\Resources\Bids\Pages\CreateBid;
use App\Filament\Resources\Bids\Pages\EditBid;
use App\Filament\Resources\Bids\Pages\ListBids;
use App\Filament\Resources\Bids\Pages\OpenEnvelope;
use App\Filament\Resources\Bids\Pages\SubmitSuggestion;
use App\Filament\Resources\Bids\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\Bids\Schemas\BidForm;
use App\Filament\Resources\Bids\Tables\BidsTable;
use App\Models\Bid;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The مناقصات (tenders) section of the panel — this app's home screen.
 *
 * A Filament "Resource" is the whole CRUD bundle for one model: the list
 * table, the create page, the edit page, the navigation entry, and the
 * permission checks. This class is mostly a table of contents; the real
 * detail lives in the small classes it points at (BidForm, BidsTable, and
 * the three page classes), which keeps any one file readable.
 *
 * Who can do what is NOT decided here — App\Policies\BidPolicy answers that,
 * and Filament consults it automatically.
 */
class BidResource extends Resource
{
    // Which Eloquent model this resource manages.
    protected static ?string $model = Bid::class;

    // Sidebar icon.
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    // Persian singular/plural, used in headings, buttons and notifications
    // ("مناقصه ایجاد شد") so nothing English ever leaks into the UI.
    protected static ?string $modelLabel = 'مناقصه';

    protected static ?string $pluralModelLabel = 'مناقصات';

    protected static ?string $navigationLabel = 'مناقصات';

    // Lower number = higher in the sidebar. Tenders come first deliberately.
    protected static ?int $navigationSort = 1;

    /** The create/edit form. */
    public static function form(Schema $schema): Schema
    {
        return BidForm::configure($schema);
    }

    /** The list screen's table. */
    public static function table(Table $table): Table
    {
        return BidsTable::configure($table);
    }

    /**
     * "Relation managers" are sub-tables shown on the edit page. This one
     * lists the tender's already-uploaded attachments so they can be removed
     * — the form itself only ever ADDS new files.
     */
    public static function getRelations(): array
    {
        return [
            AttachmentsRelationManager::class,
        ];
    }

    /**
     * URL -> page class. These become /bids, /bids/create, /bids/1/edit,
     * /bids/1/suggest and /bids/1/envelope/a.
     *
     * The last two are not part of the usual CRUD set:
     *   suggest  — the user-facing «ارسال پیشنهاد» wizard;
     *   envelope — the admin's «بازکردن پاکت الف/ب» review screen, whose
     *              `{stage}` segment is 'a' or 'b' (App\Enums\EnvelopeStage).
     *
     * Both are registered as resource pages rather than routes in
     * routes/web.php so they inherit the panel's auth middleware, breadcrumbs
     * and layout for free, and so a row button can link to them with
     * BidResource::getUrl('suggest', ...) / getUrl('envelope', ...).
     */
    public static function getPages(): array
    {
        return [
            'index' => ListBids::route('/'),
            'create' => CreateBid::route('/create'),
            'edit' => EditBid::route('/{record}/edit'),
            'suggest' => SubmitSuggestion::route('/{record}/suggest'),
            'envelope' => OpenEnvelope::route('/{record}/envelope/{stage}'),
        ];
    }
}
