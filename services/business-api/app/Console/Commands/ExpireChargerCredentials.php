<?php

namespace App\Console\Commands;

use App\Services\Charging\AdmissionLifecycle;
use Illuminate\Console\Command;

class ExpireChargerCredentials extends Command
{
    protected $signature = 'csms:expire-credentials {--limit=100}';

    protected $description = 'Disable expired admission credentials and retain an audit event; no charger commands';

    public function handle(AdmissionLifecycle $lifecycle): int
    {
        $limit = $this->option('limit');
        if (! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 1000) {
            $this->error('Limit must be1..1000.');

            return self::FAILURE;
        }
        $count = $lifecycle->scan((int) $limit);
        $this->info('Expired admission credentials: '.$count.'. No physical stop or certificate revocation is implied.');

        return self::SUCCESS;
    }
}
