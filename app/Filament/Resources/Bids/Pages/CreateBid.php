<?php

namespace App\Filament\Resources\Bids\Pages;

use App\Filament\Resources\Bids\BidResource;
use App\Models\BidAttachment;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateBid extends CreateRecord
{
    protected static string $resource = BidResource::class;

    protected array $newAttachmentPaths = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->newAttachmentPaths = $data['new_attachments'] ?? [];
        unset($data['new_attachments']);

        $data['created_by'] = Auth::id();

        return $data;
    }

    protected function afterCreate(): void
    {
        BidAttachment::createManyFromPaths($this->record, $this->newAttachmentPaths);
    }
}
