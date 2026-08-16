<?php

namespace App\Models;

use App\Enums\SuggestionAttachmentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One file a user uploaded while building their پیشنهاد — either a
 * supporting document (step 2) or the payment receipt / bank guarantee
 * (step 3). `type` says which; see App\Enums\SuggestionAttachmentType.
 *
 * Same columns and same upload pattern as BidAttachment and GoodDrawing,
 * deliberately. The one thing that is genuinely different is sync(): a
 * tender's attachments are only ever ADDED to, while a draft's are edited
 * repeatedly — the user can add and remove files across many visits before
 * finalising — so this model has to reconcile a list rather than append to
 * one.
 */
#[Fillable(['bid_suggestion_id', 'type', 'disk', 'path', 'original_name', 'mime_type', 'size'])]
class BidSuggestionAttachment extends Model
{
    // Uploads are replaced, never edited in place — no updated_at column.
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => SuggestionAttachmentType::class,
        ];
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(BidSuggestion::class, 'bid_suggestion_id');
    }

    /** Only the files uploaded into one step of the wizard. */
    public function scopeOfType(Builder $query, SuggestionAttachmentType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /** The public URL the browser can download this file from. */
    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * Make the rows of one type match exactly the given list of stored paths.
     *
     * This is the draft-save workhorse. Filament's FileUpload has already
     * moved every selected file onto the disk by the time we get here, so
     * $paths is a list of real, existing storage paths — what is left is to
     * reconcile the database with it:
     *
     *   - a path with no row  → the user just added a file → create the row
     *   - a row with no path  → the user removed it in the form → delete the
     *                           row AND the file, so an abandoned draft does
     *                           not quietly fill the disk
     *   - a path with a row   → unchanged, leave it alone (this is the
     *                           common case on every re-save)
     *
     * @param  array<int|string, string>  $paths  as returned by FileUpload's
     *                                            multiple() state — keys are
     *                                            Filament's own and ignored
     */
    public static function sync(
        BidSuggestion $suggestion,
        SuggestionAttachmentType $type,
        array $paths,
        string $disk = 'public',
    ): void {
        $paths = array_values(array_filter($paths, 'is_string'));

        $existing = $suggestion->attachments()->ofType($type)->get();

        // Gone from the form: drop the row and the file behind it.
        foreach ($existing as $attachment) {
            if (! in_array($attachment->path, $paths, strict: true)) {
                Storage::disk($attachment->disk)->delete($attachment->path);
                $attachment->delete();
            }
        }

        $keptPaths = $existing->pluck('path')->all();
        $storage = Storage::disk($disk);

        foreach ($paths as $path) {
            if (in_array($path, $keptPaths, strict: true)) {
                continue;
            }

            // The file is on disk already, so it can be asked for its own
            // type and size rather than trusting anything the browser said.
            $suggestion->attachments()->create([
                'type' => $type,
                'disk' => $disk,
                'path' => $path,
                'original_name' => basename($path),
                'mime_type' => $storage->mimeType($path) ?: 'application/octet-stream',
                'size' => $storage->size($path),
            ]);
        }

        /*
         * Drop the parent's cached `attachments` collection.
         *
         * Eloquent loads a relationship once and then serves the same
         * objects forever. Without this, code that saves a draft and THEN
         * asks "does this bid have a receipt yet?" — which is exactly what
         * the finalise path does — would be answered from the list as it was
         * before these rows were written, and a bid with a freshly uploaded
         * receipt would be refused for not having one.
         */
        $suggestion->unsetRelation('attachments');
    }
}
