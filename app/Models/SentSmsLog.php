<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'mobile', 'purpose', 'provider', 'template', 'status',
    'reference_id', 'error_code', 'error_message', 'trace_id',
])]
/**
 * An audit trail of every SMS the app asked the provider to send —
 * successes and failures alike.
 *
 * This exists because SMS is billed per accepted send and failures are
 * frequently the provider's fault, not ours. When a user reports "I never
 * got the code", `trace_id` and `error_code` here are what the provider's
 * support desk asks for. Note the code itself is never logged.
 */
class SentSmsLog extends Model
{
    const UPDATED_AT = null;

    // Eloquent would otherwise guess the plural table name "sent_sms_logs".
    protected $table = 'sent_sms_log';
}
