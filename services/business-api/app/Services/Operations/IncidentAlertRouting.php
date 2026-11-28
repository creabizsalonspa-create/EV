<?php

namespace App\Services\Operations;

use App\Models\InboxNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Local notices only; generic email handoff explicitly excludes this notice kind. */
final class IncidentAlertRouting
{
    private function eligible(User $user, ?object $preference): bool
    {
        return $preference && $preference->enabled && $user->role === 'admin'
            && $user->account_status === 'active' && (int) $preference->consent_epoch === (int) $user->auth_epoch;
    }

    public function preference(User $user): array
    {
        $row = DB::table('operational_incident_alert_preferences')->where('user_id', $user->id)->first();

        return ['enabled' => (bool) ($row->enabled ?? false), 'consent_current' => $this->eligible($user, $row),
            'scope' => 'current_admin_local_inbox', 'external_delivery_confirmed' => false];
    }

    /** Caller holds the current Admin account lock. */
    public function setPreference(User $user, bool $enabled): array
    {
        abort_unless(DB::transactionLevel() > 0 && $user->role === 'admin' && $user->account_status === 'active', 403);
        $row = DB::table('operational_incident_alert_preferences')->where('user_id', $user->id)->lockForUpdate()->first();
        if (($enabled && $this->eligible($user, $row)) || (! $enabled && ! ($row->enabled ?? false))) {
            return $this->preference($user);
        }
        DB::table('operational_incident_alert_preferences')->updateOrInsert(['user_id' => $user->id], [
            'enabled' => $enabled, 'consent_epoch' => $user->auth_epoch, 'updated_at' => now('UTC'),
        ]);
        DB::table('audit_events')->insert([
            'actor_id' => $user->id, 'action' => $enabled ? 'operations.alerts_opt_in' : 'operations.alerts_opt_out',
            'subject' => (string) $user->id, 'created_at' => now('UTC'),
        ]);

        return $this->preference($user);
    }

    public function run(int $maximum = 100): array
    {
        $result = ['notices_created' => 0, 'external_actions' => false];
        if (! config('operational_health.enabled')) {
            return $result;
        }
        $maximum = min(100, max(1, $maximum));
        // Only eligible recipients with unreviewed, unrecorded work consume
        // bounded candidate slots. Recheck all authority under locks below.
        $ids = DB::table('operational_incident_alert_preferences as pref')->join('users as recipient', 'recipient.id', '=', 'pref.user_id')
            ->where('pref.enabled', true)->where('recipient.role', 'admin')->where('recipient.account_status', 'active')
            ->whereColumn('pref.consent_epoch', 'recipient.auth_epoch')
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from('operational_incident_states as incident')->where('incident.state', 'open')
                    ->whereNotExists(fn ($review) => $review->selectRaw('1')->from('operational_incident_acknowledgements as ack')
                        ->whereColumn('ack.condition_key', 'incident.condition_key')->whereColumn('ack.transition_revision', 'incident.transitions'))
                    ->whereNotExists(fn ($receipt) => $receipt->selectRaw('1')->from('operational_incident_alert_receipts as sent')
                        ->whereColumn('sent.user_id', 'pref.user_id')->whereColumn('sent.condition_key', 'incident.condition_key')
                        ->whereColumn('sent.transition_revision', 'incident.transitions'));
            })->orderBy('pref.user_id')->limit($maximum)->pluck('pref.user_id');
        foreach ($ids as $id) {
            $result['notices_created'] += DB::transaction(function () use ($id) {
                $user = User::whereKey($id)->lockForUpdate()->first();
                $preference = DB::table('operational_incident_alert_preferences')->where('user_id', $id)->lockForUpdate()->first();
                if (! $user || ! $this->eligible($user, $preference) || ! config('operational_health.enabled')) {
                    return 0;
                }
                // Serialize with the scanner and reject old or missing scan
                // evidence. Open records are observations, not live alarms.
                $scan = DB::table('operational_incident_scan_locks')->where('id', 1)->lockForUpdate()->first();
                if (! $scan || ! $scan->last_completed_at) {
                    return 0;
                }
                $summary = app(OperationalIncidents::class)->summary();
                if (($summary['scanner']['state'] ?? null) !== 'recent') {
                    return 0;
                }
                $count = 0;
                $states = DB::table('operational_incident_states')->where('state', 'open')->orderBy('condition_key')->limit(6)->lockForUpdate()->get();
                foreach ($states as $state) {
                    $identity = ['condition_key' => $state->condition_key, 'transition_revision' => $state->transitions];
                    if (DB::table('operational_incident_acknowledgements')->where($identity)->exists()) {
                        continue;
                    }
                    if (! DB::table('operational_incident_alert_receipts')->insertOrIgnore($identity + ['user_id' => $user->id, 'created_at' => now('UTC')])) {
                        continue;
                    }
                    InboxNotification::create(['user_id' => $user->id, 'kind' => 'operations.incident.review',
                        'metadata' => ['condition_key' => $state->condition_key, 'transition_revision' => (int) $state->transitions,
                            'consent_epoch' => (string) $user->auth_epoch, 'source' => 'local_operational_observation']]);
                    $count++;
                }

                return $count;
            }, 3);
        }

        return $result;
    }
}
