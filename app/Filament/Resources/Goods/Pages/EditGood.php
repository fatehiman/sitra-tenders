<?php

namespace App\Filament\Resources\Goods\Pages;

use App\Filament\Resources\Goods\GoodResource;
use App\Models\GoodDrawing;
use Filament\Resources\Pages\EditRecord;

class EditGood extends EditRecord
{
    protected static string $resource = GoodResource::class;

    protected array $newDrawingPaths = [];

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->newDrawingPaths = $data['new_drawings'] ?? [];
        unset($data['new_drawings']);

        return $data;
    }

    protected function afterSave(): void
    {
        if (! empty($this->newDrawingPaths)) {
            GoodDrawing::createManyFromPaths($this->record, $this->newDrawingPaths);
        }
    }
}
