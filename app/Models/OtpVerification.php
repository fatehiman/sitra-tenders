<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A pending SMS verification challenge, keyed by mobile number.
 *
 * It cannot be keyed by user_id, because during registration no user row
 * exists yet — that is the whole point: the account is only created once
 * the code has been confirmed, so an abandoned sign-up leaves no user
 * behind (see App\Services\OtpService).
 *
 * `code_hash`, not `code`: the six-digit code is hashed exactly like a
 * password, so a leaked database dump cannot be used to complete somebody
 * else's in-flight registration. `attempts` caps guessing.
 */
#[Fillable(['mobile', 'code_hash', 'attempts', 'expires_at', 'verified_at', 'ip_address'])]
class OtpVerification extends Model
{
    // Rows are created, counted up, then deleted — never meaningfully
    // "updated", so there is no updated_at column.
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }
}
