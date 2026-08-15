<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One uploaded file belonging to a tender.
 *
 * The file itself lives on disk (storage/app/public); this row only records
 * where it is and what it was called. `disk` names the Laravel filesystem
 * it was written to, so moving to S3 later would not need a schema change.
 */
#[Fillable(['bid_id', 'disk', 'path', 'original_name', 'mime_type', 'size'])]
class BidAttachment extends Model
{
    // Attachments are never edited after upload, so there is no updated_at
    // column. Telling Eloquent that stops it trying to write one.
    const UPDATED_AT = null;

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    /**
     * Turn the stored paths coming back from the form's multi-file
     * `new_attachments` upload into attachment rows. The upload field uses
     * `preserveFilenames()`, so the basename is the original filename.
     *
     * @param  array<string>  $paths
     */
    public static function createManyFromPaths(Bid $bid, array $paths, string $disk = 'public'): void
    {
        $storage = Storage::disk($disk);

        // Filament has already moved each upload into place by this point,
        // so the file is on disk and we can ask it for its type and size.
        foreach ($paths as $path) {
            $bid->attachments()->create([
                'disk' => $disk,
                'path' => $path,
                'original_name' => basename($path),
                'mime_type' => $storage->mimeType($path) ?: 'application/octet-stream',
                'size' => $storage->size($path),
            ]);
        }
    }
}
