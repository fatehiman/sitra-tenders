<?php

namespace App\Filament\Resources\Bids\Pages;

use App\Filament\Resources\Bids\BidResource;
use App\Models\BidAttachment;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBid extends EditRecord
{
    protected static string $resource = BidResource::class;

    protected array $newAttachmentPaths = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->newAttachmentPaths = $data['new_attachments'] ?? [];
        unset($data['new_attachments']);

        return $data;
    }

    protected function afterSave(): void
    {
        if (! empty($this->newAttachmentPaths)) {
            BidAttachment::createManyFromPaths($this->record, $this->newAttachmentPaths);
        }
    }
}
