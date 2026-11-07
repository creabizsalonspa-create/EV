<?php

namespace App\Console\Commands;

use App\Services\Commercial\SubscriptionCollections;
use Illuminate\Console\Command;

class CollectSubscriptions extends Command
{
    protected $signature = 'commercial:collect-subscriptions {--limit=100}';

    protected $description = 'Issue due renewal invoices and collect only current consented test-provider mandates';

    public function handle(SubscriptionCollections $c): int
    {
        $n = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! $n || $n < 1 || $n > 1000) {
            $this->error('Limit must be 1..1000.');

            return self::FAILURE;
        }
        $this->info('Checked '.$c->scan($n).' subscription invoices. Test-provider evidence only.');

        return self::SUCCESS;
    }
}
