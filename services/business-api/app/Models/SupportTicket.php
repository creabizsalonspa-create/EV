<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['idempotency_key'];

    protected $attributes = ['status' => 'open'];
}
