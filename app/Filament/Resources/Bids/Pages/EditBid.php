<?php

namespace App\Filament\Resources\Bids\Pages;

use App\Filament\Resources\Bids\BidResource;
use App\Models\BidAttachment;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * The "edit a مناقصه" page — the mirror image of CreateBid.
 *
 * The upload field only ever ADDS files; removing an existing attachment is
 * done from the attachments table further down the page (see
 * AttachmentsRelationManager). Keeping the two apart means re-saving the
 * form without touching the uploader can never wipe existing files.
 *
 * `created_by` is deliberately not touched here: it records who published
 * the tender, not who last edited it.
 */
class EditBid extends EditRecord
{
    protected static string $resource = BidResource::class;

    protected array $newAttachmentPaths = [];

    /** Buttons in the page header. The policy decides if it is shown. */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /** Same trick as CreateBid: pull the uploads out of the column data. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->newAttachmentPaths = $data['new_attachments'] ?? [];
        unset($data['new_attachments']);

        return $data;
    }

    protected function afterSave(): void
    {
        // Skip entirely when nothing new was uploaded — the common case for
        // an edit that only changed the title or the dates.
        if (! empty($this->newAttachmentPaths)) {
            BidAttachment::createManyFromPaths($this->record, $this->newAttachmentPaths);
        }
    }
}
