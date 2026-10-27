<?php

namespace App\Services\Commercial;

use App\Models\User;
use App\Services\Commercial\Payments\MockPaymentProvider;
use App\Services\Commercial\Payments\PaymentProvider;
use App\Services\Commercial\Payments\PaymentRecovery;
use App\Services\Commercial\Payments\StripeSandboxProvider;
use App\Services\Finance\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionCreditService
{
    public function __construct(private SubscriptionService $subscriptions, private PaymentRecovery $recovery) {}

    private function provider(string $name): PaymentProvider
    {
        abort_if(app()->environment('production'), 503, 'Subscription test refunds are disabled in production.');

        return match ($name) {
            'mock' => new MockPaymentProvider, 'stripe_test' => new StripeSandboxProvider, default => abort(503, 'Unsupported subscription refund provider.')
        };
    }

    private function receipt(int $subscription, int $invoice): object
    {
        $i = DB::table('commercial_subscription_invoices')->where('subscription_id', $subscription)->where('id', $invoice)->first();
        abort_unless($i, 404);
        $r = DB::table('commercial_subscription_receipts')->where('invoice_id', $invoice)->first();
        abort_unless($r, 409, 'An unpaid subscription invoice cannot be refunded.');

        return $r;
    }

    private function capture(object $receipt): ?object
    {
        $capture = DB::table('subscription_gateway_operations')->where('receipt_id', $receipt->id)->where('kind', 'capture')->first();
        $checkout = $capture ? DB::table('subscription_gateway_checkouts')->find($capture->checkout_id) : null;
        if ($receipt->evidence === 'STRIPE_TEST_CONTRACT') {
            abort_unless($capture && $checkout && $checkout->provider === 'stripe_test' && $checkout->status === 'captured' && $checkout->currency === $receipt->currency && (int) $checkout->amount_minor === (int) $receipt->amount_minor && (int) $checkout->invoice_id === (int) $receipt->invoice_id && (int) $capture->amount_minor === (int) $receipt->amount_minor && $capture->provider_operation_reference === $receipt->provider_reference && preg_match('/^pi_[A-Za-z0-9]+$/D', $checkout->provider_reference), 409, 'A reconciled Stripe test capture is required; local-only external credits are prohibited.');
        } else {
            abort_unless($receipt->evidence === 'MOCK_PROVIDER' && (! $checkout || $checkout->provider === 'mock'), 409, 'Receipt provider evidence does not reconcile.');
        }

        return $checkout;
    }

    public function refund(User $actor, int $subscription, int $invoice, int $amount, string $reason, string $key): array
    {
        abort_unless($actor->role === 'admin', 403);
        abort_if(app()->environment('production'), 503);
        $requestKey = hash('sha256', json_encode(['subscription-credit', $actor->id, $key], JSON_THROW_ON_ERROR));
        $fingerprint = hash('sha256', json_encode([$subscription, $invoice, $amount, $reason], JSON_THROW_ON_ERROR));
        // The immutable request survives an external timeout and retains exact amount/reason provenance.
        $request = DB::transaction(function () use ($actor, $subscription, $invoice, $amount, $reason, $requestKey, $fingerprint) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->subscriptions->access($actor, $subscription, true);
            if ($old = DB::table('subscription_credit_requests')->where('request_key', $requestKey)->first()) {
                abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, 'Subscription credit key payload mismatch.');

                return $old;
            }
            $receipt = $this->receipt($subscription, $invoice);
            $checkout = $this->capture($receipt);
            $refunded = (int) DB::table('subscription_credits')->where('receipt_id', $receipt->id)->sum('amount_minor');
            abort_unless($amount > 0 && $amount <= (int) $receipt->amount_minor - $refunded, 409, 'Subscription refund exceeds remaining captured funds.');
            $id = DB::table('subscription_credit_requests')->insertGetId(['subscription_id' => $subscription, 'invoice_id' => $invoice, 'receipt_id' => $receipt->id, 'checkout_id' => $checkout?->id, 'actor_id' => $actor->id, 'request_key' => $requestKey, 'recovery_identity' => $checkout && $checkout->provider === 'stripe_test' ? hash('sha256', json_encode(['subscription', $checkout->id, 'refund', $requestKey], JSON_THROW_ON_ERROR)) : null, 'fingerprint' => $fingerprint, 'amount_minor' => $amount, 'currency' => $receipt->currency, 'reason' => $reason, 'created_at' => now()]);

            return DB::table('subscription_credit_requests')->find($id);
        }, 3);
        if ($prior = DB::table('subscription_credits')->where('request_id', $request->id)->first()) {
            return $this->detail($actor, $prior->id);
        }
        $receipt = $this->receipt($subscription, $invoice);
        $checkout = $this->capture($receipt);
        $providerName = $receipt->evidence === 'STRIPE_TEST_CONTRACT' ? 'stripe_test' : 'mock';
        $provider = $this->provider($providerName);
        $action = function () use ($request, $provider, $receipt, $checkout) {
            return DB::transaction(function () use ($request, $provider, $receipt, $checkout) {
                $s = DB::table('commercial_subscriptions')->where('id', $request->subscription_id)->lockForUpdate()->firstOrFail();
                if ($prior = DB::table('subscription_credits')->where('request_id', $request->id)->first()) {
                    return $prior;
                }
                $currentReceipt = $this->receipt($s->id, $request->invoice_id);
                $this->capture($currentReceipt);
                $refunded = (int) DB::table('subscription_credits')->where('receipt_id', $receipt->id)->sum('amount_minor');
                abort_unless((int) $request->amount_minor <= (int) $receipt->amount_minor - $refunded, 409, 'Subscription refund exceeds remaining captured funds.');
                $i = DB::table('commercial_subscription_invoices')->find($request->invoice_id);
                if ((int) $request->amount_minor + $refunded === (int) $receipt->amount_minor && (int) $s->cycle_number === (int) $i->cycle_number) {
                    abort_if(DB::table('subscription_gateway_checkouts')->where('subscription_id', $s->id)->where('invoice_id', '!=', $i->id)->whereIn('status', ['pending', 'authorized', 'action_required'])->exists(), 409, 'Cancel or reconcile the next-cycle checkout before fully refunding the current entitlement.');
                }
                $reference = $checkout?->provider_reference ?? $receipt->provider_reference;
                $result = $provider->refund($reference, (int) $request->amount_minor, $request->request_key);
                abort_unless(($result['status'] ?? null) === 'refunded' && is_string($result['reference'] ?? null), 409, 'Subscription provider refund is not final.');

                return $this->post($request, $result['reference']);
            }, 3);
        };
        if ($checkout && $providerName === 'stripe_test') {
            $this->recovery->run('subscription', $checkout, $actor->id, 'refund', $requestKey, [$invoice, $amount, $receipt->currency, $reason], $action);
        } else {
            $action();
        }
        $credit = DB::table('subscription_credits')->where('request_id', $request->id)->first();
        abort_unless($credit, 409, 'Refund outcome requires reconciliation before creating a credit.');

        return $this->detail($actor, $credit->id);
    }

    private function post(object $request, string $providerReference): object
    {
        if ($old = DB::table('subscription_credits')->where('provider_reference', $providerReference)->first()) {
            abort_unless((int) $old->request_id === (int) $request->id, 409, 'Provider refund identity was used by another credit.');

            return $old;
        }
        $invoice = DB::table('commercial_subscription_invoices')->find($request->invoice_id);
        $receipt = DB::table('commercial_subscription_receipts')->find($request->receipt_id);
        $credits = DB::table('subscription_credits')->where('receipt_id', $receipt->id);
        $allocation = Money::allocate((int) $request->amount_minor, ['subtotal_minor' => (int) $invoice->subtotal_minor - (int) (clone $credits)->sum('subtotal_minor'), 'tax_minor' => (int) $invoice->tax_minor - (int) (clone $credits)->sum('tax_minor')]);
        $id = DB::table('subscription_credits')->insertGetId(['request_id' => $request->id, 'number' => 'TEST-SUB-CN-'.Str::ulid(), 'subscription_id' => $request->subscription_id, 'invoice_id' => $invoice->id, 'receipt_id' => $receipt->id, 'actor_id' => $request->actor_id, 'amount_minor' => $request->amount_minor, ...$allocation, 'currency' => $invoice->currency, 'minor_units' => $invoice->minor_units, 'provider_reference' => $providerReference, 'evidence' => $receipt->evidence, 'reason' => $request->reason, 'created_at' => now()]);
        $cash = $receipt->evidence === 'STRIPE_TEST_CONTRACT' ? 'cash:stripe_test_subscription' : 'cash:mock_subscription';
        foreach ([['subscription_revenue', $allocation['subtotal_minor'], 0], ['subscription_tax_payable', $allocation['tax_minor'], 0], [$cash, 0, (int) $request->amount_minor]] as [$account, $debit, $credit]) {
            if ($debit || $credit) {
                DB::table('commercial_subscription_ledger')->insert(['invoice_id' => $invoice->id, 'kind' => 'c'.$id, 'account' => $account, 'debit_minor' => $debit, 'credit_minor' => $credit, 'created_at' => now()]);
            }
        }
        $s = DB::table('commercial_subscriptions')->find($request->subscription_id);
        $full = (int) DB::table('subscription_credits')->where('receipt_id', $receipt->id)->sum('amount_minor') === (int) $receipt->amount_minor;
        if ($full && (int) $s->cycle_number === (int) $invoice->cycle_number) {
            DB::table('commercial_subscriptions')->where('id', $s->id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_at_period_end' => false, 'next_plan_id' => null, 'updated_at' => now()]);
        }
        DB::table('commercial_subscription_events')->insert(['subscription_id' => $request->subscription_id, 'actor_id' => $request->actor_id, 'kind' => 'credit_refund_posted', 'data' => json_encode(['credit_id' => $id, 'invoice_id' => $invoice->id, 'amount_minor' => (int) $request->amount_minor, 'evidence' => $receipt->evidence], JSON_THROW_ON_ERROR), 'created_at' => now()]);
        DB::table('audit_events')->insert(['actor_id' => $request->actor_id, 'action' => 'finance.subscription_credit_posted', 'subject' => (string) $id, 'created_at' => now()]);

        return DB::table('subscription_credits')->find($id);
    }

    public function stripeWebhook(string $raw, string $signature): array
    {
        abort_if(app()->environment('production'), 503);
        $event = (new StripeSandboxProvider)->verifyWebhook($raw, $signature);
        if (($event['type'] ?? null) !== 'refund.updated') {
            return ['accepted' => true, 'ignored' => true, 'evidence' => 'STRIPE_TEST_CONTRACT'];
        }
        $object = $event['data']['object'] ?? null;
        abort_unless(is_array($object) && ($object['livemode'] ?? null) === false && ($object['status'] ?? null) === 'succeeded' && is_string($object['id'] ?? null) && preg_match('/^re_[A-Za-z0-9]+$/D', $object['id']) && is_string($object['payment_intent'] ?? null) && preg_match('/^pi_[A-Za-z0-9]+$/D', $object['payment_intent']), 422, 'Expected a final Stripe test refund.');
        $initial = DB::table('subscription_gateway_checkouts')->where('provider', 'stripe_test')->where('provider_reference', $object['payment_intent'])->first();
        abort_unless($initial, 404);
        $eventId = hash('sha256', 'subscription-credit:'.$event['id']);
        $fingerprint = hash('sha256', $raw);
        try {
            return DB::transaction(function () use ($initial, $object, $eventId, $fingerprint) {
                DB::table('commercial_subscriptions')->where('id', $initial->subscription_id)->lockForUpdate()->firstOrFail();
                $checkout = DB::table('subscription_gateway_checkouts')->where('id', $initial->id)->lockForUpdate()->firstOrFail();
                if ($old = DB::table('subscription_credit_webhooks')->where('provider_event_id', $eventId)->first()) {
                    abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, 'Subscription refund event payload mismatch.');

                    return ['accepted' => true, 'duplicate' => true, 'evidence' => 'STRIPE_TEST_CONTRACT'];
                }
                $credit = $this->postVerifiedRefund($checkout, $object);
                DB::table('subscription_credit_webhooks')->insert(['provider_event_id' => $eventId, 'fingerprint' => $fingerprint, 'credit_id' => $credit->id, 'created_at' => now()]);

                return ['accepted' => true, 'duplicate' => false, 'credit_id' => $credit->id, 'evidence' => 'STRIPE_TEST_CONTRACT'];
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            $old = DB::table('subscription_credit_webhooks')->where('provider_event_id', $eventId)->first();
            abort_unless($old && hash_equals($old->fingerprint, $fingerprint), 409, 'Subscription refund identity conflict.');

            return ['accepted' => true, 'duplicate' => true, 'evidence' => 'STRIPE_TEST_CONTRACT'];
        }
    }

    /** Read-only provider reconciliation; never sends a refund or fabricates a webhook signature. */
    public function recoverFromProvider(object $intent): array
    {
        abort_if(app()->environment('production'), 503);
        $canonical = DB::table('payment_recovery_intents')->where('id', $intent->id)->where('scope', 'subscription')->where('kind', 'refund')->where('provider', 'stripe_test')->first();
        abort_unless($canonical, 404);
        $checkout = DB::table('subscription_gateway_checkouts')->where('id', $canonical->checkout_id)->where('provider', 'stripe_test')->first();
        abort_unless($checkout, 404);
        $object = (new StripeSandboxProvider)->recoveryEvidence($canonical, $checkout);

        return DB::transaction(function () use ($checkout, $canonical, $object) {
            DB::table('commercial_subscriptions')->where('id', $checkout->subscription_id)->lockForUpdate()->firstOrFail();
            $checkout = DB::table('subscription_gateway_checkouts')->where('id', $checkout->id)->lockForUpdate()->firstOrFail();
            $credit = $this->postVerifiedRefund($checkout, $object, $canonical->id);

            return ['recovered' => true, 'credit_id' => $credit->id, 'evidence' => 'STRIPE_TEST_CONTRACT', 'external_provider_verified' => false, 'real_payment_processed' => false];
        }, 3);
    }

    /** Caller must already have authenticated the provider source and locked the subscription/checkout. */
    private function postVerifiedRefund(object $checkout, array $object, ?int $expectedRecovery = null): object
    {
        abort_unless(($object['livemode'] ?? null) === false && ($object['status'] ?? null) === 'succeeded' && is_string($object['id'] ?? null) && preg_match('/^re_[A-Za-z0-9]+$/D', $object['id']) && ($object['payment_intent'] ?? null) === $checkout->provider_reference, 409, 'Provider refund is not final or belongs to another capture.');
        $amount = $object['amount'] ?? null;
        $currency = strtoupper((string) ($object['currency'] ?? ''));
        abort_unless(is_int($amount) && $amount > 0 && $currency === $checkout->currency, 409, 'Subscription refund amount or currency mismatch.');
        $credit = DB::table('subscription_credits')->where('provider_reference', $object['id'])->first();
        $evidence = ['id' => $object['id'], 'amount' => $amount, 'currency' => $currency];
        $recoveryId = $object['metadata']['creabiz_recovery'] ?? null;
        if (is_int($recoveryId) || (is_string($recoveryId) && ctype_digit($recoveryId))) {
            $evidence['recovery_id'] = (int) $recoveryId;
        }
        if ($credit) {
            abort_unless((int) $credit->invoice_id === (int) $checkout->invoice_id && (int) $credit->amount_minor === $amount && $credit->currency === $currency && $credit->evidence === 'STRIPE_TEST_CONTRACT', 409, 'Subscription refund does not match its posted credit.');
            $request = DB::table('subscription_credit_requests')->find($credit->request_id);
            $intent = DB::table('payment_recovery_intents')->where('identity', $request->recovery_identity)->first();
            abort_unless($intent && ($expectedRecovery === null || (int) $intent->id === $expectedRecovery) && (! isset($evidence['recovery_id']) || $evidence['recovery_id'] === (int) $intent->id), 409, 'Posted credit recovery identity does not match.');
        } else {
            $intents = DB::table('payment_recovery_intents')->where('scope', 'subscription')->where('checkout_id', $checkout->id)->where('kind', 'refund')->get();
            $intent = $intents->first(function ($i) use ($evidence) {
                $observation = $i->observation ? json_decode($i->observation, true, 512, JSON_THROW_ON_ERROR) : [];

                return isset($evidence['recovery_id']) ? (int) $i->id === $evidence['recovery_id'] : ($observation['id'] ?? null) === $evidence['id'];
            });
            abort_unless($intent && ($expectedRecovery === null || (int) $intent->id === $expectedRecovery), 409, 'Uncorrelated provider refund requires financial review.');
            $request = DB::table('subscription_credit_requests')->where('recovery_identity', $intent->identity)->first();
            abort_unless($request && (int) $request->checkout_id === (int) $checkout->id && (int) $request->amount_minor === $amount && $request->currency === $currency && (int) $intent->amount_minor === $amount && $intent->currency === $currency, 409, 'Provider refund does not match the authorized credit request.');
            $receipt = $this->receipt($request->subscription_id, $request->invoice_id);
            $this->capture($receipt);
            $refunded = (int) DB::table('subscription_credits')->where('receipt_id', $receipt->id)->sum('amount_minor');
            abort_unless($amount <= (int) $receipt->amount_minor - $refunded, 409, 'Provider refund exceeds remaining captured funds.');
            $credit = $this->post($request, $object['id']);
        }
        $evidence['recovery_id'] = (int) $intent->id;
        $this->recovery->resolveEvidence('subscription', $checkout->id, 'refund', $evidence);

        return $credit;
    }

    public function detail(User $actor, int $credit): array
    {
        $c = DB::table('subscription_credits')->find($credit);
        abort_unless($c, 404);
        $this->subscriptions->access($actor, $c->subscription_id);

        return ['credit' => $c, 'entries' => DB::table('commercial_subscription_ledger')->where('invoice_id', $c->invoice_id)->where('kind', 'c'.$c->id)->orderBy('id')->get(), 'subscription' => $this->subscriptions->summary($c->subscription_id), 'real_payment_processed' => false];
    }
}
