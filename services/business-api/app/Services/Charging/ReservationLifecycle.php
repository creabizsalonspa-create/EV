<?php

namespace App\Services\Charging;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReservationLifecycle
{
    public function eligible(Reservation $reservation): bool
    {
        $start = CarbonImmutable::parse($reservation->starts_at, 'UTC');

        return $reservation->status === 'confirmed' && $reservation->checked_in_at === null && now()->gte($start->subMinutes(15)) && now()->lt($start->addMinutes(30));
    }

    public function checkIn(Reservation $reservation, int $actor): array
    {
        abort_unless($reservation->user_id === $actor, 404);

        return DB::transaction(function () use ($reservation, $actor) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($reservation->checked_in_at !== null) {
                return ['reservation' => $reservation, 'replayed' => true, 'physical_presence_verified' => false, 'evidence' => 'SELF_REPORTED'];
            }
            abort_unless($this->eligible($reservation), 409, 'Check-in is only available from 15 minutes before the slot until its end.');
            $reservation->update(['checked_in_at' => now()]);
            DB::table('reservation_lifecycle_events')->insert(['reservation_id' => $reservation->id, 'actor_id' => $actor, 'event_type' => 'self_reported_arrival', 'evidence' => json_encode(['physical_presence_verified' => false], JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return ['reservation' => $reservation, 'replayed' => false, 'physical_presence_verified' => false, 'evidence' => 'SELF_REPORTED'];
        }, 3);
    }

    /** Expire only simulator bookings with no linked session; never stop or bill. */
    public function expire(int $limit = 100, ?int $id = null, int $after = 0): array
    {
        if ($limit < 1 || $limit > 1000 || $after < 0 || ($id !== null && $id < 1)) {
            throw new \InvalidArgumentException('Invalid expiry scan bounds.');
        }
        $rows = DB::table('reservations as r')->join('stations as s', 's.id', '=', 'r.station_id')->where('s.source', 'simulator')->where('r.id', '>', $after)->where('r.status', 'confirmed')->where('r.starts_at', '<=', now()->subMinutes(30))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('charging_sessions as cs')->whereColumn('cs.reservation_id', 'r.id'))
            ->when($id !== null, fn ($q) => $q->where('r.id', $id))->orderBy('r.id')->limit($limit)->get(['r.id', 'r.station_id']);
        $expired = 0;
        $skipped = 0;
        foreach ($rows as $candidate) {
            $changed = DB::transaction(function () use ($candidate) {
                $station = Station::whereKey($candidate->station_id)->lockForUpdate()->firstOrFail();
                $reservation = Reservation::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if ($station->source !== 'simulator' || $reservation->station_id !== $station->id || $reservation->status !== 'confirmed' || now()->lt(CarbonImmutable::parse($reservation->starts_at, 'UTC')->addMinutes(30)) || ChargingSession::where('reservation_id', $reservation->id)->exists()) {
                    return false;
                }
                if (DB::table('charger_commands')->where('station_id', $station->id)->whereNull('payload->session_id')->where('status', '!=', 'cancelled')->exists()) {
                    return false;
                }
                $reservation->update(['status' => 'expired_unused']);
                DB::table('reservation_lifecycle_events')->insert(['reservation_id' => $reservation->id, 'actor_id' => null, 'event_type' => 'expired_unused', 'evidence' => json_encode(['reason' => 'window_elapsed_without_linked_session', 'self_reported_arrival' => $reservation->checked_in_at !== null, 'financial_action' => 'none', 'hardware_action' => 'none'], JSON_THROW_ON_ERROR), 'created_at' => now()]);

                return true;
            }, 3);
            $changed ? $expired++ : $skipped++;
        }

        return ['examined' => $rows->count(), 'expired' => $expired, 'skipped' => $skipped, 'next_after_id' => $rows->count() === $limit ? (int) $rows->last()->id : 0, 'evidence' => 'SIMULATED_BUSINESS_RECORDS'];
    }
}
