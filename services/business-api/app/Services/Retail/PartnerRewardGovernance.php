<?php

namespace App\Services\Retail;

use App\Models\User;
use App\Security\AdminTransactionAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PartnerRewardGovernance
{
    public function __construct(private readonly PartnerRegistry $registry) {}

    public function record(User $snapshot, int $partner, ?int $review, string $decision, array $input): array
    {
        return DB::transaction(function () use ($snapshot, $partner, $review, $decision, $input) {
            $actor = AdminTransactionAuthority::requireCurrent($snapshot);
            $key = hash('sha256', 'partner-reward:'.$actor->id.':'.$input['idempotency_key']);
            $fingerprint = hash('sha256', json_encode([$partner, $review, $decision, $input], JSON_THROW_ON_ERROR));
            DB::table('retail_partners')->where('id', $partner)->lockForUpdate()->firstOrFail();
            $terms = $this->registry->read($partner);
            $prior = DB::table('partner_reward_review_events')->where('request_key', $key)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->fingerprint, $fingerprint), 409, 'Reward review request content changed.');

                return $this->projection($this->forPartner($partner, $prior->review_id), $terms);
            }

            if ($decision === 'propose') {
                abort_unless($terms['currently_effective'] && $terms['revision'] === $input['expected_partner_revision'], 409, 'Current approved partner terms are required.');
                $reward = DB::table('loyalty_rewards')->where('id', $input['reward_id'])->firstOrFail();
                $this->validateRewardTerms($reward, $terms);
                $previous = DB::table('partner_reward_reviews')->where('partner_id', $partner)
                    ->where('partner_revision', $terms['revision'])->where('reward_id', $reward->id)
                    ->orderByDesc('id')->first();
                abort_if($previous && in_array($this->latest($previous->id)->decision, ['propose', 'approve'], true), 409, 'This reward already has a pending or approved review.');

                $review = DB::table('partner_reward_reviews')->insertGetId([
                    'partner_id' => $partner, 'partner_version_id' => $terms['version']->id,
                    'partner_revision' => $terms['revision'], 'reward_id' => $reward->id,
                    'proposed_by' => $actor->id, 'created_at' => now(),
                ]);
                $revision = 1;
            } else {
                $row = $this->forPartner($partner, $review);
                $latest = $this->latest($row->id);
                abort_unless((int) $latest->revision === $input['expected_revision'], 409, 'Review changed. Refresh before deciding.');
                if ($decision === 'revoke') {
                    abort_unless($latest->decision === 'approve', 409, 'Only approved reviews can be revoked.');
                } else {
                    abort_unless(in_array($decision, ['approve', 'reject'], true) && $latest->decision === 'propose', 409, 'Only pending reviews can be decided.');
                    abort_if((int) $row->proposed_by === $actor->id, 409, 'A different administrator must review the proposal.');
                    if ($decision === 'approve') {
                        abort_unless($this->termsCurrent($row, $terms), 409, 'Partner terms changed or are no longer effective.');
                        $this->validateRewardTerms(DB::table('loyalty_rewards')->find($row->reward_id), $terms);
                    }
                }
                $revision = (int) $latest->revision + 1;
            }

            DB::table('partner_reward_review_events')->insert([
                'review_id' => $review, 'actor_id' => $actor->id, 'decision' => $decision,
                'revision' => $revision, 'reason' => $input['reason'], 'request_key' => $key,
                'fingerprint' => $fingerprint, 'created_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => $actor->id, 'action' => 'loyalty.partner_reward.'.$decision,
                'subject' => (string) $review, 'created_at' => now(),
            ]);

            return $this->projection($this->forPartner($partner, $review), $terms);
        }, 3);
    }

    public function index(User $actor, int $partner)
    {
        return DB::transaction(function () use ($actor, $partner) {
            AdminTransactionAuthority::requireCurrent($actor);
            DB::table('retail_partners')->where('id', $partner)->lockForUpdate()->firstOrFail();
            $terms = $this->registry->read($partner);

            return DB::table('partner_reward_reviews')->where('partner_id', $partner)->orderByDesc('id')->paginate(25)
                ->through(fn ($row) => $this->projection($row, $terms));
        }, 3);
    }

    public function history(User $actor, int $partner, int $review): array
    {
        return DB::transaction(function () use ($actor, $partner, $review) {
            AdminTransactionAuthority::requireCurrent($actor);
            DB::table('retail_partners')->where('id', $partner)->lockForUpdate()->firstOrFail();
            $row = $this->forPartner($partner, $review);

            return [
                'review' => $this->projection($row, $this->registry->read($partner)),
                'events' => DB::table('partner_reward_review_events')->where('review_id', $review)
                    ->orderByDesc('revision')->paginate(25, ['actor_id', 'decision', 'reason', 'revision', 'created_at']),
            ];
        }, 3);
    }

    private function forPartner(int $partner, int $review): object
    {
        return DB::table('partner_reward_reviews')->where('partner_id', $partner)->where('id', $review)->firstOrFail();
    }

    private function latest(int $review): object
    {
        return DB::table('partner_reward_review_events')->where('review_id', $review)->orderByDesc('revision')->firstOrFail();
    }

    private function validateRewardTerms(object $reward, array $terms): void
    {
        $currency = DB::table('regions')->where('country', $terms['version']->country)->value('currency');
        abort_unless($currency === $reward->currency &&
            CarbonImmutable::parse($reward->starts_at)->gte(CarbonImmutable::parse($terms['version']->effective_from)) &&
            CarbonImmutable::parse($reward->ends_at)->lte(CarbonImmutable::parse($terms['version']->expires_at)) &&
            CarbonImmutable::parse($reward->ends_at)->isFuture(), 409, 'Reward currency and dates must fit the current partner terms.');
    }

    private function termsCurrent(object $row, array $terms): bool
    {
        return $terms['currently_effective'] && (int) $row->partner_revision === $terms['revision']
            && (int) $row->partner_version_id === (int) $terms['version']->id;
    }

    private function projection(object $row, array $terms): array
    {
        $event = $this->latest($row->id);

        return (array) $row + [
            'decision' => $event->decision, 'revision' => (int) $event->revision,
            'decision_actor_id' => (int) $event->actor_id, 'reason' => $event->reason,
            'terms_current' => $this->termsCurrent($row, $terms),
            'fulfillment_enabled' => false,
        ];
    }
}
