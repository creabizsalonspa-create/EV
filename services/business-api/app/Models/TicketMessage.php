<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketMessage extends Model
{
    protected $guarded = ['id'];

    // Request replay keys are transport metadata, not public ticket content.
    protected $hidden = ['idempotency_key'];

    protected $attributes = ['internal' => false];
}
