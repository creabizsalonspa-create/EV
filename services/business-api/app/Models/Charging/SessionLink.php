<?php

namespace App\Models\Charging;

use Illuminate\Database\Eloquent\Model;

class SessionLink extends Model
{
    protected $table = 'csms_session_links';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['closed_without_dispatch_at' => 'immutable_datetime', 'source_meter_cursor' => 'integer', 'last_sequence' => 'integer', 'meter_start_wh' => 'integer', 'last_meter_wh' => 'integer', 'started_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'last_event_at' => 'immutable_datetime'];
    }
}
