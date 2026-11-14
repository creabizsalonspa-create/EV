<?php

namespace App\Http\Controllers;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Finance\PrechargePricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrechargePricingController extends Controller
{
    private function authorizeOwner(Request $request, Reservation $reservation): void
    {
        abort_unless($request->user()->id === $reservation->user_id && $request->user()->role === 'customer', 404);
    }

    private function locked(Request $request, Reservation $reservation, callable $action)
    {
        $this->authorizeOwner($request, $reservation);

        return DB::transaction(function () use ($request, $reservation, $action) {
            Station::whereKey($reservation->station_id)->lockForUpdate()->firstOrFail();
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->role === 'customer' && $user->account_status === 'active', 404);

            return $action(Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail());
        }, 3);
    }

    public function preview(Request $request, Reservation $reservation, PrechargePricing $pricing)
    {
        $this->authorizeOwner($request, $reservation);
        $v = $request->validate(['energy_wh' => 'required|integer|between:1,1000000']);
        $data = $this->locked($request, $reservation, function ($current) use ($pricing, $request, $v) {
            $existing = ChargingSession::where('reservation_id', $current->id)->first();
            if ($existing) {
                return ['existing_session_id' => $existing->id, 'start_already_recorded' => true];
            }

            return $pricing->preview($current, $request->user()->id, (int) $v['energy_wh']);
        });

        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function accept(Request $request, Reservation $reservation, PrechargePricing $pricing)
    {
        $this->authorizeOwner($request, $reservation);
        $v = $request->validate(['quote_token' => 'required|string|max:16384', 'accepted' => 'required|accepted']);
        $data = $this->locked($request, $reservation, fn ($current) => $pricing->accept($current, $request->user()->id, $v['quote_token']));

        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
