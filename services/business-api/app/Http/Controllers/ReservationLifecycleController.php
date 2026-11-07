<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Services\Charging\ReservationLifecycle;
use Illuminate\Http\Request;

class ReservationLifecycleController extends Controller
{
    public function checkIn(Request $r, Reservation $reservation, ReservationLifecycle $lifecycle)
    {
        abort_unless($r->user()->id === $reservation->user_id, 404);
        abort_unless($r->user()->role === 'customer', 403);
        $r->validate(['self_reported_arrival' => 'required|accepted', 'checked_in_at' => 'prohibited', 'latitude' => 'prohibited', 'longitude' => 'prohibited']);
        $result = $lifecycle->checkIn($reservation, $r->user()->id);

        return response()->json($result, $result['replayed'] ? 200 : 201);
    }
}
