<?php

namespace App\Console\Commands;

use App\Services\Charging\ReservationLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ExpireUnusedReservations extends Command
{
    protected $signature = 'reservations:expire-unused {--limit=100} {--reservation=} {--scheduled}';

    protected $description = 'Expire simulator booking windows without linked sessions; no device or financial action';

    public function handle(ReservationLifecycle $lifecycle): int
    {
        $limit = (string) $this->option('limit');
        $id = $this->option('reservation');
        if (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 1000 || ($id !== null && (! ctype_digit((string) $id) || (int) $id < 1 || strlen((string) $id) > 18)) || ($this->option('scheduled') && $id !== null)) {
            $this->error('Use limit 1–1000 and an optional positive reservation; scheduled scans cannot target one reservation.');

            return self::INVALID;
        }
        $cursor = $this->option('scheduled') ? Cache::get('reservations:unused-expiry-cursor', 0) : 0;
        $result = $lifecycle->expire((int) $limit, $id === null ? null : (int) $id, is_int($cursor) && $cursor >= 0 ? $cursor : 0);
        if ($this->option('scheduled')) {
            Cache::forever('reservations:unused-expiry-cursor', $result['next_after_id']);
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
