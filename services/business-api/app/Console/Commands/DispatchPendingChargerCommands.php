<?php

namespace App\Console\Commands;

use App\Models\ChargerCommand;
use App\Services\Charging\RemoteSessionAuthorization;
use App\Services\CsmsDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Drives only previously authorized canonical outbox rows; never retries delivery. */
class DispatchPendingChargerCommands extends Command
{
    protected $signature = 'csms:dispatch-pending {--once} {--limit=25} {--interval=2} {--station=}';

    protected $description = 'Dispatch pending canonical simulator commands once; uncertain, blocked and unlinked commands are never replayed';

    public function handle(CsmsDispatcher $dispatcher): int
    {
        if (! config('csms.command_worker_enabled') || ! config('csms.simulator_enabled') || ! config('csms.session_bridge_enabled') || ! app()->environment(['local', 'testing'])) {
            $this->error('Explicit local simulator command worker configuration is required.');

            return self::FAILURE;
        }
        $limit = (string) $this->option('limit');
        $interval = (string) $this->option('interval');
        $station = $this->option('station');
        if (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100 || ! ctype_digit($interval) || (int) $interval < 1 || (int) $interval > 30 || ($station !== null && (! ctype_digit((string) $station) || (int) $station < 1 || strlen((string) $station) > 18))) {
            $this->error('Use limit1..100, interval1..30 and an optional positive station ID.');

            return self::INVALID;
        }
        $lock = fopen(storage_path('framework/csms-command-worker.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('A local command worker already owns the lock.');

            return self::FAILURE;
        }
        try {
            do {
                $rows = ChargerCommand::where('status', 'pending')->whereNull('failure_code')->whereIn('action', ['start', 'stop'])
                    ->whereIn('station_id', DB::table('stations')->where('source', 'simulator')->select('id'))
                    ->whereExists(function ($q) {
                        $q->selectRaw('1')->from('csms_session_links as link')->where(function ($q) {
                            $q->whereColumn('link.start_command_id', 'charger_commands.id')->orWhereColumn('link.stop_command_id', 'charger_commands.id');
                        });
                    })
                    ->when($station !== null, fn ($q) => $q->where('station_id', (int) $station))->orderBy('id')->limit((int) $limit)->get(['id']);
                $failed = 0;
                foreach ($rows as $row) {
                    try {
                        // Dispatcher atomically claims pending -> dispatching and
                        // rechecks booking, maintenance, company and source lineage.
                        // A second process can only observe that claim, never resend.
                        $command = ChargerCommand::findOrFail($row->id);
                        if ($command->action === 'start') {
                            app(RemoteSessionAuthorization::class)->publish($command);
                        }
                        $result = $dispatcher->dispatch($row->id);
                        $this->line(json_encode(['command_id' => $row->id, 'state' => $result->status, 'evidence' => 'SIMULATED_CSMS', 'acceptance_verified' => false], JSON_THROW_ON_ERROR));
                    } catch (Throwable $e) {
                        $failed++;
                        // Failures preceding a dispatch claim must not become an
                        // unattended retry loop. Operator review remains explicit.
                        ChargerCommand::whereKey($row->id)->where('status', 'pending')->whereNull('failure_code')->update(['failure_code' => 'worker_requires_operator_review']);
                        $this->error('Command '.$row->id.' requires operator review ('.$e::class.').');
                    }
                }
                if ($this->option('once')) {
                    return $failed ? self::FAILURE : self::SUCCESS;
                }
                sleep((int) $interval);
            } while (true);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
