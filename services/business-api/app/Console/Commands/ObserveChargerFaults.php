<?php

namespace App\Console\Commands;

use App\Services\Charging\ContinuousFaultObserver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ObserveChargerFaults extends Command
{
    protected $signature = 'csms:observe-faults {--once} {--interval=15} {--binding=}';

    protected $description = 'Continuously ingest bounded source fault/recovery events with durable per-mapping cursors; never send device commands';

    public function handle(ContinuousFaultObserver $observer): int
    {
        if (! config('csms.fault_worker_enabled') || ! config('csms.session_bridge_enabled') || ! app()->environment(['local', 'testing'])) {
            $this->error('Explicit local source fault worker configuration is required.');

            return self::FAILURE;
        }
        $interval = filter_var($this->option('interval'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 60]]);
        $id = $this->option('binding');
        if ($interval === false || ($id !== null && (! ctype_digit((string) $id) || (int) $id < 1 || strlen((string) $id) > 18))) {
            return self::INVALID;
        }
        $lock = fopen(storage_path('framework/csms-fault-worker.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('A local fault observer already owns the lock.');

            return self::FAILURE;
        }
        try {
            do {
                $failed = 0;
                $bindings = DB::table('csms_bindings')->whereNotNull('vendor_charger_id')->where('identifier', 'like', 'ev-test-%')->when($id, fn ($q) => $q->where('id', (int) $id))->orderBy('id')->lazyById(100);
                foreach ($bindings as $binding) {
                    try {
                        $this->line(json_encode($observer->observe($binding->id) + ['evidence' => 'SIMULATED_SOURCE'], JSON_THROW_ON_ERROR));
                    } catch (Throwable) {
                        $failed++;
                        $this->error('Binding '.$binding->id.' requires source/access review. No command sent.');
                    }
                }
                if ($this->option('once')) {
                    return $failed ? self::FAILURE : self::SUCCESS;
                }
                sleep($interval);
            } while (true);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
