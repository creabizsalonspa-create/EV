<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Commercial\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    /**
     * Call inside a DB transaction to pin the current account while reading
     * private billing terms or making an Admin catalog change. Session models
     * are snapshots and must not authorize financial activity after revocation.
     */
    private function currentActor(Request $r): User
    {
        $snapshot = $r->user();
        abort_unless($snapshot instanceof User, 401);
        $current = User::whereKey($snapshot->id)->lockForUpdate()->first();
        abort_unless($current && $current->account_status === 'active'
            && $current->role === $snapshot->role
            && (int) $current->auth_epoch === (int) $snapshot->auth_epoch
            && hash_equals((string) $current->password, (string) $snapshot->password),
            409, 'Account authorization changed; sign in again.');

        return $current;
    }

    public function plans(Request $r)
    {
        return DB::transaction(function () use ($r) {
            $current = $this->currentActor($r);
            $v = $r->validate(['page' => 'sometimes|integer|between:1,100000']);
            $q = DB::table('commercial_plan_versions as p')
                ->join('commercial_plan_availability as a', 'a.plan_id', '=', 'p.id')
                ->select('p.*', 'a.available');
            if ($current->role !== 'admin') {
                $q->where('p.audience', $current->role)->where('a.available', true);
            }

            $page = $q->orderByDesc('p.id')
                ->paginate(25, ['*'], 'page', (int) ($v['page'] ?? 1))
                ->through(function ($p) {
                    $p->available = (bool) $p->available;
                    $p->entitlements = json_decode($p->entitlements, true, 512, JSON_THROW_ON_ERROR);

                    return $p;
                });

            return response()->json($page)
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function publish(Request $r, SubscriptionService $s)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/'], 'name' => 'required|string|max:120', 'audience' => ['required', Rule::in(['customer', 'vendor'])], 'currency' => ['required', 'regex:/^[A-Z]{3}$/'], 'minor_units' => 'required|integer|between:0,3', 'price_minor' => 'required|integer|between:0,2000000000', 'tax_bps' => 'required|integer|between:0,10000', 'interval' => ['required', Rule::in(['month', 'year'])], 'entitlements' => 'present|array|max:32']);
        abort_unless(DB::table('regions')->where('currency', $v['currency'])->where('minor_units', $v['minor_units'])->exists(), 422, 'Configure currency precision before publishing a plan.');
        foreach ($v['entitlements'] as $key => $value) {
            abort_unless(is_string($key) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) && (is_bool($value) || (is_int($value) && $value >= 0 && $value <= 1000000)), 422, 'Entitlements must use named boolean features or integer limits.');
        }
        $v['entitlements'] = json_encode($v['entitlements'], JSON_THROW_ON_ERROR);
        foreach (['minor_units', 'price_minor', 'tax_bps'] as $field) {
            $v[$field] = (int) $v[$field];
        }

        return DB::transaction(function () use ($r, $s, $v) {
            $current = $this->currentActor($r);
            abort_unless($current->role === 'admin', 403);

            return response()->json($s->publish($current, $v), 201);
        }, 3);
    }

    public function availability(Request $r, int $plan)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['available' => 'required|boolean']);

        return DB::transaction(function () use ($r, $plan, $v) {
            $current = $this->currentActor($r);
            abort_unless($current->role === 'admin', 403);
            abort_unless(DB::table('commercial_plan_availability')->where('plan_id', $plan)
                ->lockForUpdate()->first(), 404);
            DB::table('commercial_plan_availability')->where('plan_id', $plan)
                ->update(['available' => $v['available']]);
            DB::table('audit_events')->insert(['actor_id' => $current->id,
                'action' => 'finance.subscription_plan_availability',
                'subject' => (string) $plan, 'created_at' => now()]);

            return response()->json(['plan_id' => $plan, 'available' => (bool) $v['available']]);
        }, 3);
    }

    public function index(Request $r)
    {
        return DB::transaction(function () use ($r) {
            $current = $this->currentActor($r);
            $v = $r->validate(['page' => 'sometimes|integer|between:1,100000']);

            $page = DB::table('commercial_subscriptions')
                ->when($current->role !== 'admin', fn ($q) => $q->where('user_id', $current->id))
                ->orderByDesc('id')
                ->paginate(25, ['*'], 'page', (int) ($v['page'] ?? 1))
                ->through(function ($s) {
                    $s->cancel_at_period_end = (bool) $s->cancel_at_period_end;

                    return $s;
                });

            return response()->json($page)
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function subscribe(Request $r, SubscriptionService $s)
    {
        $v = $r->validate(['plan_id' => 'required|integer|min:1', 'idempotency_key' => 'required|string|max:100']);

        return DB::transaction(function () use ($r, $s, $v) {
            $current = $this->currentActor($r);

            return response()->json($s->subscribe($current, (int) $v['plan_id'], $v['idempotency_key']), 201)
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function show(Request $r, int $subscription, SubscriptionService $s)
    {
        return DB::transaction(function () use ($r, $subscription, $s) {
            $current = $this->currentActor($r);
            $s->access($current, $subscription, true);

            return response()->json($s->summary($subscription))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function changePreview(Request $r, int $subscription, SubscriptionService $s)
    {
        // An authenticated session snapshot can outlive account suspension,
        // password rotation or role/epoch revocation. Hold the persisted row
        // through quote calculation so a former Admin cannot preview another
        // company's private terms after losing cross-account authority.
        return DB::transaction(function () use ($r, $subscription, $s) {
            $current = $this->currentActor($r);

            // Authorize the CURRENT owner/Admin before resolving target terms.
            $s->access($current, $subscription, true);
            $v = $r->validate(['plan_id' => 'required|integer|between:1,999999999999999999']);

            return response()->json($s->previewChange($current, $subscription, (int) $v['plan_id']))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function change(Request $r, int $subscription, SubscriptionService $s)
    {
        return DB::transaction(function () use ($r, $subscription, $s) {
            $current = $this->currentActor($r);
            // Never disclose a private subscription's existence through
            // validation errors before current-account owner authorization.
            $s->access($current, $subscription);
            $v = $r->validate(['action' => ['required', Rule::in(['change_plan', 'cancel_at_period_end', 'cancel_now', 'resume'])], 'plan_id' => 'required_if:action,change_plan|nullable|integer|min:1', 'idempotency_key' => 'required|string|max:100']);

            return response()->json($s->change($current, $subscription, $v['action'],
                isset($v['plan_id']) ? (int) $v['plan_id'] : null, $v['idempotency_key']))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function mockPayment(Request $r, int $subscription, SubscriptionService $s)
    {
        return DB::transaction(function () use ($r, $subscription, $s) {
            $current = $this->currentActor($r);
            // Authorize private subscription before revealing form validity.
            $s->access($current, $subscription);
            $v = $r->validate(['invoice_id' => 'required|integer|min:1', 'outcome' => ['required', Rule::in(['succeeded', 'failed'])], 'idempotency_key' => 'required|string|max:100']);

            return response()->json($s->mockPay($current, $subscription,
                (int) $v['invoice_id'], $v['outcome'], $v['idempotency_key']))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function renew(Request $r, int $subscription, SubscriptionService $s)
    {
        return DB::transaction(function () use ($r, $subscription, $s) {
            $current = $this->currentActor($r);
            $s->access($current, $subscription);

            $v = $r->validate(['expected_cycle_number' => 'sometimes|required|integer|between:1,9007199254740991']);

            return response()->json($s->renew($subscription,
                isset($v['expected_cycle_number']) ? (int) $v['expected_cycle_number'] : null))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function history(Request $r, int $subscription, SubscriptionService $s)
    {
        return DB::transaction(function () use ($r, $subscription, $s) {
            $current = $this->currentActor($r);
            $s->access($current, $subscription);
            $v = $r->validate(['page' => 'sometimes|integer|between:1,100000']);

            $page = DB::table('commercial_subscription_events')
                ->where('subscription_id', $subscription)
                ->select(['id', 'kind', 'data', 'created_at'])
                ->orderByDesc('id')
                ->paginate(25, ['*'], 'page', (int) ($v['page'] ?? 1))
                ->through(function ($e) {
                    $e->data = json_decode($e->data, true, 512, JSON_THROW_ON_ERROR);

                    return $e;
                });

            return response()->json($page)
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }
}
