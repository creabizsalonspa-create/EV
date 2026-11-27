<?php

namespace App\Services\Commercial;

use App\Models\User;
use App\Security\CurrentFinanceReader;
use App\Services\Commercial\Payments\HostedCheckout;
use App\Services\Finance\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionService
{
    private const MAX_EXACT_MINOR = 9_007_199_254_740_991;

    /** Reject coerced, fractional or unsafe source money before financial JSON. */
    private static function exactMinor(mixed $value, bool $signed = false): int
    {
        abort_unless(is_int($value) || is_string($value), 409,
            'Subscription source amount is not an exact minor-unit integer.');
        $minor = filter_var($value, FILTER_VALIDATE_INT);
        abort_unless($minor !== false && $minor <= self::MAX_EXACT_MINOR
            && $minor >= ($signed ? -self::MAX_EXACT_MINOR : 0), 409,
            'Subscription source amount exceeds exact integer bounds.');

        return $minor;
    }

    private static function checkedAdd(int $left, int $right, bool $signed = false): int
    {
        return self::exactMinor($left + $right, $signed);
    }

    private static function checkedSum(iterable $rows, string $column): int
    {
        $total = 0;
        foreach ($rows as $row) {
            $value = is_array($row) ? $row[$column] : $row->{$column};
            $total = self::checkedAdd($total, self::exactMinor($value));
        }

        return $total;
    }

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
            $actor = app(CurrentFinanceReader::class)->requireSnapshot($actor);
            abort_unless($actor->role === 'admin', 403);
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

    /**
     * A current summary may reflect later actions. Acknowledge only the exact
     * immutable event belonging to this actor and original saved intent.
     */
    private function operationSummary(int $id, int $actor, string $key, string $fingerprint, string $action, ?int $planId): array
    {
        $event = $this->prior($this->key($actor, $key), $fingerprint);
        abort_unless($event && (int) $event->subscription_id === $id
            && (int) $event->actor_id === $actor
            && $event->kind === ($action === 'enroll' ? 'subscribed' : $action),
            409, 'Subscription operation evidence requires reconciliation.');
        $data = json_decode($event->data, true, 512, JSON_THROW_ON_ERROR);
        abort_unless(is_array($data) && array_key_exists('plan_id', $data)
            && ($planId === null ? $data['plan_id'] === null
                : (is_int($data['plan_id']) || is_string($data['plan_id']))
                    && (string) $data['plan_id'] === (string) $planId),
            409, 'Subscription operation plan evidence requires reconciliation.');
        $eventId = (int) $event->id;

        return $this->summary($id) + ['operation_receipt' => [
            'event_id' => $eventId, 'actor_id' => $actor, 'subscription_id' => $id,
            'action' => $action, 'plan_id' => $planId,
            'request_receipt' => hash('sha256', 'subscription-operation-v1:'.$actor.':'.$id.':'.$eventId.':'.$action.':'.($planId ?? 0).':'.$key),
        ]];
    }

    private function availablePlan(int $id, User $user, bool $lock = false): object
    {
        $p = $this->plan($id);
        // Serialize new commitments with catalog retirement.
        $query = DB::table('commercial_plan_availability')->where('plan_id', $id);
        $availability = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($availability && (bool) $availability->available, 409, 'Plan is not open for enrollment.');
        abort_unless($p->audience === $user->role && in_array($user->role, ['customer', 'vendor'], true), 409, 'Plan audience does not match this account.');

        return $p;
    }

    public function subscribe(User $user, int $planId, string $key): array
    {
        return DB::transaction(function () use ($user, $planId, $key) {
            $user = app(CurrentFinanceReader::class)->requireSnapshot($user);
            $k = $this->key($user->id, $key);
            $hash = $this->hash('subscribe', [$planId]);
            if ($old = $this->prior($k, $hash)) {
                return $this->operationSummary((int) $old->subscription_id, $user->id, $key, $hash, 'enroll', $planId);
            }
            $p = $this->availablePlan($planId, $user, true);
            abort_if(DB::table('commercial_subscriptions')->where('user_id', $user->id)->where('status', '!=', 'cancelled')->exists(), 409, 'An existing subscription must be cancelled before enrolling again.');
            $id = DB::table('commercial_subscriptions')->insertGetId(['user_id' => $user->id, 'plan_id' => $p->id, 'status' => 'pending', 'cycle_number' => 0, 'cancel_at_period_end' => false, 'created_at' => now(), 'updated_at' => now()]);
            $this->invoice($id, $p, CarbonImmutable::now(), 1);
            $this->event($id, $user->id, 'subscribed', ['plan_id' => $p->id], $k, $hash);

            return $this->operationSummary($id, $user->id, $key, $hash, 'enroll', $planId);
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
        $price = self::exactMinor($p->price_minor);
        $tax = Money::multiplyDivide($price, (int) $p->tax_bps, 10000);
        $total = self::checkedAdd($price, self::exactMinor($tax));

        $id = DB::table('commercial_subscription_invoices')->insertGetId(['number' => 'MOCK-SUB-'.Str::ulid(), 'subscription_id' => $sub, 'plan_id' => $p->id, 'cycle_number' => $cycle, 'period_start' => $start, 'period_end' => $this->end($start, $p->interval, $sub), 'currency' => $p->currency, 'minor_units' => $p->minor_units, 'subtotal_minor' => $price, 'tax_minor' => $tax, 'total_minor' => $total, 'entitlements' => json_encode($p->entitlements, JSON_THROW_ON_ERROR), 'evidence' => 'MOCK_PROVIDER', 'created_at' => now()]);
        $this->ledger($id, 'invoice', [['subscription_receivable', $total, 0], ['subscription_revenue', 0, $price], ['subscription_tax_payable', 0, $tax]]);

        return $id;
    }

    private function ledger(int $invoice, string $kind, array $entries): void
    {
        // Validate every entry and both running totals before any financial
        // posting. An overflow after the first insert must never leave a
        // partially applied journal, even outside a caller transaction.
        $debits = 0;
        $credits = 0;
        $normalized = [];
        foreach ($entries as [$account, $debit, $credit]) {
            $debit = self::exactMinor($debit);
            $credit = self::exactMinor($credit);
            $debits = self::checkedAdd($debits, $debit);
            $credits = self::checkedAdd($credits, $credit);
            $normalized[] = [$account, $debit, $credit];
        }
        if ($debits !== $credits) {
            throw new \LogicException('Unbalanced subscription journal rejected.');
        }
        foreach ($normalized as [$account, $debit, $credit]) {
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

    /**
     * Share paid-cycle, audience and currency policy between preview and write.
     * Neither checking nor quoting may create a financial record.
     */
    private function eligibleChangePlan(object $subscription, ?int $planId, bool $lock = false): object
    {
        abort_unless(! $subscription->cancel_at_period_end && SubscriptionPaidCycle::current($subscription), 409, 'Only a current paid subscription can schedule a plan change.');
        abort_unless($planId !== null, 422, 'A target plan is required.');
        $owner = User::findOrFail($subscription->user_id);
        abort_unless($owner->account_status === 'active', 409, 'Subscription owner is not active.');
        $plan = $this->availablePlan($planId, $owner, $lock);
        $current = $this->plan($subscription->plan_id);
        abort_unless($plan->currency === $current->currency &&
            (int) $plan->minor_units === (int) $current->minor_units, 409, 'Currency changes require a new subscription.');

        return $plan;
    }

    /**
     * Read-only, non-binding proposal for the next billing cycle.
     * The existing subscription write rechecks all eligibility.
     */
    public function previewChange(User $actor, int $subscriptionId, int $targetPlanId): array
    {
        $subscription = $this->access($actor, $subscriptionId);
        $plan = $this->eligibleChangePlan($subscription, $targetPlanId);
        $tax = Money::multiplyDivide((int) $plan->price_minor, (int) $plan->tax_bps, 10000);
        $currentPlan = $this->plan((int) $subscription->plan_id);
        $currentTax = Money::multiplyDivide((int) $currentPlan->price_minor, (int) $currentPlan->tax_bps, 10000);
        $currentListTotal = (int) $currentPlan->price_minor + $currentTax;
        $nextListTotal = (int) $plan->price_minor + $tax;
        // Monthly and annual totals are not comparable without a stated horizon.
        // This is nominal published list pricing, NOT a credit, proration,
        // outstanding balance, or a charge instruction.
        $sameCadence = $currentPlan->interval === $plan->interval;

        return [
            'subscription_id' => (int) $subscription->id,
            'current_plan_id' => (int) $subscription->plan_id,
            'proposed_plan_id' => (int) $plan->id,
            'proposed_plan_version' => (int) $plan->version,
            'currency' => $plan->currency,
            'minor_units' => (int) $plan->minor_units,
            'interval' => $plan->interval,
            'effective_at_utc' => CarbonImmutable::parse($subscription->period_end)->utc()->toIso8601String(),
            'next_cycle_subtotal_minor' => (int) $plan->price_minor,
            'next_cycle_tax_minor' => $tax,
            'next_cycle_total_minor' => $nextListTotal,
            'current_plan_list_total_minor' => $currentListTotal,
            'current_plan_interval' => $currentPlan->interval,
            'cycles_comparable' => $sameCadence,
            'nominal_plan_list_difference_minor' => $sameCadence ? $nextListTotal - $currentListTotal : null,
            'comparison_is_proration' => false,
            'amount_due_now_minor' => 0,
            'proration_supported' => false,
            'next_cycle_invoice_issued' => false,
            'quote_is_binding' => false,
            'evidence' => 'SIMULATED_NEXT_CYCLE_PREVIEW',
            'real_payment_processed' => false,
        ];
    }

    public function change(User $actor, int $id, string $action, ?int $planId, string $key): array
    {
        return DB::transaction(function () use ($actor, $id, $action, $planId, $key) {
            $actor = app(CurrentFinanceReader::class)->requireSnapshot($actor);
            $s = $this->access($actor, $id, true);
            $k = $this->key($actor->id, $key);
            $h = $this->hash('change', [$id, $action, $planId]);
            if ($this->prior($k, $h)) {
                return $this->operationSummary($id, $actor->id, $key, $h, $action, $planId);
            }
            abort_if($s->status === 'cancelled', 409, 'Subscription is cancelled.');
            if ($action === 'change_plan') {
                $p = $this->eligibleChangePlan($s, $planId, true);
                $update = ['next_plan_id' => $p->id];
            } elseif ($action === 'cancel_at_period_end') {
                abort_unless(SubscriptionPaidCycle::current($s), 409, 'No current paid period; cancel immediately instead.');
                $update = ['cancel_at_period_end' => true, 'next_plan_id' => null];
            } elseif ($action === 'resume') {
                abort_unless($s->cancel_at_period_end && SubscriptionPaidCycle::current($s), 409, 'The paid subscription cannot be resumed.');
                $update = ['cancel_at_period_end' => false];
            } else {
                abort_unless($action === 'cancel_now', 422);
                abort_if(DB::table('subscription_gateway_checkouts')->where('subscription_id', $id)->whereIn('status', ['pending', 'authorized', 'action_required'])->exists(), 409, 'Cancel or reconcile the gateway checkout before cancelling the subscription.');
                $update = ['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_at_period_end' => false, 'next_plan_id' => null];
            }
            DB::table('commercial_subscriptions')->where('id', $id)->update($update + ['updated_at' => now()]);
            $this->event($id, $actor->id, $action, ['plan_id' => $planId], $k, $h);

            return $this->operationSummary($id, $actor->id, $key, $h, $action, $planId);
        }, 3);
    }

    public function mockPay(User $actor, int $id, int $invoiceId, string $outcome, string $key): array
    {
        abort_if(app()->environment('production'), 503, 'Mock subscription billing is disabled in production.');

        return DB::transaction(function () use ($actor, $id, $invoiceId, $outcome, $key) {
            $actor = app(CurrentFinanceReader::class)->requireSnapshot($actor);
            $s = $this->access($actor, $id, true);
            HostedCheckout::guard('subscription', $invoiceId);
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
        $total = self::exactMinor($invoice->total_minor);
        $receipt = DB::table('commercial_subscription_receipts')->insertGetId(['invoice_id' => $invoice->id, 'provider_reference' => $reference, 'amount_minor' => $total, 'currency' => $invoice->currency, 'evidence' => $evidence, 'created_at' => now()]);
        $account = $evidence === 'STRIPE_TEST_CONTRACT' ? 'cash:stripe_test_subscription' : 'cash:mock_subscription';
        $this->ledger($invoice->id, 'payment', [[$account, $total, 0], ['subscription_receivable', 0, $total]]);
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
                HostedCheckout::guard('subscription', $invoice);
                $i = DB::table('commercial_subscription_invoices')->where('subscription_id', $subscription)->where('id', $invoice)->first();
                abort_unless($i, 404);
                abort_unless(self::exactMinor($i->total_minor) === self::exactMinor($amount)
                    && $i->currency === $currency, 409,
                    'Provider settlement does not match subscription invoice.');
                if ($old = DB::table('commercial_subscription_receipts')->where('invoice_id', $invoice)->first()) {
                    abort_unless($old->provider_reference === $reference
                        && self::exactMinor($old->amount_minor) === $amount
                        && $old->currency === $currency && $old->evidence === $evidence,
                        409, 'Subscription invoice already settled by another provider operation.');

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

    public function renew(int $id, ?int $expectedCycle = null): array
    {
        return DB::transaction(function () use ($id, $expectedCycle) {
            $s = DB::table('commercial_subscriptions')->where('id', $id)->lockForUpdate()->first();
            abort_unless($s, 404);
            // A delayed native confirmation must not advance a different
            // paid cycle. Existing scheduler and web callers may omit it.
            abort_unless($expectedCycle === null || (int) $s->cycle_number === $expectedCycle,
                409, 'Subscription cycle changed; refresh before checking renewal.');
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
            $i->total_minor = self::exactMinor($i->total_minor);
            $i->subtotal_minor = self::exactMinor($i->subtotal_minor);
            $i->tax_minor = self::exactMinor($i->tax_minor);
            abort_unless(self::checkedAdd($i->subtotal_minor, $i->tax_minor) === $i->total_minor,
                409, 'Subscription invoice components no longer reconcile.');
            $receipt = DB::table('commercial_subscription_receipts')->where('invoice_id', $i->id)->first();
            $i->paid = (bool) $receipt;
            $creditsForInvoice = DB::table('subscription_credits')
                ->where('invoice_id', $i->id)->get(['amount_minor']);
            $i->refunded_minor = self::checkedSum($creditsForInvoice, 'amount_minor');
            $collected = $receipt ? self::exactMinor($receipt->amount_minor) : 0;
            $i->net_collected_minor = self::checkedAdd($collected, -$i->refunded_minor, true);
            abort_unless($i->net_collected_minor >= 0, 409,
                'Subscription credits exceed the collected source.');
            $i->credit_state = $i->refunded_minor === 0 ? 'none'
                : ($receipt && $i->refunded_minor === $collected
                    ? 'fully_refunded' : 'partially_refunded');

            return $i;
        });
        $current = $invoices->first(fn ($i) => $i->paid
            && ($i->total_minor === 0 || $i->net_collected_minor > 0)
            && (int) $i->cycle_number === (int) $s->cycle_number);
        $enabled = SubscriptionPaidCycle::current($s) && $current;

        // Read exact individual ledger entries; SQL SUM/Collection::sum may
        // become a PHP float before an unsafe legacy (int) cast.
        $entries = DB::table('commercial_subscription_ledger')
            ->whereIn('invoice_id', $invoices->pluck('id'))
            ->get(['debit_minor', 'credit_minor']);
        $balanced = self::checkedSum($entries, 'debit_minor')
            === self::checkedSum($entries, 'credit_minor');

        $receipts = DB::table('commercial_subscription_receipts')
            ->whereIn('invoice_id', $invoices->pluck('id'))->orderBy('id')->get();
        $provider = $receipts->last()?->evidence ?? 'MOCK_PROVIDER';

        $credits = DB::table('subscription_credits')
            ->where('subscription_id', $id)->orderBy('id')->get();
        $totalCredited = self::checkedSum($credits, 'amount_minor');
        $netCollected = self::checkedAdd(
            self::checkedSum($receipts, 'amount_minor'), -$totalCredited, true);
        abort_unless($netCollected >= 0, 409,
            'Subscription credits exceed collected source receipts.');

        return ['credits' => $credits, 'refunded_minor' => $totalCredited,
            'net_collected_minor' => $netCollected, 'receipts' => $receipts,
            'ledger_balanced' => $balanced, 'subscription' => $s,
            'plan' => $this->plan($s->plan_id), 'invoices' => $invoices,
            'entitlements' => $enabled ? $current->entitlements : [],
            'entitlements_active' => (bool) $enabled, 'billing_provider' => $provider,
            'real_payment_processed' => false];
    }
}
