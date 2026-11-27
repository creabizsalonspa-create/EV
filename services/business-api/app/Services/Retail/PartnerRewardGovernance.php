<?php

namespace App\Services\Retail;

use App\Models\User;
use App\Security\AdminTransactionAuthority;
use App\Services\Commercial\Loyalty\NoncashActivation;
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

    public function history(User $actor, int $partner, int $review, int $claimsPage = 1): array
    {
        return DB::transaction(function () use ($actor, $partner, $review, $claimsPage) {
            AdminTransactionAuthority::requireCurrent($actor);
            DB::table('retail_partners')->where('id', $partner)->lockForUpdate()->firstOrFail();
            $row = $this->forPartner($partner, $review);

            $claims = DB::table('partner_reward_claims as claim')
                ->leftJoin('loyalty_redemptions as redemption', 'redemption.id', '=', 'claim.redemption_id')
                ->leftJoin('partner_reward_review_events as approval', 'approval.id', '=', 'claim.approval_event_id')
                ->where('claim.review_id', $review)->orderByDesc('claim.id')
                ->paginate(25, [
                    'claim.id', 'claim.review_id', 'claim.redemption_id', 'claim.approval_event_id',
                    'claim.created_at', 'claim.user_id', 'redemption.user_id as redemption_user_id',
                    'redemption.reward_id', 'redemption.points_cost', 'redemption.status',
                    'approval.review_id as approval_review_id', 'approval.decision as approval_decision',
                ], 'claims_page', $claimsPage)->through(function ($claim) use ($row, $review) {
                    abort_unless((int) $claim->review_id === $review
                        && $claim->redemption_user_id !== null && (int) $claim->user_id === (int) $claim->redemption_user_id
                        && (int) $claim->reward_id === (int) $row->reward_id
                        && $claim->points_cost !== null && (string) $claim->points_cost === '0'
                        && (int) $claim->approval_review_id === $review && $claim->approval_decision === 'approve'
                        && in_array($claim->status, ['pending', 'fulfilled', 'rejected'], true), 409, 'Partner claim evidence is inconsistent.');

                    return [
                        'id' => (int) $claim->id, 'review_id' => $review,
                        'redemption_id' => (int) $claim->redemption_id, 'approval_event_id' => (int) $claim->approval_event_id,
                        'created_at' => $claim->created_at, 'status' => $claim->status,
                    ] + $this->sandboxEvidence();
                });

            return [
                'claims' => $claims,
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

    public function sandboxOffers()
    {
        abort_unless(app(NoncashActivation::class)->legacyPointsEnabled(), 503, 'Synthetic partner rewards are disabled.');
        $now = now()->utc();

        return DB::table('partner_reward_reviews as pr')
            ->join('partner_reward_review_events as decision', 'decision.review_id', '=', 'pr.id')
            ->join('retail_partner_events as terms', 'terms.partner_id', '=', 'pr.partner_id')
            ->join('retail_partner_versions as version', 'version.id', '=', 'pr.partner_version_id')
            ->join('loyalty_rewards as reward', 'reward.id', '=', 'pr.reward_id')
            ->join('regions', 'regions.country', '=', 'version.country')
            ->where('decision.decision', 'approve')
            ->whereIn('terms.action', ['approve', 'reinstate'])
            ->whereColumn('terms.revision', 'pr.partner_revision')
            ->whereColumn('terms.version_id', 'pr.partner_version_id')
            ->whereColumn('reward.currency', 'regions.currency')
            ->where('version.effective_from', '<=', $now)->where('version.expires_at', '>', $now)
            ->where('reward.starts_at', '<=', $now)->where('reward.ends_at', '>', $now)
            ->whereNotExists(fn ($query) => $query->from('partner_reward_review_events as later')
                ->whereColumn('later.review_id', 'decision.review_id')->whereColumn('later.revision', '>', 'decision.revision'))
            ->whereNotExists(fn ($query) => $query->from('retail_partner_events as later')
                ->whereColumn('later.partner_id', 'terms.partner_id')->whereColumn('later.revision', '>', 'terms.revision'))
            ->orderByDesc('pr.id')->paginate(25, [
                'pr.id as review_id', 'version.display_name as partner_name', 'version.display_name_ar as partner_name_ar',
                'reward.name', 'reward.name_ar', 'reward.description', 'reward.description_ar', 'reward.currency',
                'reward.starts_at', 'reward.ends_at',
            ])->through(fn ($row) => (array) $row + $this->sandboxEvidence());
    }

    public function lockEligibleSandboxReview(int $review, ?int $approvalEvent = null): array
    {
        abort_unless(app(NoncashActivation::class)->legacyPointsEnabled(), 503, 'Synthetic partner rewards are disabled.');
        $row = DB::table('partner_reward_reviews')->where('id', $review)->firstOrFail();
        // Registry decisions and reward review decisions hold the same partner row.
        DB::table('retail_partners')->where('id', $row->partner_id)->lockForUpdate()->firstOrFail();
        $terms = $this->registry->read($row->partner_id);
        $event = $this->latest($review);
        abort_unless($this->termsCurrent($row, $terms) && $event->decision === 'approve'
            && ($approvalEvent === null || (int) $event->id === $approvalEvent), 409, 'Current partner reward approval is required.');
        $this->validateRewardTerms(DB::table('loyalty_rewards')->find($row->reward_id), $terms);

        return [$row, $event];
    }

    public function sandboxEvidence(): array
    {
        return ['source' => 'RETAIL_PARTNER_REWARD', 'evidence' => 'SANDBOX_MANUAL_NONCASH',
            'external_fulfillment_verified' => false, 'cash_value' => false, 'points_cost' => 0];
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
