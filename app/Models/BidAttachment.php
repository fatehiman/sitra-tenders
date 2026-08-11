<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['bid_id', 'disk', 'path', 'original_name', 'mime_type', 'size'])]
class BidAttachment extends Model
{
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
