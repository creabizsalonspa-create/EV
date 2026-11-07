<?php

namespace App\Services\Charging;

use App\Models\ChargerCommand;
use App\Models\Charging\SessionLink;
use App\Models\ChargingSession;
use Illuminate\Support\Facades\DB;

/** Observe already authenticated, applied source evidence. Never send commands. */
class CommandReconciler
{
    public function forSession(int $sessionId): int
    {
        $link = SessionLink::where('charging_session_id', $sessionId)->first();
        if (! $link) {
            return 0;
        }
        $count = 0;
        foreach (array_filter([$link->start_command_id, $link->stop_command_id]) as $id) {
            $count += (int) $this->forCommand($id);
        }

        return $count;
    }

    public function forCommand(int $id): bool
    {
        // Only the command is locked. Do not invert cancellation's command -> link
        // order or hold source/session locks while waiting for an outbox claim.
        return DB::transaction(function () use ($id) {
            $command = ChargerCommand::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($command->status === 'cancelled' || ! isset($command->payload['session_id'])) {
                return false;
            }
            $link = SessionLink::where('charging_session_id', $command->payload['session_id'])->first();
            if (! $link || $link->closed_without_dispatch_at !== null || $link->transaction_id === null || $link->started_at === null || ! in_array($command->action, ['start', 'stop'], true) || ($command->action === 'start' ? $link->start_command_id : $link->stop_command_id) !== $command->id) {
                return false;
            }
            $binding = DB::table('csms_bindings')->where('id', $link->binding_id)->first();
            if (! $binding || $binding->station_id !== $command->station_id || $binding->identifier !== $link->identifier || $binding->protocol !== $link->protocol) {
                return false;
            }
            $session = ChargingSession::findOrFail($link->charging_session_id);
            $end = $command->action === 'stop';
            if ($end && ($link->ended_at === null || $session->status !== 'stopped' || $session->stopped_at === null)) {
                return false;
            }
            $event = DB::table('csms_inbox_events')->where('link_id', $link->id)->where('outcome', 'applied')->where('type', $end ? 'ended' : 'started')->orderBy('id')->first();
            if (! $event || (! $end && $event->sequence !== 0)) {
                return false;
            }
            if (DB::table('command_source_observations')->where('command_id', $command->id)->exists()) {
                return false;
            }
            DB::table('command_source_observations')->insert(['command_id' => $command->id, 'inbox_event_id' => $event->id, 'prior_status' => $command->status, 'prior_failure_code' => $command->failure_code, 'observed_state' => $end ? 'ended' : 'started', 'command_acceptance_verified' => false, 'created_at' => now()]);
            $command->update(['status' => 'source_observed', 'failure_code' => null]);

            return true;
        }, 3);
    }
}
