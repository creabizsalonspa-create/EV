<?php

namespace App\Console\Commands;

use App\Services\Commercial\Disputes\ExternalDisputes;
use Illuminate\Console\Command;

class ReconcileExternalDisputes extends Command
{
    protected $signature = 'commercial:reconcile-disputes {--limit=25} {--provider : Explicitly permit read-only Stripe TEST provider calls}';

    protected $description = 'Bounded provider dispute inspection only; never initiate money movement or submit evidence';

    public function handle(ExternalDisputes $service): int
    {
        $limit = (string) $this->option('limit');
        if (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100) {
            $this->error('Limit must be between 1 and 100.');

            return self::FAILURE;
        }
        if (! $this->option('provider')) {
            $this->error('Provider calls require explicit --provider opt-in. No requests sent.');

            return self::FAILURE;
        }
        $counts = $service->scan((int) $limit);
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

        return $counts['failed'] || $counts['attention'] ? self::FAILURE : self::SUCCESS;
    }
}
