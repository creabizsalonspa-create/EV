<?php

namespace App\Http\Controllers;

use App\Services\Commercial\Loyalty\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LoyaltyController extends Controller
{
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

        return response()->json(['policy' => $this->safe($service->publish('loyalty_policies', $r->user()->id, $v, $key))], 201);
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

        return response()->json(['reward' => $this->safe($service->publish('loyalty_rewards', $r->user()->id, $v, $key))], 201);
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

        return response()->json(['redemption' => $this->safe($service->transition($redemption, $r->user()->id, $action, $v['reason']))]);
    }
}
