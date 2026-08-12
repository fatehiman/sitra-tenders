<?php

namespace App\Filament\Resources\Goods\Pages;

use App\Filament\Resources\Goods\GoodResource;
use App\Models\GoodDrawing;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateGood extends CreateRecord
{
    protected static string $resource = GoodResource::class;

    protected array $newDrawingPaths = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->newDrawingPaths = $data['new_drawings'] ?? [];
        unset($data['new_drawings']);

        $data['created_by'] = Auth::id();

        return $data;
    }

    protected function afterCreate(): void
    {
        GoodDrawing::createManyFromPaths($this->record, $this->newDrawingPaths);
    }
}
