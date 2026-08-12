<?php

namespace App\Filament\Resources\Goods;

use App\Filament\Resources\Goods\Pages\CreateGood;
use App\Filament\Resources\Goods\Pages\EditGood;
use App\Filament\Resources\Goods\Pages\ListGoods;
use App\Filament\Resources\Goods\RelationManagers\DrawingsRelationManager;
use App\Filament\Resources\Goods\Schemas\GoodForm;
use App\Filament\Resources\Goods\Tables\GoodsTable;
use App\Models\Good;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GoodResource extends Resource
{
    protected static ?string $model = Good::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $modelLabel = 'کالا';

    protected static ?string $pluralModelLabel = 'کالاها';

    protected static ?string $navigationLabel = 'کالاها';

    protected static ?int $navigationSort = 2;

    /**
     * Backs the searchable picker in the bid form as well as global search.
     */
    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return GoodForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GoodsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DrawingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoods::route('/'),
            'create' => CreateGood::route('/create'),
            'edit' => EditGood::route('/{record}/edit'),
        ];
    }
}
