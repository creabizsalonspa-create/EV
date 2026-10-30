<?php

namespace App\Services\Commercial;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SettlementService
{
    private const RESERVED = ['pending', 'approved', 'simulated_paid', 'provider_paid'];

    private function enabled(): void
    {
        abort_if(app()->environment('production'), 503, 'Settlement simulation is disabled in production; no payout provider is connected.');
    }

    public function currencyPrecision(int $vendor, string $currency): int
    {
        $configured = DB::table('regions')->where('currency', $currency)->pluck('minor_units');
        $historical = DB::table('finance_invoices')->where('vendor_id', $vendor)->where('currency', $currency)->pluck('minor_units')
            ->merge(DB::table('commercial_adjustments')->where('vendor_id', $vendor)->where('currency', $currency)->pluck('minor_units'))
            ->merge(DB::table('commercial_settlements')->where('vendor_id', $vendor)->where('currency', $currency)->pluck('minor_units'));
        $units = $configured->merge($historical)->map(fn ($v) => (int) $v)->unique();
        abort_unless($configured->isNotEmpty() && $units->count() === 1 && $units->first() >= 0 && $units->first() <= 3, 409, 'Currency precision requires reconciliation.');

        return $units->first();
    }

    public function balances(int $vendor, string $currency, int $holdDays = 0, bool $lock = false): array
    {
        $query = DB::table('finance_invoices')->where('vendor_id', $vendor)->where('currency', $currency)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $lines = [];
        $gross = 0;
        $commission = 0;
        $tax = 0;
        $refunded = 0;
        foreach ($query->get() as $invoice) {
            $paid = (int) DB::table('finance_payments')->where('invoice_id', $invoice->id)->sum('amount_minor');
            $refunds = DB::table('finance_refunds')->where('invoice_id', $invoice->id);
            $refund = (int) (clone $refunds)->sum('amount_minor');
            $vendorRefund = (int) (clone $refunds)->sum('vendor_minor');
            $reserved = (int) DB::table('commercial_settlement_lines as l')->join('commercial_settlements as s', 's.id', '=', 'l.settlement_id')->where('l.invoice_id', $invoice->id)->whereIn('s.state', self::RESERVED)->sum('l.amount_minor');
            // Positive earnings require full capture and a completed holding period.
            // Refund debt after a prior settlement is carried forward immediately.
            $mature = Carbon::parse($invoice->created_at)->addDays($holdDays)->lte(now());
            $earned = $paid === (int) $invoice->total_minor && ($mature || $reserved !== 0) ? (int) $invoice->vendor_minor - $vendorRefund : 0;
            if ($this->held((int) $invoice->id)) {
                $completed = (int) DB::table('commercial_settlement_lines as l')->join('commercial_settlements as s', 's.id', '=', 'l.settlement_id')->where('l.invoice_id', $invoice->id)->whereIn('s.state', ['simulated_paid', 'provider_paid'])->sum('l.amount_minor');
                $earned = min($earned, $completed);
            }
            $available = $earned - $reserved;
            if ($available !== 0) {
                $lines[] = ['invoice_id' => (int) $invoice->id, 'adjustment_id' => null, 'amount_minor' => $available];
            }
            $gross += (int) $invoice->subtotal_minor;
            $commission += (int) $invoice->commission_minor;
            $tax += (int) $invoice->tax_minor;
            $refunded += $refund;
        }
        $adjustments = DB::table('commercial_adjustments as a')->join('commercial_adjustment_approvals as p', 'p.adjustment_id', '=', 'a.id')->where('a.vendor_id', $vendor)->where('a.currency', $currency)->select('a.*')->orderBy('a.id')->get();
        foreach ($adjustments as $adjustment) {
            $reserved = (int) DB::table('commercial_settlement_lines as l')->join('commercial_settlements as s', 's.id', '=', 'l.settlement_id')->where('l.adjustment_id', $adjustment->id)->whereIn('s.state', self::RESERVED)->sum('l.amount_minor');
            $available = (int) $adjustment->amount_minor - $reserved;
            if ($available !== 0) {
                $lines[] = ['invoice_id' => null, 'adjustment_id' => (int) $adjustment->id, 'amount_minor' => $available];
            }
        }

        return ['vendor_id' => $vendor, 'currency' => $currency, 'minor_units' => $this->currencyPrecision($vendor, $currency), 'gross_invoiced_minor' => $gross, 'commission_invoiced_minor' => $commission, 'tax_invoiced_minor' => $tax, 'refunded_minor' => $refunded, 'available_minor' => array_sum(array_column($lines, 'amount_minor')), 'lines' => $lines, 'evidence' => 'SIMULATED', 'real_payout_enabled' => false];
    }

    private function held(int $invoice): bool
    {
        return DB::table('finance_refund_requests')->where('invoice_id', $invoice)->where('status', 'pending')->exists()
            || DB::table('gateway_cases')->where('invoice_id', $invoice)->where('status', 'open')->exists();
    }

