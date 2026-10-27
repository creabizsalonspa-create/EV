<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Commercial\SettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SettlementController extends Controller
{
    private function vendor(Request $r, int $vendor): void
    {
        abort_unless($r->user()->role === 'admin' || ($r->user()->role === 'vendor' && $r->user()->id === $vendor), 404);
        abort_unless(User::whereKey($vendor)->where('role', 'vendor')->exists(), 404);
    }

    private function admin(Request $r): void
    {
        abort_unless($r->user()->role === 'admin', 403);
    }

    public function index(Request $r)
    {
        abort_unless(in_array($r->user()->role, ['admin', 'vendor'], true), 403);

        return DB::table('commercial_settlements')->when($r->user()->role !== 'admin', fn ($q) => $q->where('vendor_id', $r->user()->id))->orderByDesc('id')->paginate(25);
    }

    public function statement(Request $r, int $vendor, SettlementService $service)
    {
        $this->vendor($r, $vendor);
        $v = $r->validate(['currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/']]);
        $schedule = DB::table('commercial_settlement_schedules')->where('vendor_id', $vendor)->where('currency', $v['currency'])->first();
        $balance = $service->balances($vendor, $v['currency'], (int) ($schedule->hold_days ?? 0));

        return response()->json($balance + ['schedule' => $schedule, 'eligible_to_request' => $schedule && $schedule->enabled && $schedule->next_due_at <= now()->toDateTimeString() && $balance['available_minor'] > 0 && $balance['available_minor'] >= (int) $schedule->minimum_minor,
            'adjustments' => DB::table('commercial_adjustments as a')->leftJoin('commercial_adjustment_approvals as p', 'p.adjustment_id', '=', 'a.id')->where('a.vendor_id', $vendor)->where('a.currency', $v['currency'])->select('a.id', 'a.amount_minor', 'a.reason', 'a.created_at', 'p.approved_by')->orderByDesc('a.id')->limit(100)->get()]);
    }

    public function schedule(Request $r, int $vendor)
    {
        $this->admin($r);
        $this->vendor($r, $vendor);
        $v = $r->validate(['currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'], 'hold_days' => 'required|integer|between:0,365', 'interval_days' => 'required|integer|between:1,365', 'minimum_minor' => 'required|integer|between:1,2000000000', 'next_due_at' => 'required|date', 'enabled' => 'required|boolean']);
        abort_unless(DB::table('regions')->where('currency', $v['currency'])->exists(), 422, 'Currency must be configured.');

        return DB::transaction(function () use ($r, $vendor, $v) {
            User::whereKey($vendor)->lockForUpdate()->firstOrFail();
            DB::table('commercial_settlement_schedules')->updateOrInsert(['vendor_id' => $vendor, 'currency' => $v['currency']], ['hold_days' => (int) $v['hold_days'], 'interval_days' => (int) $v['interval_days'], 'minimum_minor' => (int) $v['minimum_minor'], 'next_due_at' => Carbon::parse($v['next_due_at'])->utc(), 'enabled' => $v['enabled'], 'configured_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'finance.settlement.schedule_configured', 'subject' => (string) $vendor, 'created_at' => now()]);

            return response()->json(DB::table('commercial_settlement_schedules')->where('vendor_id', $vendor)->where('currency', $v['currency'])->first());
        }, 3);
    }

    public function draft(Request $r, int $vendor, SettlementService $service)
    {
        $this->vendor($r, $vendor);
        $v = $r->validate(['currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'], 'idempotency_key' => 'required|string|max:100']);

        return response()->json($service->draft($vendor, $v['currency'], $r->user()->id, $v['idempotency_key']), 201);
    }

    public function show(Request $r, int $settlement)
    {
        $s = DB::table('commercial_settlements')->find($settlement);
        abort_unless($s, 404);
        $this->vendor($r, (int) $s->vendor_id);
        $lines = DB::table('commercial_settlement_lines')->where('settlement_id', $settlement)->orderBy('id')->get();

        return response()->json(['settlement' => $s, 'lines' => $lines, 'events' => DB::table('commercial_settlement_events')->where('settlement_id', $settlement)->orderBy('id')->get(), 'reconciled' => (int) $s->amount_minor === (int) $lines->sum('amount_minor'), 'real_payout_enabled' => false]);
    }

    public function transition(Request $r, int $settlement, string $action, SettlementService $service)
    {
        $this->admin($r);

        return response()->json($service->transition($settlement, $r->user()->id, $action));
    }

    public function adjustment(Request $r, int $vendor)
    {
        $this->admin($r);
        $this->vendor($r, $vendor);
        $v = $r->validate(['currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'], 'amount_minor' => 'required|integer|between:-2000000000,2000000000|not_in:0', 'reason' => 'required|string|max:500', 'idempotency_key' => 'required|string|max:100']);
        abort_unless(DB::table('regions')->where('currency', $v['currency'])->exists(), 422, 'Currency must be configured.');

        return DB::transaction(function () use ($r, $vendor, $v) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            User::whereKey($vendor)->lockForUpdate()->firstOrFail();
            $key = hash('sha256', $r->user()->id.':'.$v['idempotency_key']);
            $old = DB::table('commercial_adjustments')->where('request_key', $key)->first();
            if ($old) {
                abort_unless((int) $old->vendor_id === $vendor && $old->currency === $v['currency'] && (int) $old->amount_minor === (int) $v['amount_minor'] && $old->reason === $v['reason'], 409, 'Adjustment key payload mismatch.');

                return response()->json($old, 201);
            }
            $id = DB::table('commercial_adjustments')->insertGetId(['vendor_id' => $vendor, 'currency' => $v['currency'], 'minor_units' => app(SettlementService::class)->currencyPrecision($vendor, $v['currency']), 'amount_minor' => (int) $v['amount_minor'], 'reason' => $v['reason'], 'request_key' => $key, 'created_by' => $r->user()->id, 'created_at' => now()]);
            DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'finance.settlement.adjustment_requested', 'subject' => (string) $id, 'created_at' => now()]);

            return response()->json(DB::table('commercial_adjustments')->find($id), 201);
        }, 3);
    }

    public function approveAdjustment(Request $r, int $adjustment)
    {
        $this->admin($r);

        return DB::transaction(function () use ($r, $adjustment) {
            $a = DB::table('commercial_adjustments')->find($adjustment);
            abort_unless($a, 404);
            User::whereKey($a->vendor_id)->lockForUpdate()->firstOrFail();
            abort_if((int) $a->created_by === $r->user()->id, 403, 'A different administrator must approve an adjustment.');
            if (! DB::table('commercial_adjustment_approvals')->where('adjustment_id', $adjustment)->exists()) {
                DB::table('commercial_adjustment_approvals')->insert(['adjustment_id' => $adjustment, 'approved_by' => $r->user()->id, 'created_at' => now()]);
                $amount = abs((int) $a->amount_minor);
                $positive = (int) $a->amount_minor > 0;
                DB::table('commercial_adjustment_entries')->insert([
                    ['adjustment_id' => $adjustment, 'account' => 'vendor_payable:'.$a->vendor_id, 'debit_minor' => $positive ? 0 : $amount, 'credit_minor' => $positive ? $amount : 0, 'created_at' => now()],
                    ['adjustment_id' => $adjustment, 'account' => 'platform_adjustment', 'debit_minor' => $positive ? $amount : 0, 'credit_minor' => $positive ? 0 : $amount, 'created_at' => now()],
                ]);

                DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => 'finance.settlement.adjustment_approved', 'subject' => (string) $adjustment, 'created_at' => now()]);
            }

            return response()->json(DB::table('commercial_adjustment_approvals')->where('adjustment_id', $adjustment)->first());
        }, 3);
    }
}
