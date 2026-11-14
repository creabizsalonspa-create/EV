<?php

namespace App\Services\Charging;

use App\Models\ChargingSession;
use App\Models\Reservation;
use App\Models\Station;
use Carbon\CarbonImmutable;

/** Supported business rules, never a claim of physical occupancy or fee authority. */
final class ReservationPolicy
{
    public const VERSION = 1;

    public function publicTerms(): array
    {
        return ['version' => self::VERSION, 'slot_minutes' => 30, 'arrival_before_minutes' => 15,
            'unused_expiry_minutes' => 30, 'cancellation_rule' => 'BEFORE_ADMITTED_OR_UNCERTAIN_START',
            'no_show_fee_minor' => 0, 'cancellation_fee_minor' => 0,
            'occupancy_basis' => 'CANONICAL_LOGICAL_SESSION_ONLY', 'occupancy_enforcement' => 'START_ADMISSION', 'physical_occupancy_verified' => false,
            'unresolved_session_requires_reconciliation' => true, 'automatic_stop' => false,
            'evidence' => 'SIMULATED_BUSINESS_POLICY'];
    }

    public function assertVersion(?int $expected, ?int $actual = self::VERSION): void
    {
        abort_if($expected !== null && $expected !== $actual, 409, 'Reservation terms changed; refresh before booking.');
    }

    /** Caller holds the station lock also used by start/stop reconciliation. */
    public function requireUnoccupied(Station $station, ?int $evseId): void
    {
        $sessions = ChargingSession::whereIn('reservation_id', Reservation::where('station_id', $station->id)->select('id'))
            ->when($evseId !== null, fn ($q) => $q->where(fn ($q) => $q->where('charging_evse_id', $evseId)->orWhereNull('charging_evse_id')))
            ->where(fn ($q) => $q->whereNotIn('status', ['stopped', 'cancelled', 'expired_unstarted'])
                ->orWhere(fn ($q) => $q->where('status', 'stopped')->whereNull('stopped_at'))
                ->orWhereExists(fn ($link) => $link->selectRaw('1')->from('csms_session_links as link')
                    ->whereColumn('link.charging_session_id', 'charging_sessions.id')->whereNull('link.ended_at')->whereNull('link.closed_without_dispatch_at')));
        abort_if($sessions->exists(), 409, 'An unresolved logical session occupies this charging resource. Reconcile or finish it before another booking.');
    }

    public function snapshot(Station $station, CarbonImmutable $start, bool $clientConfirmedVersion): array
    {
        return $this->publicTerms() + ['station_timezone' => $station->timezone, 'currency' => $station->currency,
            'slot_starts_at' => $start->toIso8601String(), 'slot_ends_at' => $start->addMinutes(30)->toIso8601String(),
            'self_reported_arrival_from' => $start->subMinutes(15)->toIso8601String(),
            'unused_expiry_at' => $start->addMinutes(30)->toIso8601String(),
            'client_version_confirmed' => $clientConfirmedVersion, 'recorded_at' => now()->toIso8601String()];
    }
}
