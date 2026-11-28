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
        $held = 0;
        DB::table('commercial_subscriptions')->where('status', '!=', 'cancelled')->whereNotNull('period_end')->where('period_end', '<=', now())->orderBy('id')->chunkById(100, function ($rows) use ($service, &$count, &$held) {
            foreach ($rows as $row) {
                $result = $service->renew($row->id);
                if (isset($result['renewal_hold_reason'])) {
                    $held++;
                } else {
                    $count++;
                }
            }
        });
        $this->info('Processed '.$count.' due subscriptions. Held '.$held.' ineligible subscriptions. No payment was collected.');

        return self::SUCCESS;
    }
}
