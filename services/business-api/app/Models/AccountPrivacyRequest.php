<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountPrivacyRequest extends Model
{
    protected $table = 'account_privacy_requests';

    protected $guarded = ['id'];

    // Private reason and internal review notes must never be serialized
    // through Eloquent defaults. Controllers provide explicit allowlists.
    protected $hidden = ['request_details_encrypted', 'review_note_encrypted'];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
