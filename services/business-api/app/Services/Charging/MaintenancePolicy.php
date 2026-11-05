<?php

namespace App\Services\Charging;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class MaintenancePolicy
{
    /** Half-open intervals: a booking may end exactly when maintenance begins. */
    public function blocked(int $connector, ?CarbonInterface $start = null, ?CarbonInterface $end = null): bool
    {
        $start ??= CarbonImmutable::now();
        $end ??= $start->toImmutable()->addSecond();

        return DB::table('connector_maintenance_windows')->where('connector_id', $connector)->whereNull('cancelled_at')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();
    }

    public function requireAvailable(int $connector, ?CarbonInterface $start = null, ?CarbonInterface $end = null): void
    {
        abort_if($this->blocked($connector, $start, $end), 409, 'Scheduled maintenance blocks new admission for this connector.');
    }
}
