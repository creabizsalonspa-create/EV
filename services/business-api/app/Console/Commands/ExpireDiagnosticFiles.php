<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ExpireDiagnosticFiles extends Command
{
    protected $signature = 'csms:expire-diagnostic-files {--limit=100}';

    protected $description = 'Remove expired encrypted diagnostic content/capabilities; retain immutable request receipts';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('Limit must be 1..1000.');

            return self::FAILURE;
        }

        $cutoff = now('UTC')->toImmutable();
        $timestamp = $cutoff->format('Y-m-d H:i:s.u');
        $ids = DB::table('charger_diagnostic_logs')
            ->where(function (Builder $query) use ($timestamp) {
                $query->where(fn (Builder $request) => $request
                    ->where('expires_at', '<=', $timestamp)
                    ->where(fn (Builder $content) => $content
                        ->whereNotNull('upload_token_digest')->orWhereNotNull('payload_ciphertext')
                        ->orWhere(fn (Builder $claim) => $claim->whereNull('cancelled_at')
                            ->whereIn('dispatch_state', ['requested', 'dispatching']))))
                    ->orWhere(fn (Builder $file) => $file
                        ->where('file_expires_at', '<=', $timestamp)->whereNotNull('file_ciphertext'));
            })
            ->orderBy('id')->limit($limit)->pluck('id');

        $count = 0;
        foreach ($ids as $id) {
            $count += (int) DB::transaction(function () use ($id, $cutoff) {
                $row = DB::table('charger_diagnostic_logs')->where('id', $id)->lockForUpdate()->first();
                if (! $row) {
                    return false;
                }

                $values = [];
                if ($cutoff->gte(CarbonImmutable::parse($row->expires_at, 'UTC'))) {
                    if ($row->upload_token_digest !== null || $row->payload_ciphertext !== null) {
                        $values += ['upload_token_digest' => null, 'payload_ciphertext' => null];
                    }
                    if ($row->cancelled_at === null && in_array($row->dispatch_state, ['requested', 'dispatching'], true)) {
                        // A claimed transport may have reached the source even if its receipt never committed.
                        $values['dispatch_state'] = $row->dispatch_state === 'requested' && $row->attempted_at === null
                            ? 'not_dispatched' : 'outcome_unknown';
                    }
                }
                if ($row->file_ciphertext !== null && $row->file_expires_at !== null
                    && $cutoff->gte(CarbonImmutable::parse($row->file_expires_at, 'UTC'))) {
                    $values += ['file_ciphertext' => null, 'content_removed_at' => $row->content_removed_at ?? $cutoff];
                }
                if ($values === []) {
                    return false;
                }

                return DB::table('charger_diagnostic_logs')->where('id', $id)->update($values + ['updated_at' => $cutoff]) === 1;
            }, 3);
        }

        $this->info('Expired diagnostic records processed: '.$count.'. No source commands or billing changes.');

        return self::SUCCESS;
    }
}
