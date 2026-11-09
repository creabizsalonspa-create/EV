<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Disposable database-queue recovery evidence. NEVER a live charging job.
 * Requires a synthetic probe table seeded only inside an isolated restore.
 */
final class Agent3SyntheticQueueRecoveryProbe implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TOKEN = 'SYNTHETIC_AGENT3_RECOVERY_V1';

    public const INTENT = 'queue-snapshot-intent';

    public const COMPLETED = 'queue-snapshot-executed';

    public function __construct(public string $token)
    {
    }

    public function handle(): void
    {
        if (! app()->environment(['testing', 'staging'])
            || config('queue.default') !== 'database'
            || config('csms.command_worker_enabled')
            || config('csms.simulator_enabled')
            || config('csms.session_bridge_enabled')
            || config('csms.fault_worker_enabled')
            || ! hash_equals(self::TOKEN, $this->token)) {
            throw new RuntimeException('Synthetic recovery queue probe is disabled.');
        }

        DB::transaction(function (): void {
            // This table exists only in a deliberately seeded disposable
            // recovery database. Missing intent always fails closed.
            $ready = DB::table('ev_agent3_recovery_probe')
                ->where('marker_key', self::INTENT)
                ->where('marker_value', self::TOKEN)
                ->lockForUpdate()->exists();
            if (! $ready) {
                throw new RuntimeException('Synthetic recovery queue intent is missing.');
            }
            DB::table('ev_agent3_recovery_probe')->insertOrIgnore([
                'marker_key' => self::COMPLETED,
                'marker_value' => self::TOKEN,
            ]);
        }, 3);
    }
}
