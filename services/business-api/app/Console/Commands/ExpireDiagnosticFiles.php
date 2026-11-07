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
            $this->error('Limit must be1..1000.');

            return self::FAILURE;
        }
        $ids = DB::table('charger_diagnostic_logs')->where(fn ($q) => $q->where(fn ($x) => $x->where('expires_at', '<=', now())->whereNotNull('upload_token_digest'))->orWhere(fn ($x) => $x->where('file_expires_at', '<=', now())->whereNotNull('file_ciphertext')))->orderBy('id')->limit($limit)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$count) {
                $row = DB::table('charger_diagnostic_logs')->where('id', $id)->lockForUpdate()->first();
                if (! $row) {
                    return;
                }$values = [];
                if (now()->gte(CarbonImmutable::parse($row->expires_at))) {
                    $values += ['upload_token_digest' => null, 'payload_ciphertext' => null];
                }if ($row->file_expires_at && now()->gte(CarbonImmutable::parse($row->file_expires_at))) {
                    $values += ['file_ciphertext' => null, 'content_removed_at' => $row->content_removed_at ?? now()];
                }if ($values) {
                    DB::table('charger_diagnostic_logs')->where('id', $id)->update($values + ['updated_at' => now()]);
                    $count++;
                }
            });
        }
        $this->info('Expired diagnostic records processed: '.$count.'. No source commands or billing changes.');

        return self::SUCCESS;
    }
}
