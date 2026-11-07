<?php

namespace App\Services\Charging;

use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Moderated anonymous feedback. Source observations never prove physical charging. */
class CustomerReviews
{
    public const TERMS = 'charging-feedback-v1';

    public function evidence(ChargingSession $session, User $actor): array
    {
        $reservation = DB::table('reservations')->find($session->reservation_id);
        abort_unless($reservation && $reservation->user_id === $actor->id && $actor->role === 'customer', 404);
        $station = Station::findOrFail($reservation->station_id);
        abort_unless($session->status === 'stopped' && $session->stopped_at && $session->energy_wh > 0 && $station->source === 'simulator' && $station->vendor_id !== $actor->id, 409, 'A completed positive-energy owned test session is required.');
        $link = DB::table('csms_session_links')->where('charging_session_id', $session->id)->first();
        $fingerprints = [];
        if ($link) {
            abort_unless($link->started_at && $link->ended_at && $link->transaction_id && ! $link->closed_without_dispatch_at && $link->last_sequence > 0 && $link->last_meter_wh >= $link->meter_start_wh && $link->last_meter_wh - $link->meter_start_wh === $session->energy_wh, 409, 'Completed source evidence requires review.');
            $events = DB::table('csms_inbox_events')->where('link_id', $link->id)->where('outcome', 'applied')->whereIn('type', ['started', 'ended'])->orderBy('sequence')->get();
            $start = $events->first(fn ($e) => $e->type === 'started' && $e->sequence === 0);
            $end = $events->first(fn ($e) => $e->type === 'ended' && $e->sequence === $link->last_sequence);
            abort_unless($start && $end && $start->meter_wh === $link->meter_start_wh && $end->meter_wh === $link->last_meter_wh && CarbonImmutable::parse($start->occurred_at)->equalTo(CarbonImmutable::parse($link->started_at)) && CarbonImmutable::parse($end->occurred_at)->equalTo(CarbonImmutable::parse($link->ended_at)), 409, 'Canonical source completion is required.');
            $binding = DB::table('csms_bindings')->find($link->binding_id);
            abort_unless($binding && $binding->station_id === $station->id && $binding->identifier === $link->identifier && $binding->protocol === $link->protocol, 409, 'Source station lineage requires review.');
            $fingerprints = [$link->id, $start->fingerprint, $end->fingerprint];
        }

        return ['station' => $station, 'evidence' => $link ? 'SIMULATED_SOURCE_OBSERVED' : 'SIMULATED_APP_ONLY', 'fingerprint' => hash('sha256', json_encode([$session->id, $reservation->id, $station->id, $station->vendor_id, $session->energy_wh, $session->stopped_at, $fingerprints], JSON_THROW_ON_ERROR))];
    }

    private function event(object $row): object
    {
        return DB::table('charging_review_events')->where('review_id', $row->id)->where('revision', $row->revision)->whereIn('action', ['create', 'revise'])->firstOrFail();
    }

    public function present(object $row, bool $private = false): array
    {
        $event = $this->event($row);
        $base = ['public_id' => $row->public_id, 'rating' => $event->rating, 'body' => Crypt::decryptString($event->body_ciphertext), 'evidence' => $row->evidence, 'physical_charging_verified' => false, 'created_at' => $event->created_at, 'station_name' => Station::find($row->station_id)?->name];
        if ($private) {
            $base += ['id' => $row->id, 'station_id' => $row->station_id, 'session_id' => $row->charging_session_id, 'state' => $row->state, 'version' => $row->version, 'revision' => $row->revision];
        }

        return $base;
    }

