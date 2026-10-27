<?php

namespace App\Console\Commands;

use App\Models\ChargerCommand;
use App\Services\Charging\CommandReconciler;
use App\Services\Charging\SessionBridge;
use Illuminate\Console\Command;

class ReconcileSourceCommands extends Command
{
    protected $signature = 'csms:reconcile-commands {--limit=100} {--after=0}';

    protected $description = 'Reconcile already applied simulator inbox evidence without sending or retrying device commands';

    public function handle(CommandReconciler $reconciler, SessionBridge $bridge): int
    {
        $bridge->enabled();
        $limit = (string) $this->option('limit');
        $after = (string) $this->option('after');
        if (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 1000 || ! ctype_digit($after) || strlen($after) > 18) {
            $this->error('Use limit1–1000 and a nonnegative after ID.');

            return self::INVALID;
        }
        $ids = ChargerCommand::where('id', '>', (int) $after)->whereNotIn('status', ['cancelled', 'source_observed'])->whereNotNull('payload->session_id')->orderBy('id')->limit((int) $limit)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $count += (int) $reconciler->forCommand($id);
        }
        $this->line(json_encode(['examined' => $ids->count(), 'reconciled' => $count, 'next_after_id' => $ids->count() === (int) $limit ? $ids->last() : 0, 'network_requests' => 0, 'command_acceptance_verified' => false], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
