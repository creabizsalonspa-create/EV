<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketMessage extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['internal' => false];
}
