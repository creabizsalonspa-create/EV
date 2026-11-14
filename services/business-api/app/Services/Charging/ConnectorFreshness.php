<?php

namespace App\Services\Charging;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Public projection of accepted observations, never command or booking authority. */
final class ConnectorFreshness
{
    public const STATUSES = ['Available', 'Occupied', 'Preparing', 'Charging', 'SuspendedEV', 'SuspendedEVSE', 'Finishing', 'Reserved', 'Unavailable', 'Faulted'];

    public function present(?object $binding, ?int $evse, ?int $connector): array
    {
        $now = CarbonImmutable::now('UTC');
        $result = ['state' => 'unknown', 'reported_status' => null, 'observed_at' => null,
            'checked_at' => $now->toISOString(), 'valid_until' => null, 'max_age_seconds' => 300,
            'evidence' => 'SIMULATED_SOURCE', 'physical_availability_verified' => false];
        if (! $binding || $evse === null || $connector === null) {
            return $result;
        }
        $query = DB::table('charging_status_observations')->where('binding_id', $binding->id)
            ->where('source_generation', FaultMonitor::generation($binding))
            ->where('evse_number', $evse)->where('connector_number', $connector);
        $latest = (clone $query)->orderByDesc('occurred_at')->orderByDesc('id')->first();
        if (! $latest) {
            return $result;
        }
        $observed = CarbonImmutable::parse($latest->occurred_at, 'UTC');
        $result['observed_at'] = $observed->toISOString();
        $result['valid_until'] = $observed->addSeconds(300)->toISOString();
        // Conflicting reports at the same instant cannot be resolved by row ID.
        $conflict = (clone $query)->where('occurred_at', $latest->occurred_at)
            ->where(fn ($q) => $q->where('status', '!=', $latest->status)
                ->orWhereRaw('COALESCE(error_code, ?) <> ?', ['NoError', $latest->error_code ?? 'NoError']))->exists();
        if ($conflict || $observed->gt($now) || CarbonImmutable::parse($latest->created_at, 'UTC')->gt($now)) {
            return $result;
        }
        if ($observed->addSeconds(300)->lte($now)) {
            return array_replace($result, ['state' => 'stale']);
        }
        $status = $latest->status;
        if (! in_array($status, self::STATUSES, true)) {
            return $result;
        }

        return array_replace($result, ['state' => 'recent', 'reported_status' => ! in_array($latest->error_code, [null, 'NoError'], true) ? 'Faulted' : $status]);
    }
}
