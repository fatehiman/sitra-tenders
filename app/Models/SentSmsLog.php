<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'mobile', 'purpose', 'provider', 'template', 'status',
    'reference_id', 'error_code', 'error_message', 'trace_id',
])]
class SentSmsLog extends Model
{
    const UPDATED_AT = null;

    protected $table = 'sent_sms_log';
}
