<?php

namespace App\Filament\Resources\Bids\Pages;

use App\Filament\Resources\Bids\BidResource;
use App\Models\BidAttachment;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

/**
 * The "create a مناقصه" page.
 *
 * Filament does the actual saving; this class only hooks into two moments of
 * that process to handle the attachments, which cannot be saved with the
 * tender itself: BidAttachment rows need a bid_id, and the bid has no id
 * until it has been inserted.
 */
class CreateBid extends CreateRecord
{
    protected static string $resource = BidResource::class;

    /** Carries the uploaded paths from the hook below to the one after it. */
    protected array $newAttachmentPaths = [];

    /**
     * Runs after validation, just before the row is inserted. Whatever is
     * returned is what gets written.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Set aside the uploads and remove them from the data — there is no
        // `new_attachments` column, so leaving them in would error.
        $this->newAttachmentPaths = $data['new_attachments'] ?? [];
        unset($data['new_attachments']);

        // Stamp the author from the session rather than trusting the form,
        // so nobody can publish a tender under someone else's name.
        $data['created_by'] = Auth::id();

        return $data;
    }

    /** Runs once the row exists, so $this->record now has an id to link to. */
    protected function afterCreate(): void
    {
        BidAttachment::createManyFromPaths($this->record, $this->newAttachmentPaths);
    }
}