    public function draft(int $vendor, string $currency, int $actor, string $key): object
    {
        $this->enabled();

        return DB::transaction(function () use ($vendor, $currency, $actor, $key) {
            User::whereKey($actor)->lockForUpdate()->firstOrFail();
            User::whereKey($vendor)->where('role', 'vendor')->lockForUpdate()->firstOrFail();
            $requestKey = hash('sha256', $actor.':'.$key);
            if ($old = DB::table('commercial_settlements')->where('request_key', $requestKey)->first()) {
                abort_unless((int) $old->vendor_id === $vendor && $old->currency === $currency, 409, 'Settlement key payload mismatch.');

                return $old;
            }
            $schedule = DB::table('commercial_settlement_schedules')->where('vendor_id', $vendor)->where('currency', $currency)->lockForUpdate()->first();
            abort_unless($schedule && $schedule->enabled && $schedule->next_due_at <= now()->toDateTimeString(), 409, 'Settlement schedule is disabled or not due.');
            $balance = $this->balances($vendor, $currency, (int) $schedule->hold_days, true);
            abort_unless($balance['available_minor'] >= (int) $schedule->minimum_minor && $balance['available_minor'] > 0, 409, 'No eligible payable balance.');
            $id = DB::table('commercial_settlements')->insertGetId(['number' => 'SIM-SET-'.Str::ulid(), 'vendor_id' => $vendor, 'currency' => $currency, 'minor_units' => $balance['minor_units'], 'amount_minor' => $balance['available_minor'], 'state' => 'pending', 'request_key' => $requestKey, 'created_by' => $actor, 'evidence' => 'SIMULATED', 'created_at' => now(), 'updated_at' => now()]);
            foreach ($balance['lines'] as $line) {
                DB::table('commercial_settlement_lines')->insert($line + ['settlement_id' => $id, 'created_at' => now()]);
            }
            $this->event($id, $actor, 'drafted');

            return DB::table('commercial_settlements')->find($id);
        }, 3);
    }

    public function transition(int $id, int $actor, string $action): object
    {
        $this->enabled();

        return DB::transaction(function () use ($id, $actor, $action) {
            $hint = DB::table('commercial_settlements')->find($id);
            abort_unless($hint, 404);
            User::whereKey($hint->vendor_id)->lockForUpdate()->firstOrFail();
            $s = DB::table('commercial_settlements')->where('id', $id)->lockForUpdate()->first();
            // Durable provider claims retain this reservation even after a timeout.
            // Cancel only a provably unsent/failed payout through its own workflow.
            if (in_array($action, ['reject', 'simulate-payout'], true)) {
                abort_if(DB::table('commercial_payouts')->where('settlement_id', $id)->where('status', '!=', 'cancelled')->exists(), 409, 'A payout claim owns this settlement; reconcile or safely cancel it before a legacy transition.');
            }
            $target = ['approve' => 'approved', 'reject' => 'rejected', 'simulate-payout' => 'simulated_paid'][$action] ?? null;
            abort_unless($target, 422);
            if ($s->state === $target) {
                return $s;
            }
            abort_unless($action === 'reject' ? in_array($s->state, ['pending', 'approved'], true) : $s->state === ($action === 'simulate-payout' ? 'approved' : 'pending'), 409, 'Invalid settlement transition.');
            if ($action !== 'reject') {
                // Lock invoices in the same order as draft; payment/refund writers
                // serialize on these exact invoice rows. Every held claim must remain covered.
                $schedule = DB::table('commercial_settlement_schedules')->where('vendor_id', $s->vendor_id)->where('currency', $s->currency)->first();
                abort_unless($schedule && $schedule->enabled, 409, 'Settlement schedule is disabled.');
                $balance = $this->balances((int) $s->vendor_id, $s->currency, (int) $schedule->hold_days, true);
                abort_unless($balance['available_minor'] >= 0, 409, 'Refund or adjustment changed the payable balance; reject and rebuild.');
                foreach (DB::table('commercial_settlement_lines')->where('settlement_id', $id)->where('amount_minor', '>', 0)->whereNotNull('invoice_id')->pluck('invoice_id') as $invoiceId) {
                    abort_if($this->held((int) $invoiceId), 409, 'A refund request or dispute holds this settlement.');
                }

            }
            $values = ['state' => $target, 'updated_at' => now()];
            if ($action === 'approve') {
                $values += ['approved_by' => $actor, 'approved_at' => now()];
            }
            if ($action === 'simulate-payout') {
                $values['executed_at'] = now();
                DB::table('commercial_settlement_schedules')->where('vendor_id', $s->vendor_id)->where('currency', $s->currency)->update(['next_due_at' => now()->addDays((int) $schedule->interval_days), 'updated_at' => now()]);
            }
            DB::table('commercial_settlements')->where('id', $id)->update($values);
            $this->event($id, $actor, $action);

            return DB::table('commercial_settlements')->find($id);
        }, 3);
    }

    private function event(int $id, int $actor, string $action): void
    {
        DB::table('commercial_settlement_events')->insert(['settlement_id' => $id, 'actor_id' => $actor, 'action' => $action, 'created_at' => now()]);
        DB::table('audit_events')->insert(['actor_id' => $actor, 'action' => 'finance.settlement.'.$action, 'subject' => (string) $id, 'created_at' => now()]);
    }
}
