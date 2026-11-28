<?php

namespace App\Console\Commands;

use App\Services\Charging\ContinuousFaultObserver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ObserveChargerFaults extends Command
{
    protected $signature = 'csms:observe-faults {--once} {--interval=15} {--binding=} {--limit=25} {--after=0}';

    protected $description = 'Continuously ingest bounded source fault/recovery events with durable per-mapping cursors; never send device commands';

    /**
     * Fixed review categories only. Never print arbitrary exception messages,
     * response bodies, URLs, database errors or transport credentials.
     */
    public static function reviewCode(Throwable $error): string
    {
        if (! $error instanceof HttpExceptionInterface) {
            return 'worker_internal_failure';
        }

        return match ($error->getMessage()) {
            'Fault source authority changed during observation.',
            'Authenticated CSMS source transport is not approved.' => 'source_authority',
            'Status source cursor lacks durable clock evidence.' => 'cursor_evidence',
            'Malformed source station projection.',
            'Malformed source station row.' => 'station_projection',
            'Malformed source status page or newest-event projection.',
            'Source reported newer events but returned an empty page.',
            'Source event page exceeds reported newest event.' => 'page_watermark',
            'Unsupported source connector status.',
            'Invalid OCPP 1.6 status.',
            'Invalid OCPP 2.0.1 status.',
            'Invalid or inconsistent OCPP 1.6 fault receipt.' => 'protocol_receipt',
            'Source event timestamp is invalid.',
            'Invalid source status time.',
            'Source status time is in the future.' => 'source_timestamp',
            'Source status chronology regressed.' => 'source_chronology',
            'Malformed source component identity.',
            'Invalid source component.' => 'component_identity',
            'Conflicting same-time source status receipts.',
            'Conflicting same-time status crosses the durable cursor.' => 'same_time_conflict',
            'Changed source event requires reconciliation.' => 'event_replay_conflict',
            default => match ($error->getStatusCode()) {
                401, 403, 404, 409 => 'scope_or_lineage',
                503 => 'source_unavailable',
                default => 'worker_internal_failure',
            },
        };
    }

    public function handle(ContinuousFaultObserver $observer): int
    {
        if (! config('csms.fault_worker_enabled') || ! config('csms.session_bridge_enabled') || ! app()->environment(['local', 'testing'])) {
            $this->error('Explicit local source fault worker configuration is required. [review:worker_disabled]');

            return self::FAILURE;
        }
        $interval = filter_var($this->option('interval'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 60]]);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $after = filter_var($this->option('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $id = $this->option('binding');
        if ($interval === false || $limit === false || $after === false
            || ($id !== null && ($after !== 0 || ! ctype_digit((string) $id) || (int) $id < 1 || strlen((string) $id) > 18))) {
            $this->error('Use interval 5–60, limit 1–100, and either one binding ID or an after cursor.');

            return self::INVALID;
        }
        $lock = fopen(storage_path('framework/csms-fault-worker.lock'), 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('A local fault observer already owns the lock. [review:worker_lock]');

            return self::FAILURE;
        }
        try {
            do {
                $failed = 0;
                // Limit work and remote requests per pass. The extra row indicates
                // whether the next pass should resume or wrap to the first binding.
                // Each binding keeps its existing independently committed source cursor.
                $page = DB::table('csms_bindings')->whereNotNull('vendor_charger_id')
                    ->where('identifier', 'like', 'ev-test-%')->where('id', '>', $after)
                    ->when($id !== null, fn ($q) => $q->where('id', (int) $id))
                    ->orderBy('id')->limit($limit + 1)->get(['id']);
                $bindings = $page->take($limit);
                $after = $page->count() > $limit ? (int) $bindings->last()->id : 0;
                foreach ($bindings as $binding) {
                    try {
                        $this->line(json_encode($observer->observe($binding->id) + ['evidence' => 'SIMULATED_SOURCE'], JSON_THROW_ON_ERROR));
                    } catch (Throwable $error) {
                        $failed++;
                        $this->error('Binding '.$binding->id.' requires source/access review. No command sent. [review:'.self::reviewCode($error).']');
                    }
                }
                if ($this->option('once')) {
                    if ($after > 0) {
                        $this->line(json_encode(['next_binding_after' => $after, 'evidence' => 'SIMULATED_SOURCE'], JSON_THROW_ON_ERROR));
                    }

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
