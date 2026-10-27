<?php

namespace App\Console\Commands;

use App\Services\Commercial\SubscriptionDunning as Dunning;
use Illuminate\Console\Command;

class SubscriptionDunning extends Command
{
    protected $signature = 'commercial:subscription-dunning {--limit=100}';

    protected $description = 'Record due in-app subscription reminders without charging or retrying payments';

    public function handle(Dunning $dunning): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! $limit || $limit < 1 || $limit > 1000) {
            $this->error('Limit must be 1..1000.');

            return self::FAILURE;
        }
        $this->info('Recorded '.$dunning->scan($limit).' in-app reminders. No payment mutation was sent.');

        return self::SUCCESS;
    }
}
