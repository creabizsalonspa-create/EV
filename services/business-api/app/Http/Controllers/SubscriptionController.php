<?php

namespace App\Http\Controllers;

use App\Services\Commercial\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function plans(Request $r)
    {
        $q = DB::table('commercial_plan_versions as p')->join('commercial_plan_availability as a', 'a.plan_id', '=', 'p.id')->select('p.*', 'a.available');
        if ($r->user()->role !== 'admin') {
            $q->where('p.audience', $r->user()->role)->where('a.available', true);
        }

        return $q->orderByDesc('p.id')->paginate(25)->through(function ($p) {
            $p->available = (bool) $p->available;
            $p->entitlements = json_decode($p->entitlements, true, 512, JSON_THROW_ON_ERROR);

            return $p;
        });
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

        return response()->json($s->publish($r->user(), $v), 201);
    }

    public function availability(Request $r, int $plan)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['available' => 'required|boolean']);
        abort_unless(DB::table('commercial_plan_availability')->where('plan_id', $plan)->exists(), 404);
        DB::table('commercial_plan_availability')->where('plan_id', $plan)->update(['available' => $v['available']]);
        DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'finance.subscription_plan_availability', 'subject' => (string) $plan, 'created_at' => now()]);

        return response()->json(['plan_id' => $plan, 'available' => (bool) $v['available']]);
    }

    public function index(Request $r)
    {
        return DB::table('commercial_subscriptions')->when($r->user()->role !== 'admin', fn ($q) => $q->where('user_id', $r->user()->id))->orderByDesc('id')->paginate(25)->through(function ($s) {
            $s->cancel_at_period_end = (bool) $s->cancel_at_period_end;

            return $s;
        });
    }

    public function subscribe(Request $r, SubscriptionService $s)
    {
        $v = $r->validate(['plan_id' => 'required|integer|min:1', 'idempotency_key' => 'required|string|max:100']);

        return response()->json($s->subscribe($r->user(), (int) $v['plan_id'], $v['idempotency_key']), 201);
    }

    public function show(Request $r, int $subscription, SubscriptionService $s)
    {
        $s->access($r->user(), $subscription);

        return response()->json($s->summary($subscription));
    }

    public function change(Request $r, int $subscription, SubscriptionService $s)
    {
        $s->access($r->user(), $subscription);
        $v = $r->validate(['action' => ['required', Rule::in(['change_plan', 'cancel_at_period_end', 'cancel_now', 'resume'])], 'plan_id' => 'required_if:action,change_plan|nullable|integer|min:1', 'idempotency_key' => 'required|string|max:100']);

        return response()->json($s->change($r->user(), $subscription, $v['action'], isset($v['plan_id']) ? (int) $v['plan_id'] : null, $v['idempotency_key']));
    }

    public function mockPayment(Request $r, int $subscription, SubscriptionService $s)
    {
        $s->access($r->user(), $subscription);
        $v = $r->validate(['invoice_id' => 'required|integer|min:1', 'outcome' => ['required', Rule::in(['succeeded', 'failed'])], 'idempotency_key' => 'required|string|max:100']);

        return response()->json($s->mockPay($r->user(), $subscription, (int) $v['invoice_id'], $v['outcome'], $v['idempotency_key']));
    }

    public function renew(Request $r, int $subscription, SubscriptionService $s)
    {
        $s->access($r->user(), $subscription);

        return response()->json($s->renew($subscription));
    }

    public function history(Request $r, int $subscription, SubscriptionService $s)
    {
        $s->access($r->user(), $subscription);

        return DB::table('commercial_subscription_events')->where('subscription_id', $subscription)->select(['id', 'kind', 'data', 'created_at'])->orderByDesc('id')->paginate(25)->through(function ($e) {
            $e->data = json_decode($e->data, true, 512, JSON_THROW_ON_ERROR);

            return $e;
        });
    }
}
