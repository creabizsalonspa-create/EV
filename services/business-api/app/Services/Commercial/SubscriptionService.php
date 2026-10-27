<?php

namespace App\Services\Commercial;

use App\Models\User;
use App\Services\Finance\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionService
{
    public function plan(int $id): object
    {
        $plan = DB::table('commercial_plan_versions')->find($id);
        abort_unless($plan, 404);
        $plan->entitlements = json_decode($plan->entitlements, true, 512, JSON_THROW_ON_ERROR);

        return $plan;
    }

    public function publish(User $actor, array $v): object
    {
        abort_unless($actor->role === 'admin', 403);

        return DB::transaction(function () use ($actor, $v) {
            // Serialize catalog version allocation across all administrators and plan codes.
            DB::table('regions')->orderBy('country')->lockForUpdate()->firstOrFail();
            $prior = DB::table('commercial_plan_versions')->where('code', $v['code'])->orderByDesc('version')->first();
            if ($prior) {
                abort_unless($prior->audience === $v['audience'] && $prior->currency === $v['currency'] && (int) $prior->minor_units === (int) $v['minor_units'], 409, 'A plan code cannot change audience, currency or precision.');
            }
            $id = DB::table('commercial_plan_versions')->insertGetId($v + ['version' => ($prior->version ?? 0) + 1, 'created_by' => $actor->id, 'created_at' => now()]);
            DB::table('commercial_plan_availability')->insert(['plan_id' => $id, 'available' => true]);

            return $this->plan($id);
        }, 3);
    }

    private function key(int $actor, string $key): string
    {
        return 'subscription:'.$actor.':'.$key;
    }

    private function hash(string $operation, array $v): string
    {
        return hash('sha256', json_encode([$operation, $v], JSON_THROW_ON_ERROR));
    }

    private function prior(string $key, string $hash): ?object
    {
        $old = DB::table('commercial_subscription_events')->where('request_key', $key)->first();
        if ($old) {
            abort_unless(hash_equals($old->fingerprint, $hash), 409, 'Subscription idempotency key payload mismatch.');
        }

        return $old;
    }

    private function event(int $sub, ?int $actor, string $kind, array $data, ?string $key = null, ?string $hash = null): void
    {
        DB::table('commercial_subscription_events')->insert(['subscription_id' => $sub, 'actor_id' => $actor, 'kind' => $kind, 'request_key' => $key, 'fingerprint' => $hash, 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private function availablePlan(int $id, User $user): object
    {
        $p = $this->plan($id);
        abort_unless(DB::table('commercial_plan_availability')->where('plan_id', $id)->where('available', true)->exists(), 409, 'Plan is not open for enrollment.');
        abort_unless($p->audience === $user->role && in_array($user->role, ['customer', 'vendor'], true), 409, 'Plan audience does not match this account.');

        return $p;
    }

    public function subscribe(User $user, int $planId, string $key): array
    {
        return DB::transaction(function () use ($user, $planId, $key) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $k = $this->key($user->id, $key);
            $hash = $this->hash('subscribe', [$planId]);
            if ($old = $this->prior($k, $hash)) {
                return $this->summary($old->subscription_id);
            }
            $p = $this->availablePlan($planId, $user);
            abort_if(DB::table('commercial_subscriptions')->where('user_id', $user->id)->where('status', '!=', 'cancelled')->exists(), 409, 'An existing subscription must be cancelled before enrolling again.');
            $id = DB::table('commercial_subscriptions')->insertGetId(['user_id' => $user->id, 'plan_id' => $p->id, 'status' => 'pending', 'cycle_number' => 0, 'cancel_at_period_end' => false, 'created_at' => now(), 'updated_at' => now()]);
            $this->invoice($id, $p, CarbonImmutable::now(), 1);
            $this->event($id, $user->id, 'subscribed', ['plan_id' => $p->id], $k, $hash);

            return $this->summary($id);
        }, 3);
    }

    private function end(CarbonImmutable $start, string $interval, int $subscription): CarbonImmutable
    {
        // Keep the original anniversary day across short months and leap years.
        // Changing cadence resets the anchor at the current cycle boundary.
        $anchor = $start;
        $cycles = 0;
        $previous = DB::table('commercial_subscription_invoices as i')->join('commercial_plan_versions as p', 'p.id', '=', 'i.plan_id')->where('i.subscription_id', $subscription)->orderByDesc('i.cycle_number')->get(['i.period_start', 'p.interval']);
        foreach ($previous as $invoice) {
            if ($invoice->interval !== $interval) {
                break;
            }
            $anchor = CarbonImmutable::parse($invoice->period_start);
            $cycles++;
        }

        return match ($interval) {
            'month' => $anchor->addMonthsNoOverflow($cycles + 1),
            'year' => $anchor->addYearsNoOverflow($cycles + 1),
            default => throw new \LogicException('Unsupported billing interval'),
        };
    }

    private function invoice(int $sub, object $p, CarbonImmutable $start, int $cycle): int
    {
        $tax = Money::multiplyDivide((int) $p->price_minor, (int) $p->tax_bps, 10000);

        $id = DB::table('commercial_subscription_invoices')->insertGetId(['number' => 'MOCK-SUB-'.Str::ulid(), 'subscription_id' => $sub, 'plan_id' => $p->id, 'cycle_number' => $cycle, 'period_start' => $start, 'period_end' => $this->end($start, $p->interval, $sub), 'currency' => $p->currency, 'minor_units' => $p->minor_units, 'subtotal_minor' => $p->price_minor, 'tax_minor' => $tax, 'total_minor' => $p->price_minor + $tax, 'entitlements' => json_encode($p->entitlements, JSON_THROW_ON_ERROR), 'evidence' => 'MOCK_PROVIDER', 'created_at' => now()]);
        $this->ledger($id, 'invoice', [['subscription_receivable', $p->price_minor + $tax, 0], ['subscription_revenue', 0, (int) $p->price_minor], ['subscription_tax_payable', 0, $tax]]);

        return $id;
    }

    private function ledger(int $invoice, string $kind, array $entries): void
    {
        if (array_sum(array_column($entries, 1)) !== array_sum(array_column($entries, 2))) {
            throw new \LogicException('Unbalanced subscription journal rejected.');
        }
        foreach ($entries as [$account, $debit, $credit]) {
            if ($debit < 0 || $credit < 0) {
                throw new \LogicException('Negative subscription journal rejected.');
            }
            if ($debit || $credit) {
                DB::table('commercial_subscription_ledger')->insert(['invoice_id' => $invoice, 'kind' => $kind, 'account' => $account, 'debit_minor' => $debit, 'credit_minor' => $credit, 'created_at' => now()]);
            }
        }
    }

    public function access(User $actor, int $id, bool $lock = false): object
    {
        $q = DB::table('commercial_subscriptions')->where('id', $id);
        if ($actor->role !== 'admin') {
            $q->where('user_id', $actor->id);
        }
        $s = ($lock ? $q->lockForUpdate() : $q)->first();
        abort_unless($s, 404);

        return $s;
    }

    public function change(User $actor, int $id, string $action, ?int $planId, string $key): array
    {
        return DB::transaction(function () use ($actor, $id, $action, $planId, $key) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $s = $this->access($actor, $id, true);
            $k = $this->key($actor->id, $key);
            $h = $this->hash('change', [$id, $action, $planId]);
            if ($this->prior($k, $h)) {
                return $this->summary($id);
            }
            abort_if($s->status === 'cancelled', 409, 'Subscription is cancelled.');
            if ($action === 'change_plan') {
                abort_unless($s->status === 'active' && ! $s->cancel_at_period_end && CarbonImmutable::parse($s->period_end)->isFuture(), 409, 'Only a current paid subscription can schedule a plan change.');
                $p = $this->availablePlan($planId, User::findOrFail($s->user_id));
                $current = $this->plan($s->plan_id);
                abort_unless($p->currency === $current->currency && (int) $p->minor_units === (int) $current->minor_units, 409, 'Currency changes require a new subscription.');
                $update = ['next_plan_id' => $p->id];
            } elseif ($action === 'cancel_at_period_end') {
                abort_unless($s->status === 'active', 409, 'No current paid period; cancel immediately instead.');
                $update = ['cancel_at_period_end' => true, 'next_plan_id' => null];
            } elseif ($action === 'resume') {
                abort_unless($s->status === 'active' && $s->cancel_at_period_end && CarbonImmutable::parse($s->period_end)->isFuture(), 409, 'The paid subscription cannot be resumed.');
                $update = ['cancel_at_period_end' => false];
            } else {
                abort_unless($action === 'cancel_now', 422);
                abort_if(DB::table('subscription_gateway_checkouts')->where('subscription_id', $id)->whereIn('status', ['pending', 'authorized', 'action_required'])->exists(), 409, 'Cancel or reconcile the gateway checkout before cancelling the subscription.');
                $update = ['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_at_period_end' => false, 'next_plan_id' => null];
            }
            DB::table('commercial_subscriptions')->where('id', $id)->update($update + ['updated_at' => now()]);
            $this->event($id, $actor->id, $action, ['plan_id' => $planId], $k, $h);

            return $this->summary($id);
        }, 3);
    }

    public function mockPay(User $actor, int $id, int $invoiceId, string $outcome, string $key): array
    {
        abort_if(app()->environment('production'), 503, 'Mock subscription billing is disabled in production.');

        return DB::transaction(function () use ($actor, $id, $invoiceId, $outcome, $key) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $s = $this->access($actor, $id, true);
            $k = $this->key($actor->id, $key);
            $h = $this->hash('mock_pay', [$id, $invoiceId, $outcome]);
            if ($this->prior($k, $h)) {
                return $this->summary($id);
            }
            abort_unless(in_array($outcome, ['succeeded', 'failed'], true), 422);
            abort_unless($s->status !== 'cancelled', 409, 'Cancelled subscriptions cannot be charged.');
            $i = DB::table('commercial_subscription_invoices')->where('subscription_id', $id)->where('id', $invoiceId)->first();
            abort_unless($i, 404);
            abort_if(DB::table('commercial_subscription_receipts')->where('invoice_id', $invoiceId)->exists(), 409, 'Invoice already paid.');
            abort_unless((int) $i->cycle_number === (int) $s->cycle_number + 1, 409, 'Invoice is not the next billing cycle.');
            abort_if(DB::table('subscription_gateway_checkouts')->where('invoice_id', $invoiceId)->whereIn('status', ['pending', 'authorized', 'action_required'])->exists(), 409, 'Resolve the gateway checkout before using manual mock settlement.');
            if ($outcome === 'succeeded') {
                $this->settleReceipt($s, $i, 'mock_sub_'.Str::ulid(), 'MOCK_PROVIDER');
            } else {
                DB::table('commercial_subscriptions')->where('id', $id)->update(['status' => 'past_due', 'updated_at' => now()]);
            }
            $this->event($id, $actor->id, 'payment_'.$outcome, ['invoice_id' => $invoiceId, 'evidence' => 'MOCK_PROVIDER'], $k, $h);

            return $this->summary($id);
        }, 3);
    }

    private function settleReceipt(object $subscription, object $invoice, string $reference, string $evidence): object
    {
        $receipt = DB::table('commercial_subscription_receipts')->insertGetId(['invoice_id' => $invoice->id, 'provider_reference' => $reference, 'amount_minor' => $invoice->total_minor, 'currency' => $invoice->currency, 'evidence' => $evidence, 'created_at' => now()]);
        $account = $evidence === 'STRIPE_TEST_CONTRACT' ? 'cash:stripe_test_subscription' : 'cash:mock_subscription';
        $this->ledger($invoice->id, 'payment', [[$account, (int) $invoice->total_minor, 0], ['subscription_receivable', 0, (int) $invoice->total_minor]]);
        DB::table('commercial_subscriptions')->where('id', $subscription->id)->update(['plan_id' => $invoice->plan_id, 'next_plan_id' => null, 'status' => 'active', 'cycle_number' => $invoice->cycle_number, 'period_start' => $invoice->period_start, 'period_end' => $invoice->period_end, 'updated_at' => now()]);

        return DB::table('commercial_subscription_receipts')->find($receipt);
    }

    /** Internal settlement boundary: only invoke after server-confirmed capture or authenticated provider evidence. */
    public function recordProviderReceipt(int $subscription, int $invoice, string $reference, int $amount, string $currency, string $evidence, ?int $actor): object
    {
        abort_if(app()->environment('production'), 503, 'Subscription test settlement is disabled in production.');
        abort_unless(in_array($evidence, ['MOCK_PROVIDER', 'STRIPE_TEST_CONTRACT'], true), 422);
        try {
            return DB::transaction(function () use ($subscription, $invoice, $reference, $amount, $currency, $evidence, $actor) {
                $s = DB::table('commercial_subscriptions')->where('id', $subscription)->lockForUpdate()->first();
                abort_unless($s, 404);
                $i = DB::table('commercial_subscription_invoices')->where('subscription_id', $subscription)->where('id', $invoice)->first();
                abort_unless($i, 404);
                abort_unless((int) $i->total_minor === $amount && $i->currency === $currency, 409, 'Provider settlement does not match subscription invoice.');
                if ($old = DB::table('commercial_subscription_receipts')->where('invoice_id', $invoice)->first()) {
                    abort_unless($old->provider_reference === $reference && (int) $old->amount_minor === $amount && $old->currency === $currency && $old->evidence === $evidence, 409, 'Subscription invoice already settled by another provider operation.');

                    return $old;
                }
                abort_unless($s->status !== 'cancelled' && (int) $i->cycle_number === (int) $s->cycle_number + 1, 409, 'Subscription invoice is not the next payable cycle.');
                $receipt = $this->settleReceipt($s, $i, $reference, $evidence);
                $this->event($subscription, $actor, 'provider_payment_succeeded', ['invoice_id' => $invoice, 'receipt_id' => $receipt->id, 'evidence' => $evidence]);

                return $receipt;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            abort(409, 'Provider capture identity was already used; reconcile before retrying.');
        }
    }

    public function renew(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $s = DB::table('commercial_subscriptions')->where('id', $id)->lockForUpdate()->first();
            abort_unless($s, 404);
            if ($s->status === 'cancelled' || ! $s->period_end || CarbonImmutable::parse($s->period_end)->isFuture()) {
                return $this->summary($id);
            }
            if ($s->cancel_at_period_end) {
                DB::table('commercial_subscriptions')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
                $this->event($id, null, 'cancelled_on_expiry', []);
            } elseif (! DB::table('commercial_subscription_invoices')->where('subscription_id', $id)->where('cycle_number', $s->cycle_number + 1)->exists()) {
                $p = $this->plan($s->next_plan_id ?? $s->plan_id);
                $i = $this->invoice($id, $p, CarbonImmutable::parse($s->period_end), $s->cycle_number + 1);
                DB::table('commercial_subscriptions')->where('id', $id)->update(['status' => 'past_due', 'updated_at' => now()]);
                $this->event($id, null, 'renewal_invoiced', ['invoice_id' => $i]);
            }

            return $this->summary($id);
        }, 3);
    }

    public function summary(int $id): array
    {
        $s = DB::table('commercial_subscriptions')->find($id);
        abort_unless($s, 404);
        $s->cancel_at_period_end = (bool) $s->cancel_at_period_end;
        $invoices = DB::table('commercial_subscription_invoices')->where('subscription_id', $id)->orderBy('cycle_number')->get()->map(function ($i) {
            $i->entitlements = json_decode($i->entitlements, true, 512, JSON_THROW_ON_ERROR);
            $i->paid = DB::table('commercial_subscription_receipts')->where('invoice_id', $i->id)->exists();

            return $i;
        });
        $current = $invoices->first(fn ($i) => $i->paid && (int) $i->cycle_number === (int) $s->cycle_number);
        $enabled = $s->status === 'active' && $current && CarbonImmutable::parse($current->period_start)->lessThanOrEqualTo(now()) && CarbonImmutable::parse($current->period_end)->isFuture();

        $ledger = DB::table('commercial_subscription_ledger')->whereIn('invoice_id', $invoices->pluck('id'));
        $balanced = (int) (clone $ledger)->sum('debit_minor') === (int) (clone $ledger)->sum('credit_minor');

        $receipts = DB::table('commercial_subscription_receipts')->whereIn('invoice_id', $invoices->pluck('id'))->orderBy('id')->get();
        $provider = $receipts->last()?->evidence ?? 'MOCK_PROVIDER';

        return ['receipts' => $receipts, 'ledger_balanced' => $balanced, 'subscription' => $s, 'plan' => $this->plan($s->plan_id), 'invoices' => $invoices, 'entitlements' => $enabled ? $current->entitlements : [], 'entitlements_active' => (bool) $enabled, 'billing_provider' => $provider, 'real_payment_processed' => false];
    }
}
