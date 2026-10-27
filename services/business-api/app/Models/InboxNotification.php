<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InboxNotification extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['read_at' => null];
}
