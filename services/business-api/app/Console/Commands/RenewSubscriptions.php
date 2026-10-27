<?php

namespace App\Console\Commands;

use App\Services\Commercial\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RenewSubscriptions extends Command
{
    protected $signature = 'commercial:renew-subscriptions';

    protected $description = 'Issue due subscription cycle invoices or expire cancellations; never capture funds';

    public function handle(SubscriptionService $service): int
    {
        $count = 0;
        DB::table('commercial_subscriptions')->where('status', '!=', 'cancelled')->whereNotNull('period_end')->where('period_end', '<=', now())->orderBy('id')->chunkById(100, function ($rows) use ($service, &$count) {
            foreach ($rows as $row) {
                $service->renew($row->id);
                $count++;
            }
        });
        $this->info('Processed '.$count.' due subscriptions. No payment was collected.');

        return self::SUCCESS;
    }
}
