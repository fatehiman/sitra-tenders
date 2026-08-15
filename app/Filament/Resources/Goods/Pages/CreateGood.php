<?php

namespace App\Filament\Resources\Goods\Pages;

use App\Filament\Resources\Goods\GoodResource;
use App\Models\GoodDrawing;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

/**
 * "Create a کالا". Same two-step attachment dance as CreateBid: the نقشه
 * files can only be linked once the good has an id, so they are set aside
 * before the insert and turned into rows immediately after it.
 */
class CreateGood extends CreateRecord
{
    protected static string $resource = GoodResource::class;

    protected array $newDrawingPaths = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // 'new_drawings' is a form field, not a column — take it out.
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