    public function mutate(User $user, string $action, array $v, ?ChargingSession $session = null, ?int $id = null): object
    {
        $hash = hash('sha256', json_encode([$action, $session?->id, $id, $v], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $action, $v, $session, $id, $hash) {
            $actor = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $old = DB::table('charging_review_events')->where('actor_id', $actor->id)->where('request_key', $v['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->intent_hash, $hash), 409, 'Request identity belongs to different feedback.');
                $review = DB::table('charging_reviews')->find($old->review_id);
                abort_unless($review && ($review->user_id === $actor->id || $actor->role === 'admin'), 404);

                return $review;
            }
            abort_if(DB::table('charging_review_events')->where('actor_id', $actor->id)->where('created_at', '>=', now()->subDay())->count() >= 30, 429, 'Feedback action limit reached.');
            if ($action === 'create') {
                abort_unless($actor->role === 'customer' && $session, 403);
                $source = $this->evidence(ChargingSession::whereKey($session->id)->lockForUpdate()->firstOrFail(), $actor);
                $station = Station::whereKey($source['station']->id)->lockForUpdate()->firstOrFail();
                abort_unless($station->vendor_id === $source['station']->vendor_id && $station->source === 'simulator', 409);
                abort_if(DB::table('charging_reviews')->where('user_id', $actor->id)->where('station_id', $station->id)->exists(), 409, 'Update your existing review for this station.');
                $id = DB::table('charging_reviews')->insertGetId(['public_id' => (string) Str::ulid(), 'user_id' => $actor->id, 'station_id' => $station->id, 'vendor_id' => $station->vendor_id, 'charging_session_id' => $session->id, 'evidence' => $source['evidence'], 'source_fingerprint' => $source['fingerprint'], 'state' => 'pending', 'version' => 1, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
                $row = DB::table('charging_reviews')->find($id);
            } else {
                $row = DB::table('charging_reviews')->where('id', $id)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($action, ['publish', 'reject', 'hide'], true) ? $actor->role === 'admin' : $row->user_id === $actor->id, 404);
                abort_unless($row->version === $v['expected_version'], 409, 'Refresh the changed review before another decision.');
                if ($action === 'revise') {
                    abort_unless($actor->role === 'customer', 403);
                    $proof = $this->evidence(ChargingSession::findOrFail($row->charging_session_id), $actor);
                    abort_unless(hash_equals($row->source_fingerprint, $proof['fingerprint']), 409, 'Historical source evidence changed.');
                }
                if ($action === 'publish') {
                    abort_unless($row->user_id !== $actor->id, 403, 'Reviewers cannot publish their own feedback.');
                    $proof = $this->evidence(ChargingSession::findOrFail($row->charging_session_id), User::findOrFail($row->user_id));
                    abort_unless(hash_equals($row->source_fingerprint, $proof['fingerprint']) && $proof['station']->vendor_id === $row->vendor_id, 409, 'Source feedback lineage changed.');
                }
                if (in_array($action, ['publish', 'reject'], true)) {
                    abort_unless($row->state === 'pending', 409, 'Only pending revisions can be decided.');
                }
                if ($action === 'hide') {
                    abort_unless($row->state === 'published', 409);
                }
                $state = ['revise' => 'pending', 'withdraw' => 'withdrawn', 'publish' => 'published', 'reject' => 'rejected', 'hide' => 'hidden'][$action];
                DB::table('charging_reviews')->where('id', $row->id)->update(['state' => $state, 'version' => $row->version + 1, 'revision' => $row->revision + ($action === 'revise' ? 1 : 0), 'updated_at' => now()]);
                $row = DB::table('charging_reviews')->find($row->id);
            }
            DB::table('charging_review_events')->insert(['review_id' => $row->id, 'actor_id' => $actor->id, 'request_key' => $v['request_key'], 'intent_hash' => $hash, 'action' => $action, 'version' => $row->version, 'revision' => $row->revision, 'rating' => $v['rating'] ?? null, 'body_ciphertext' => isset($v['body']) ? Crypt::encryptString($v['body']) : null, 'reason_ciphertext' => isset($v['reason']) ? Crypt::encryptString($v['reason']) : null, 'created_at' => now()]);
            DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => 'charging.review.'.$action, 'subject' => (string) $row->id, 'created_at' => now()]);

            return $row;
        }, 3);
    }
}
