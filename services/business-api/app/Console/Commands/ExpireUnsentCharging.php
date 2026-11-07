<?php

namespace App\Console\Commands;

use App\Services\Charging\UnsentStartExpiry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ExpireUnsentCharging extends Command
{
    protected $signature = 'csms:expire-unsent {--limit=100} {--reservation=} {--scheduled}';

    protected $description = 'Expire only proven undispatched local simulator starts after their reserved window';

    public function handle(UnsentStartExpiry $expiry): int
    {
        $limit = (string) $this->option('limit');
        $id = $this->option('reservation');
        if (($this->option('scheduled') && $id !== null) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 1000 || ($id !== null && (! ctype_digit((string) $id) || (int) $id < 1 || strlen((string) $id) > 18))) {
            $this->error('Use --limit=1..1000 and an optional positive --reservation id.');

            return self::INVALID;
        }
        $cursor = $this->option('scheduled') ? Cache::get('csms:unsent-expiry-cursor', 0) : 0;
        $result = $expiry->run((int) $limit, $id === null ? null : (int) $id, is_int($cursor) && $cursor >= 0 ? $cursor : 0);
        if ($this->option('scheduled')) {
            Cache::forever('csms:unsent-expiry-cursor', $result['next_after_id']);
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
