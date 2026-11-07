<?php

namespace App\Services\Charging;

use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UnsentStartExpiry
{
    /** Bounded synthetic-only housekeeping. Unknown outcomes always need reconciliation. */
    public function run(int $limit = 100, ?int $reservationId = null, int $afterId = 0): array
    {
        app(SessionBridge::class)->enabled();
        if ($afterId < 0 || $limit < 1 || $limit > 1000 || ($reservationId !== null && $reservationId < 1)) {
            throw new \InvalidArgumentException('Use a limit of 1–1000 and a positive reservation id.');
        }
        $ids = DB::table('reservations as r')
            ->join('charging_sessions as s', 's.reservation_id', '=', 'r.id')
            ->join('csms_session_links as l', 'l.charging_session_id', '=', 's.id')
            ->join('charger_commands as c', 'c.id', '=', 'l.start_command_id')
            ->where('r.id', '>', $afterId)->where('r.status', 'confirmed')->where('s.status', 'pending_start')
            ->where('r.starts_at', '<=', now()->subMinutes(30))
            ->where('c.status', 'pending')->whereNull('l.closed_without_dispatch_at')
            ->when($reservationId !== null, fn ($q) => $q->where('r.id', $reservationId))
            ->orderBy('r.id')->limit($limit)->pluck('r.id');
        $expired = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            try {
                $changed = DB::transaction(function () use ($id) {
                    // Same outer lock as customer cancellation/start; command admission is
                    // serialized separately by SessionBridge's locked pending-state proof.
                    $reservation = Reservation::whereKey($id)->lockForUpdate()->firstOrFail();
                    if ($reservation->status !== 'confirmed') {
                        return false;
                    }
                    $session = ChargingSession::where('reservation_id', $id)->firstOrFail();
                    $link = SessionLink::where('charging_session_id', $session->id)->firstOrFail();
                    $binding = DB::table('csms_bindings')->where('id', $link->binding_id)->first();
                    abort_unless($binding && $binding->station_id === $reservation->station_id && $link->identifier === $binding->identifier && $link->protocol === $binding->protocol && Station::findOrFail($reservation->station_id)->source === 'simulator' && preg_match('/^ev-test-[a-zA-Z0-9-]{1,80}$/', $binding->identifier), 409, 'Only local simulator starts can expire.');
                    app(SessionBridge::class)->expireUndispatched($session, $reservation);
                    $reservation->update(['status' => 'expired_unstarted']);
                    DB::table('charging_request_expirations')->insert([
                        'reservation_id' => $id, 'charging_session_id' => $session->id,
                        'reason' => 'reservation_expired_before_dispatch',
                        'initiator' => 'system_simulator_expiry', 'created_at' => now(),
                    ]);

                    return true;
                }, 3);
                $changed ? $expired++ : $skipped++;
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 409) {
                    throw $e;
                }
                $skipped++; // Raced with admission or source observation. Never force-close.
            }
        }

        return ['examined' => $ids->count(), 'expired' => $expired, 'skipped' => $skipped, 'next_after_id' => $ids->count() === $limit ? (int) $ids->last() : 0, 'evidence' => 'SIMULATED_CSMS'];
    }
}
