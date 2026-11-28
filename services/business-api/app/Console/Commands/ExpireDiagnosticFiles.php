<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
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
        $expiredAt = now();
        $ids = DB::table('charger_diagnostic_logs')
            ->where(fn ($q) => $q
                ->where(fn ($request) => $request->where('expires_at', '<=', $expiredAt)
                    ->where(fn ($pending) => $pending->whereNotNull('upload_token_digest')
                        ->orWhereNotNull('payload_ciphertext')
                        ->orWhere(fn ($claim) => $claim->whereNull('cancelled_at')
                            ->whereIn('dispatch_state', ['requested', 'dispatching']))))
                ->orWhere(fn ($file) => $file->where('file_expires_at', '<=', $expiredAt)->whereNotNull('file_ciphertext')))
            ->orderBy('id')->limit($limit)->pluck('id');

        $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id, $expiredAt) {
                $row = DB::table('charger_diagnostic_logs')->where('id', $id)->lockForUpdate()->first();
                if (! $row) {
                    return 0;
                }

                $values = [];
                if ($expiredAt->gte(CarbonImmutable::parse($row->expires_at))) {
                    if ($row->upload_token_digest !== null || $row->payload_ciphertext !== null) {
                        $values += ['upload_token_digest' => null, 'payload_ciphertext' => null];
                    }
                    if ($row->cancelled_at === null && in_array($row->dispatch_state, ['requested', 'dispatching'], true)) {
                        // A claimed transport could have reached the source
                        // even when its acknowledgement never committed.
                        $values['dispatch_state'] = $row->dispatch_state === 'requested' && $row->attempted_at === null
                            ? 'not_dispatched' : 'outcome_unknown';
                    }
                }
                if ($row->file_ciphertext !== null && $row->file_expires_at && $expiredAt->gte(CarbonImmutable::parse($row->file_expires_at))) {
                    $values += ['file_ciphertext' => null, 'content_removed_at' => $row->content_removed_at ?? $expiredAt];
                }

                return $values === [] ? 0 : DB::table('charger_diagnostic_logs')->where('id', $id)
                    ->update($values + ['updated_at' => $expiredAt]);
            }, 3);
        }
        $this->info('Expired diagnostic records processed: '.$count.'. No source commands or billing changes.');

        return self::SUCCESS;
    }
}
