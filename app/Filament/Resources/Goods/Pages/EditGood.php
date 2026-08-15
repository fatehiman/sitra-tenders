<?php

namespace App\Filament\Resources\Goods\Pages;

use App\Filament\Resources\Goods\GoodResource;
use App\Models\GoodDrawing;
use Filament\Resources\Pages\EditRecord;

/**
 * "Edit a کالا". The upload field only adds new نقشه files; existing ones
 * are removed from the drawings table further down the page.
 *
 * There is no delete button in the header here, unlike EditBid — goods are
 * deleted from the list screen, where the "is it used by a tender?" guard
 * lives (see GoodsTable::guardedDeleteAction).
 */
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
        // Nothing uploaded this time round — leave existing drawings alone.
        if (! empty($this->newDrawingPaths)) {
            GoodDrawing::createManyFromPaths($this->record, $this->newDrawingPaths);
        }
    }
}
