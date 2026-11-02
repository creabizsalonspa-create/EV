<?php

namespace App\Console\Commands;

use App\Services\Commercial\Payments\CorporateHostedCollection;
use Illuminate\Console\Command;

class ReconcileCorporateHosted extends Command
{
    protected $signature = 'commercial:reconcile-corporate-hosted {--limit=25} {--provider : Explicitly permit read-only Stripe TEST lookup}';

    protected $description = 'Inspect durable corporate hosted outcomes without initiating provider money movement';

    public function handle(CorporateHostedCollection $service): int
    {
        $limit = (string) $this->option('limit');
        if (! $this->option('provider') || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100) {
            $this->error('Use --provider and --limit=1..100. No provider request was sent.');

            return self::FAILURE;
        }
        $counts = $service->scan((int) $limit);
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

        return $counts['unresolved'] ? self::FAILURE : self::SUCCESS;
    }
}
