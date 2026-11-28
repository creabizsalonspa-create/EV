<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Commercial\Loyalty\LoyaltyService;
use App\Services\Commercial\Loyalty\NoncashActivation;
use App\Services\Commercial\Referrals\ReferralRegistry;
use App\Services\Retail\PartnerRewardGovernance;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LoyaltyController extends Controller
{
    /** Linearize every scoped read/write with its original authenticated actor. */
    public function callAction($method, $parameters)
    {
        $request = collect($parameters)->first(fn ($value) => $value instanceof Request);
        abort_unless($request?->user(), 401);

        return DB::transaction(function () use ($method, $parameters, $request) {
            $snapshot = $request->user();
            $current = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->account_status === 'active' && $current->role === $snapshot->role, 403);
            abort_unless((int) $current->auth_epoch === (int) $snapshot->auth_epoch
                && hash_equals((string) $current->password, (string) $snapshot->password), 409, 'Account credentials changed; authenticate again.');

            return $this->{$method}(...array_values($parameters));
        }, 3);
    }

    private function admin(Request $r): void
    {
        abort_unless($r->user()->role === 'admin', 403);
    }

    private function owner(Request $r, int $user): void
    {
        abort_unless($r->user()->role === 'admin' || $r->user()->id === $user, 404);
    }

    private function safe(object $row): array
    {
        $safe = (array) $row;
        unset($safe['request_key'], $safe['fingerprint']);

        return $safe;
    }

    public function publishCoupon(Request $r)
    {
        $this->admin($r);
        app(NoncashActivation::class)->requireEnabled();
        $values = $r->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z0-9-]{12,40}$/D'],
            'listed' => 'sometimes|boolean',
            'reward_id' => 'required|integer|min:1',
            'claim_limit' => 'required|integer|between:1,1000000',
            'per_user_limit' => 'required|integer|between:1,100|lte:claim_limit',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'idempotency_key' => 'required|string|max:100',
        ]);
        $publication = hash('sha256', 'coupon-publish:'.$r->user()->id.':'.$values['idempotency_key']);
        $codeHash = hash('sha256', $values['code']);
        unset($values['idempotency_key'], $values['code']);
        foreach (['reward_id', 'claim_limit', 'per_user_limit'] as $field) {
            $values[$field] = (int) $values[$field];
        }
        foreach (['starts_at', 'ends_at'] as $field) {
            $values[$field] = Carbon::parse($values[$field])->utc()->toDateTimeString();
        }
        abort_unless($values['starts_at'] < $values['ends_at'], 422);
        $values['listed'] = (bool) ($values['listed'] ?? false);
        $fingerprint = hash('sha256', json_encode([$codeHash, $values], JSON_THROW_ON_ERROR));
        if ($old = DB::table('loyalty_coupons')->where('publication_key', $publication)->first()) {
            abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, 'Coupon publication retry content changed.');

            return response()->json($this->couponDetails($old))->header('Cache-Control', 'private, no-store');
        }
        abort_unless(Carbon::parse($values['ends_at'])->isFuture(), 422, 'A new coupon must have a future expiry.');
        $reward = DB::table('loyalty_rewards')->where('id', $values['reward_id'])->lockForUpdate()->first();
        abort_unless($reward && $values['starts_at'] >= $reward->starts_at
            && $values['ends_at'] <= $reward->ends_at, 422, 'Coupon dates must be within the reward window.');
        $now = now()->utc();
        try {
            $id = DB::transaction(fn () => DB::table('loyalty_coupons')->insertGetId($values + [
                'code_hash' => $codeHash, 'publication_key' => $publication, 'fingerprint' => $fingerprint,
                'created_by' => $r->user()->id, 'created_at' => $now, 'updated_at' => $now,
            ]));
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Coupon code already allocated.');
        }
        app(NoncashActivation::class)->record('coupon', (int) $id);
        $this->couponAudit($r, 'published', $id);

        return response()->json($this->couponDetails(DB::table('loyalty_coupons')->find($id)), 201)->header('Cache-Control', 'private, no-store');
    }

    public function revokeCoupon(Request $r, int $coupon)
    {
        $this->admin($r);
        $v = $r->validate(['reason' => 'required|string|min:5|max:500']);
        $row = DB::table('loyalty_coupons')->where('id', $coupon)->lockForUpdate()->first() ?? abort(404);
        $prior = DB::table('loyalty_coupon_revocations')->where('coupon_id', $coupon)->first();
        abort_unless(! $prior || hash_equals($prior->reason, $v['reason']), 409, 'Revocation retry reason changed.');
        if ($row->revoked_at === null) {
            $now = now()->utc();
            DB::table('loyalty_coupons')->where('id', $coupon)->update(['revoked_at' => $now, 'updated_at' => $now]);
            DB::table('loyalty_coupon_revocations')->insert(['coupon_id' => $coupon, 'actor_id' => $r->user()->id, 'reason' => $v['reason'], 'created_at' => $now]);
            $this->couponAudit($r, 'revoked', $coupon);
        }

        return response()->json($this->couponDetails(DB::table('loyalty_coupons')->find($coupon)))->header('Cache-Control', 'private, no-store');
    }

    public function coupons(Request $r)
    {
        $this->admin($r);
        $r->validate(['page' => 'sometimes|integer|between:1,100000']);

        return response()->json(DB::table('loyalty_coupons')->orderByDesc('id')->paginate(25)
            ->through(fn ($row) => $this->couponDetails($row)))->header('Cache-Control', 'private, no-store');
    }

    public function discoverCoupons(Request $r, LoyaltyService $service)
    {
        abort_unless($r->user()->role === 'customer', 403);
        $r->validate(['page' => 'sometimes|integer|between:1,100000']);
        $user = $r->user()->id;
        $now = now()->utc();
        $account = $service->account($user);
        $query = DB::table('loyalty_coupons as coupon')
            ->join('loyalty_rewards as reward', 'reward.id', '=', 'coupon.reward_id')
            ->where('coupon.listed', true)->whereNull('coupon.revoked_at')
            ->where('coupon.starts_at', '<=', $now)->where('coupon.ends_at', '>', $now)
            ->where('reward.starts_at', '<=', $now)->where('reward.ends_at', '>', $now);
        foreach ([
            ['loyalty_coupon_claims', 'coupon_id', 'coupon.id', 'coupon.claim_limit', false],
            ['loyalty_coupon_claims', 'coupon_id', 'coupon.id', 'coupon.per_user_limit', true],
            ['loyalty_redemptions', 'reward_id', 'reward.id', 'reward.stock', false],
            ['loyalty_redemptions', 'reward_id', 'reward.id', 'reward.per_user_limit', true],
        ] as [$table, $column, $identity, $limit, $personal]) {
            $query->where(function ($count) use ($table, $column, $identity, $personal, $user) {
                $count->from($table)->selectRaw('count(*)')->whereColumn($column, $identity);
                if ($personal) {
                    $count->where('user_id', $user);
                }
                if ($table === 'loyalty_redemptions') {
                    $count->whereIn('status', ['pending', 'fulfilled']);
                }
            }, '<', DB::raw($limit));
        }
        $today = DB::table('loyalty_redemptions')->where('user_id', $user)
            ->where('created_at', '>=', $now->copy()->startOfDay())->count();
        $query->where(function ($policy) use ($now) {
            $policy->from('loyalty_policies')->select('daily_redemption_limit')
                ->whereColumn('currency', 'reward.currency')->where('effective_from', '<=', $now)
                ->orderByDesc('effective_from')->orderByDesc('id')->limit(1);
        }, '>', $today);
        $query->where(function ($policy) use ($now) {
            $policy->from('loyalty_policies')->select('enabled')
                ->whereColumn('currency', 'reward.currency')->where('effective_from', '<=', $now)
                ->orderByDesc('effective_from')->orderByDesc('id')->limit(1);
        }, '=', true);
        $activation = app(NoncashActivation::class);
        $mode = $activation->mode();
        if ($mode === 'sandbox') {
            // Pre-activation rows are legacy sandbox evidence only. Reviewed
            // coupons must never become sandbox commitments after a flag change.
            $query->whereNotExists(fn ($e) => $e->selectRaw('1')
                ->from('loyalty_coupon_activation_evidence as e')
                ->whereColumn('e.coupon_id', 'coupon.id')
                ->where('e.activation_mode', '!=', 'sandbox'));
        } elseif ($mode === 'reviewed_manual') {
            // Match the same immutable approval and terms required by claim().
            $query->whereExists(fn ($e) => $e->selectRaw('1')
                ->from('loyalty_coupon_activation_evidence as e')
                ->whereColumn('e.coupon_id', 'coupon.id')
                ->where('e.activation_mode', 'reviewed_manual')
                ->where('e.approval_reference', config('loyalty.noncash.approval_reference'))
                ->where('e.terms_sha256', config('loyalty.noncash.terms_sha256')));
            if (DB::table('loyalty_awards')->where('user_id', $user)->exists()
                || DB::table('loyalty_ledger')->where('user_id', $user)->where('points', '!=', 0)->exists()) {
                $query->whereRaw('1 = 0');
            }
        } else {
            $query->whereRaw('1 = 0');
        }
        // Claim reconciles refundable/expired point lots before checking debt.
        // The read projection must not advertise a benefit against pending debt.
        if ($account['redemption_hold'] || $account['balance_points'] < $account['pending_reconciliation_points']) {
            $query->whereRaw('1 = 0');
        }

        return response()->json($query->orderBy('coupon.ends_at')->orderBy('coupon.id')
            ->paginate(25, ['coupon.id', 'coupon.ends_at', 'reward.name as reward_name', 'reward.name_ar as reward_name_ar']))
            ->header('Cache-Control', 'private, no-store');
    }

    public function claimListedCoupon(Request $r, int $coupon, LoyaltyService $service)
    {
        abort_unless($r->user()->role === 'customer', 403);
        $values = $r->validate(['idempotency_key' => 'required|string|max:100']);
        $claim = $service->claimCoupon($r->user()->id, null, $values['idempotency_key'], $coupon);

        return response()->json(['claim' => $this->couponReceipt($claim)])->header('Cache-Control', 'private, no-store');
    }

    public function claimCoupon(Request $r, LoyaltyService $service)
    {
        abort_unless($r->user()->role === 'customer', 403);
        $values = $r->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z0-9-]{12,40}$/D'],
            'idempotency_key' => 'required|string|max:100',
        ]);
        $claim = $service->claimCoupon($r->user()->id, $values['code'], $values['idempotency_key']);

        return response()->json(['claim' => $this->couponReceipt($claim)])->header('Cache-Control', 'private, no-store');
    }

    public function couponClaims(Request $r)
    {
        abort_unless($r->user()->role === 'customer', 403);
        $r->validate(['page' => 'sometimes|integer|between:1,100000']);

        return response()->json(DB::table('loyalty_coupon_claims')->where('user_id', $r->user()->id)
            ->orderByDesc('id')->paginate(25)->through(fn ($row) => $this->couponReceipt($row)))->header('Cache-Control', 'private, no-store');
    }

    public function findCouponReceipt(Request $r)
    {
        abort_unless($r->user()->role === 'customer', 403);
        $values = $r->validate(['idempotency_key' => 'required|string|max:100']);
        $key = hash('sha256', 'coupon-claim:'.$r->user()->id.':'.$values['idempotency_key']);
        $row = DB::table('loyalty_coupon_claims')->where('user_id', $r->user()->id)->where('request_key', $key)->first();

        return response()->json(['claim' => $row ? $this->couponReceipt($row) : null])->header('Cache-Control', 'private, no-store');
    }

    public function cancelCouponIntent(Request $r, LoyaltyService $service)
    {
        abort_unless($r->user()->role === 'customer', 403);
        $v = $r->validate([
            'code' => ['required_without:coupon_id', 'prohibits:coupon_id', 'string', 'regex:/^[A-Z0-9-]{12,40}$/D'],
            'coupon_id' => 'required_without:code|prohibits:code|integer|min:1',
            'idempotency_key' => 'required|string|max:100',
        ]);
        $coupon = isset($v['coupon_id']) ? (int) $v['coupon_id'] : null;
        $result = $service->cancelCouponIntent($r->user()->id, $v['code'] ?? null, $v['idempotency_key'], $coupon);
        $request = hash('sha256', 'coupon-claim:'.$r->user()->id.':'.$v['idempotency_key']);
        $binding = $coupon === null ? hash('sha256', hash('sha256', $v['code']).':'.$request)
            : hash('sha256', 'coupon-cancel-v1:'.$coupon.':'.$request);

        return response()->json(['state' => $result['state'],
            'request_binding' => $binding,
            'claim' => $result['claim'] ? $this->couponReceipt($result['claim']) : null,
        ] + ($coupon === null ? [] : ['coupon_id' => $coupon]))->header('Cache-Control', 'private, no-store');
    }

    private function couponReceipt(object $row): array
    {
        $redemption = DB::table('loyalty_redemptions')->find($row->redemption_id);
        $reward = DB::table('loyalty_rewards')->find($redemption->reward_id);

        return [
            'id' => $row->id, 'coupon_id' => $row->coupon_id, 'redemption_id' => $redemption->id,
            'reward_name' => $reward->name, 'reward_name_ar' => $reward->name_ar, 'status' => $redemption->status,
            'claimed_at' => Carbon::parse($row->created_at, 'UTC')->utc()->toISOString(),
            'request_binding' => hash('sha256', DB::table('loyalty_coupons')->where('id', $row->coupon_id)->value('code_hash').':'.$row->request_key),
            'source' => 'COUPON', ...app(NoncashActivation::class)->metadata('coupon', (int) $row->coupon_id), 'delivery_verified' => false,
            'points_cost' => 0, 'cash_value' => false, 'fulfillment' => 'MANUAL_NON_MONETARY',
        ];
    }

    private function couponDetails(object $row): array
    {
        $safe = (array) $row;
        unset($safe['code_hash'], $safe['publication_key'], $safe['fingerprint']);

        return $safe + ['claims' => DB::table('loyalty_coupon_claims')->where('coupon_id', $row->id)->count(),
            'revocation' => DB::table('loyalty_coupon_revocations')->where('coupon_id', $row->id)->first(),
            ...app(NoncashActivation::class)->metadata('coupon', (int) $row->id, true), 'cash_value' => false];
    }

    private function couponAudit(Request $r, string $action, int $coupon): void
    {
        DB::table('audit_events')->insert([
            'actor_id' => $r->user()->id, 'action' => 'loyalty.coupon.'.$action,
            'subject' => (string) $coupon, 'created_at' => now(),
        ]);
    }

    public function couponPage(Request $r)
    {
        $this->admin($r);

        return response()->view('reward-coupons')->header('Cache-Control', 'no-store, private');
    }

    public function findCouponPublication(Request $r)
    {
        $this->admin($r);
        $v = $r->validate(['idempotency_key' => 'required|string|max:100']);
        $key = hash('sha256', 'coupon-publish:'.$r->user()->id.':'.$v['idempotency_key']);
        $row = DB::table('loyalty_coupons')->where('created_by', $r->user()->id)->where('publication_key', $key)->first();

        return response()->json(['coupon' => $row ? $this->couponDetails($row) : null])->header('Cache-Control', 'private, no-store');
    }

    public function noncashStatus(Request $r)
    {
        abort_unless(in_array($r->user()->role, ['admin', 'customer'], true), 403);
        $policy = app(NoncashActivation::class);

        return response()->json(['enabled' => $policy->enabled(), 'activation_mode' => $policy->mode(),
            'points_actions_enabled' => $policy->legacyPointsEnabled(), 'money_movement' => false,
            'fulfillment' => 'MANUAL_NON_MONETARY', 'delivery_verified' => false,
            'referrals_enabled' => app(ReferralRegistry::class)->enabled(),
            'verified_live_source_required' => app()->environment('production'),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function account(Request $r, LoyaltyService $service)
    {
        return response()->json($service->account($r->user()->id));
    }

    public function ledger(Request $r)
    {
        return DB::table('loyalty_ledger')->where('user_id', $r->user()->id)->orderByDesc('id')->paginate(25);
    }

    public function policies(Request $r)
    {
        return DB::table('loyalty_policies')->orderByDesc('effective_from')->orderByDesc('id')->paginate(25)->through(fn ($row) => $this->safe($row));
    }

    public function publishPolicy(Request $r, LoyaltyService $service)
    {
        $this->admin($r);
        $v = $r->validate(['currency' => ['required', 'regex:/^[A-Z]{3}$/'], 'minor_units' => 'required|integer|min:0|max:3', 'enabled' => 'required|boolean', 'points_numerator' => 'required|integer|min:1|max:10000', 'minor_denominator' => 'required|integer|min:1|max:1000000000', 'gold_threshold' => 'required|integer|min:1|max:100000000', 'platinum_threshold' => 'required|integer|gt:gold_threshold|max:1000000000', 'gold_multiplier_bps' => 'required|integer|min:10000|max:100000', 'platinum_multiplier_bps' => 'required|integer|gte:gold_multiplier_bps|max:100000', 'invoice_cap' => 'required|integer|min:1|max:1000000', 'daily_cap' => 'required|integer|gte:invoice_cap|max:10000000', 'expiry_days' => 'required|integer|min:1|max:3650', 'daily_redemption_limit' => 'required|integer|min:1|max:100', 'effective_from' => 'required|date', 'idempotency_key' => 'required|string|max:100']);
        $key = $v['idempotency_key'];
        unset($v['idempotency_key']);
        foreach (['minor_units', 'points_numerator', 'minor_denominator', 'gold_threshold', 'platinum_threshold', 'gold_multiplier_bps', 'platinum_multiplier_bps', 'invoice_cap', 'daily_cap', 'expiry_days', 'daily_redemption_limit'] as $field) {
            $v[$field] = (int) $v[$field];
        }
        $v['enabled'] = (bool) $v['enabled'];
        $v['effective_from'] = Carbon::parse($v['effective_from'])->utc()->format('Y-m-d H:i:s');

        return response()->json(['policy' => $this->safe($service->publish('loyalty_policies', $r->user(), $v, $key))], 201);
    }

    public function rewards(Request $r)
    {
        return DB::table('loyalty_rewards')->when($r->user()->role !== 'admin', fn ($q) => $q->where('starts_at', '<=', now())->where('ends_at', '>', now()))->orderByDesc('id')->paginate(25)->through(function ($row) {
            $safe = $this->safe($row);
            $used = DB::table('loyalty_redemptions')->where('reward_id', $row->id)->whereIn('status', ['pending', 'fulfilled'])->count();

            return $safe + ['available_stock' => max(0, (int) $row->stock - $used), 'fulfillment' => 'MANUAL_NON_MONETARY', 'cash_value' => false];
        });
    }

    public function publishReward(Request $r, LoyaltyService $service)
    {
        $this->admin($r);
        $v = $r->validate(['name' => 'required|string|max:200', 'name_ar' => 'required|string|max:200', 'description' => 'required|string|max:3000', 'description_ar' => 'required|string|max:3000', 'currency' => ['required', 'regex:/^[A-Z]{3}$/'], 'points_cost' => 'required|integer|min:1|max:10000000', 'stock' => 'required|integer|min:1|max:1000000', 'per_user_limit' => 'required|integer|min:1|max:100', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'idempotency_key' => 'required|string|max:100']);
        $key = $v['idempotency_key'];
        unset($v['idempotency_key']);
        foreach (['points_cost', 'stock', 'per_user_limit'] as $field) {
            $v[$field] = (int) $v[$field];
        }
        foreach (['starts_at', 'ends_at'] as $field) {
            $v[$field] = Carbon::parse($v[$field])->utc()->format('Y-m-d H:i:s');
        }

        return response()->json(['reward' => $this->safe($service->publish('loyalty_rewards', $r->user(), $v, $key))], 201);
    }

    public function earn(Request $r, int $invoice, LoyaltyService $service)
    {
        $row = DB::table('finance_invoices')->find($invoice);
        abort_unless($row, 404);
        $this->owner($r, (int) $row->user_id);

        return response()->json(['award' => $service->earn($invoice, $r->user()->id), 'account' => $service->account($row->user_id)], 201);
    }

    public function reconcile(Request $r, LoyaltyService $service)
    {
        return response()->json(['account' => $service->reconcile($r->user()->id, $r->user()->id)]);
    }

    public function redeem(Request $r, int $reward, LoyaltyService $service)
    {
        $v = $r->validate(['idempotency_key' => 'required|string|max:100']);

        return response()->json(['redemption' => $this->safe($service->redeem($r->user()->id, $reward, $r->user()->id, $v['idempotency_key'])), 'account' => $service->account($r->user()->id)], 201);
    }

    public function redemptions(Request $r)
    {
        return DB::table('loyalty_redemptions')->when($r->user()->role !== 'admin', fn ($q) => $q->where('user_id', $r->user()->id))->orderByDesc('id')->paginate(25)->through(fn ($row) => $this->safe($row));
    }

    public function partnerRewardOffers(Request $r, PartnerRewardGovernance $governance, LoyaltyService $service)
    {
        abort_unless($r->user()->role === 'customer' && $r->user()->email_verified_at !== null, 403);
        $values = $r->validate(['page' => 'sometimes|integer|between:1,100000']);
        $user = (int) $r->user()->id;

        return response()->json($governance->sandboxOffers($user, $service->account($user), (int) ($values['page'] ?? 1)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function claimPartnerReward(Request $r, int $review, LoyaltyService $service, PartnerRewardGovernance $governance)
    {
        abort_unless($r->user()->role === 'customer', 403);
        $input = $r->validate(['idempotency_key' => 'required|string|max:100']);
        $claim = $service->claimPartnerReward($r->user()->id, $review, $input['idempotency_key']);

        return response()->json(['claim' => $this->partnerRewardReceipt($claim) + $governance->sandboxEvidence()], 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function partnerRewardClaims(Request $r, PartnerRewardGovernance $governance)
    {
        abort_unless($r->user()->role === 'customer', 403);
        $values = $r->validate(['page' => 'sometimes|integer|between:1,100000']);
        $claims = $this->partnerRewardReceiptQuery()
            ->where('claim.user_id', $r->user()->id)->orderByDesc('claim.id')
            ->paginate(25, ['*'], 'page', (int) ($values['page'] ?? 1))
            ->through(fn ($claim) => $this->validatedPartnerRewardReceipt($claim) + $governance->sandboxEvidence());

        return response()->json($claims)->header('Cache-Control', 'private, no-store');
    }

    public function findPartnerRewardReceipt(Request $r, LoyaltyService $service, PartnerRewardGovernance $governance)
    {
        abort_unless($r->user()->role === 'customer', 403);
        $input = $r->validate(['idempotency_key' => 'required|string|max:100']);
        $claim = $service->findPartnerRewardClaim($r->user()->id, $input['idempotency_key']);

        return response()->json(['claim' => $claim ? $this->partnerRewardReceipt($claim) + $governance->sandboxEvidence() : null])
            ->header('Cache-Control', 'private, no-store');
    }

    public function cancelPartnerRewardIntent(Request $r, LoyaltyService $service, PartnerRewardGovernance $governance)
    {
        abort_unless($r->user()->role === 'customer', 403);
        $input = $r->validate([
            'review_id' => 'required|integer|between:1,9007199254740991',
            'idempotency_key' => 'required|string|max:100',
        ]);
        $review = (int) $input['review_id'];
        $result = $service->cancelPartnerRewardIntent($r->user()->id, $review, $input['idempotency_key']);
        $request = hash('sha256', 'partner-reward-claim:'.$r->user()->id.':'.$input['idempotency_key']);

        return response()->json([
            'state' => $result['state'],
            'review_id' => $review,
            'request_binding' => hash('sha256', 'partner-reward-cancel-v1:'.$review.':'.$request),
            'claim' => $result['claim'] ? $this->partnerRewardReceipt($result['claim']) + $governance->sandboxEvidence() : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    private function partnerRewardReceiptQuery()
    {
        return DB::table('partner_reward_claims as claim')
            ->leftJoin('loyalty_redemptions as redemption', 'redemption.id', '=', 'claim.redemption_id')
            ->leftJoin('partner_reward_reviews as review', 'review.id', '=', 'claim.review_id')
            ->leftJoin('partner_reward_review_events as approval', 'approval.id', '=', 'claim.approval_event_id')
            ->select([
                'claim.id', 'claim.review_id', 'claim.redemption_id', 'claim.created_at', 'claim.user_id',
                'redemption.user_id as redemption_user_id', 'redemption.reward_id', 'redemption.points_cost',
                'redemption.status', 'review.reward_id as reviewed_reward_id',
                'approval.review_id as approval_review_id', 'approval.decision as approval_decision',
            ]);
    }

    private function validatedPartnerRewardReceipt(object $claim): array
    {
        // Historical approval remains valid evidence after revocation, but a
        // contradictory linkage must never disclose another member's receipt.
        abort_unless($claim->redemption_user_id !== null
            && (int) $claim->user_id === (int) $claim->redemption_user_id
            && $claim->reviewed_reward_id !== null
            && (int) $claim->reviewed_reward_id === (int) $claim->reward_id
            && $claim->points_cost !== null && (string) $claim->points_cost === '0'
            && (int) $claim->approval_review_id === (int) $claim->review_id
            && $claim->approval_decision === 'approve'
            && in_array($claim->status, ['pending', 'fulfilled', 'rejected'], true),
            409, 'Partner claim evidence is inconsistent.');

        return ['id' => (int) $claim->id, 'review_id' => (int) $claim->review_id,
            'redemption_id' => (int) $claim->redemption_id, 'created_at' => $claim->created_at,
            'status' => $claim->status];
    }

    private function partnerRewardReceipt(object $claim): array
    {
        $source = $this->partnerRewardReceiptQuery()->where('claim.id', $claim->id)
            ->where('claim.user_id', $claim->user_id)->first();
        abort_unless($source, 409, 'Partner claim evidence is inconsistent.');

        return $this->validatedPartnerRewardReceipt($source);
    }

    public function show(Request $r, int $redemption)
    {
        $row = DB::table('loyalty_redemptions')->find($redemption);
        abort_unless($row, 404);
        $this->owner($r, (int) $row->user_id);

        return response()->json(['redemption' => $this->safe($row), 'reward' => $this->safe(DB::table('loyalty_rewards')->find($row->reward_id)), 'events' => DB::table('loyalty_redemption_events')->where('redemption_id', $redemption)->orderBy('id')->get()]);
    }

    public function transition(Request $r, int $redemption, string $action, LoyaltyService $service)
    {
        $this->admin($r);
        $v = $r->validate(['reason' => 'required|string|min:5|max:255']);

        return response()->json(['redemption' => $this->safe($service->transition($redemption, $r->user(), $action, $v['reason']))]);
    }
}
