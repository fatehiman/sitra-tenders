<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['good_id', 'disk', 'path', 'original_name', 'mime_type', 'size'])]
class GoodDrawing extends Model
{
    const UPDATED_AT = null;

    public function good(): BelongsTo
    {
        return $this->belongsTo(Good::class);
    }

    /**
     * Mirrors BidAttachment::createManyFromPaths — turns the stored paths
     * returned by the form's multi-file `new_drawings` upload into rows.
     * The upload field uses `preserveFilenames()`, so the basename is the
     * original filename.
     *
     * @param  array<string>  $paths
     */
    public static function createManyFromPaths(Good $good, array $paths, string $disk = 'public'): void
    {
        $storage = Storage::disk($disk);

        foreach ($paths as $path) {
            $good->drawings()->create([
                'disk' => $disk,
                'path' => $path,
                'original_name' => basename($path),
                'mime_type' => $storage->mimeType($path) ?: 'application/octet-stream',
                'size' => $storage->size($path),
            ]);
        }
    }
}
