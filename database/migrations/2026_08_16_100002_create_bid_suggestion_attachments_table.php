<?php

use App\Enums\SuggestionAttachmentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files uploaded by a user as part of their پیشنهاد.
 *
 * Deliberately the SAME shape as `bid_attachments` and `good_drawings`, so
 * all three use the identical upload-in-the-form + rows-on-the-model pattern
 * and nobody has to learn a third one.
 *
 * The one addition is `type`, because this table holds two different things
 * that arrive at two different steps of the wizard and are shown in two
 * different places:
 *
 *   document        — step 2's «پیوست‌ها», up to 10 supporting files
 *   payment_receipt — step 3's «رسید پرداخت یا ضمانت‌نامه بانکی»
 *
 * They share a table rather than getting one each because everything else
 * about them — storage, listing, deletion, the disk/path/size columns — is
 * identical, and a `where type = ...` is cheaper than a second table plus a
 * second model plus a second upload helper.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bid_suggestion_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_suggestion_id')->constrained('bid_suggestions')->cascadeOnDelete();

            /*
             * A plain string, not a MySQL ENUM — same reasoning as
             * bid_suggestions.status: adding «فرم الف» attachments later
             * should be a code change, not a schema change. The allowed
             * values live in App\Enums\SuggestionAttachmentType.
             *
             * Indexed because every read of this table filters on it.
             */
            $table->string('type', 20)->default(SuggestionAttachmentType::Document->value)->index();

            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bid_suggestion_attachments');
    }
};
