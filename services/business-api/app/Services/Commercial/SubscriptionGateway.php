<?php

namespace App\Services\Commercial;

use App\Models\User;
use App\Services\Commercial\Payments\MockPaymentProvider;
use App\Services\Commercial\Payments\PaymentProvider;
use App\Services\Commercial\Payments\StripeSandboxProvider;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionGateway
{
    public function __construct(private SubscriptionService $subscriptions) {}

    private function provider(): PaymentProvider
    {
        abort_if(app()->environment('production'), 503, 'Subscription test gateways are disabled in production.');

        return match (config('payments.provider')) {
            'mock' => new MockPaymentProvider, 'stripe_test' => new StripeSandboxProvider, default => abort(503, 'No approved subscription test provider configured.')
        };
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function invoice(int $subscription, int $invoice): object
    {
        $i = DB::table('commercial_subscription_invoices')->where('subscription_id', $subscription)->where('id', $invoice)->first();
        abort_unless($i, 404);

        return $i;
    }

    private function payable(object $s, object $i): void
    {
        abort_unless($s->status !== 'cancelled' && (int) $i->cycle_number === (int) $s->cycle_number + 1 && ! DB::table('commercial_subscription_receipts')->where('invoice_id', $i->id)->exists(), 409, 'The subscription invoice is not payable.');
    }

    public function checkout(User $actor, int $subscription, int $invoice, string $key): object
    {
        $this->provider();

        return DB::transaction(function () use ($actor, $subscription, $invoice, $key) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $s = $this->subscriptions->access($actor, $subscription, true);
            abort_unless((int) $s->user_id === $actor->id, 404);
            $i = $this->invoice($subscription, $invoice);
            $requestKey = $this->hash(['subscription-checkout', $actor->id, $key]);
            $fingerprint = $this->hash([$subscription, $invoice, config('payments.provider')]);
            if ($prior = DB::table('subscription_gateway_checkouts')->where('request_key', $requestKey)->first()) {
                abort_unless(hash_equals($prior->fingerprint, $fingerprint), 409, 'Subscription checkout key payload mismatch.');

                return $prior;
            }
            $this->payable($s, $i);
            abort_unless((int) $i->total_minor > 0, 409, 'Zero-price invoices use the explicit no-funds mock settlement flow.');
            abort_if(DB::table('subscription_gateway_checkouts')->where('invoice_id', $invoice)->whereIn('status', ['pending', 'authorized', 'action_required'])->exists(), 409, 'Resolve the outstanding checkout before creating a replacement.');
            $id = DB::table('subscription_gateway_checkouts')->insertGetId(['subscription_id' => $subscription, 'invoice_id' => $invoice, 'user_id' => $actor->id, 'provider' => config('payments.provider'), 'provider_reference' => 'subscription_checkout_'.Str::ulid(), 'request_key' => $requestKey, 'fingerprint' => $fingerprint, 'currency' => $i->currency, 'amount_minor' => $i->total_minor, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('subscription_gateway_checkouts')->find($id);
        }, 3);
    }

    public function access(User $actor, int $id): object
    {
        $q = DB::table('subscription_gateway_checkouts')->where('id', $id);
        if ($actor->role !== 'admin') {
            $q->where('user_id', $actor->id);
        }
        $c = $q->first();
        abort_unless($c, 404);

        return $c;
    }

    public function operate(User $actor, int $id, string $kind, string $key, ?string $token): object
    {
        $provider = $this->provider();
        $initial = $this->access($actor, $id);
        abort_unless((int) $initial->user_id === $actor->id, 404);

        return DB::transaction(function () use ($actor, $initial, $kind, $key, $token, $provider) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $s = $this->subscriptions->access($actor, $initial->subscription_id, true);
            $c = DB::table('subscription_gateway_checkouts')->where('id', $initial->id)->lockForUpdate()->first();
            abort_unless($c->provider === config('payments.provider'), 409, 'Use the checkout provider configuration.');
            $requestKey = $this->hash(['subscription-operation', $actor->id, $key]);
            $fingerprint = $this->hash([$c->id, $kind, $token]);
            if ($old = DB::table('subscription_gateway_operations')->where('request_key', $requestKey)->first()) {
                abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, 'Subscription operation key payload mismatch.');

                return $c;
            }
            $i = $this->invoice($s->id, $c->invoice_id);
            $receipt = null;
            $operationRef = 'subscription_'.$kind.'_'.$requestKey;
            $values = ['updated_at' => now()];
            if ($kind === 'authorize') {
                $this->payable($s, $i);
                abort_unless($c->status === 'pending', 409, 'Checkout is not pending authorization.');
                $result = $provider->authorize($c->provider_reference, (int) $c->amount_minor, $c->currency, $token ?? '');
                abort_unless(in_array($result['status'] ?? '', ['authorized', 'failed', 'action_required'], true), 502);
                $values += $result + ['authorization_expires_at' => now()->addMinutes((int) config('payments.authorization_minutes', 30))];
            } elseif ($kind === 'capture') {
                if ($c->status === 'captured') {
                    return $c;
                }
                $this->payable($s, $i);
                abort_unless($c->status === 'authorized' && $c->authorization_expires_at && now()->lt($c->authorization_expires_at), 409, 'An unexpired subscription authorization is required.');
                // External identity is stable for the checkout, even after a timeout and a changed client key.
                $result = $provider->capture($c->provider_reference, (int) $c->amount_minor, 'subscription-capture:'.$c->id);
                abort_unless(($result['status'] ?? null) === 'captured', 409, 'Subscription capture is not confirmed.');
                $operationRef = $result['reference'];
                $receipt = $this->subscriptions->recordProviderReceipt($s->id, $i->id, $operationRef, (int) $c->amount_minor, $c->currency, $c->provider === 'stripe_test' ? 'STRIPE_TEST_CONTRACT' : 'MOCK_PROVIDER', $actor->id);
                $values['status'] = 'captured';
            } elseif ($kind === 'cancel') {
                abort_unless(in_array($c->status, ['pending', 'authorized', 'action_required'], true), 409, 'Only an uncaptured checkout can be cancelled.');
                if ($c->status !== 'pending') {
                    $provider->cancel($c->provider_reference, 'subscription-cancel:'.$c->id);
                }
                $values['status'] = 'cancelled';
            } else {
                abort(422);
            }
            DB::table('subscription_gateway_operations')->insert(['checkout_id' => $c->id, 'kind' => $kind, 'request_key' => $requestKey, 'fingerprint' => $fingerprint, 'provider_operation_reference' => $operationRef, 'amount_minor' => $c->amount_minor, 'receipt_id' => $receipt?->id, 'created_at' => now()]);
            DB::table('subscription_gateway_checkouts')->where('id', $c->id)->update($values);

            return DB::table('subscription_gateway_checkouts')->find($c->id);
        }, 3);
    }

    public function stripeWebhook(string $raw, string $signature): array
    {
        $this->provider();
        abort_unless(config('payments.provider') === 'stripe_test', 409);
        $event = (new StripeSandboxProvider)->verifyWebhook($raw, $signature);
        $type = $event['type'] ?? '';
        if (! in_array($type, ['payment_intent.succeeded', 'payment_intent.amount_capturable_updated', 'payment_intent.payment_failed'], true)) {
            return ['accepted' => true, 'ignored' => true, 'evidence' => 'STRIPE_TEST_CONTRACT'];
        }
        $object = $event['data']['object'] ?? null;
        abort_unless(is_array($object) && ($object['livemode'] ?? null) === false && is_string($object['id'] ?? null) && preg_match('/^pi_[A-Za-z0-9]+$/D', $object['id']), 422);
        $reference = $object['id'];
        $metadata = $object['metadata']['creabiz_checkout'] ?? null;
        $initial = DB::table('subscription_gateway_checkouts')->where('provider', 'stripe_test')->where(function ($q) use ($reference, $metadata) {
            $q->where('provider_reference', $reference);
            if (is_string($metadata)) {
                $q->orWhere('provider_reference', $metadata);
            }
        })->first();
        abort_unless($initial, 404, 'No matching subscription test checkout.');
        $eventKey = hash('sha256', 'subscription-stripe:'.$event['id']);
        $hash = hash('sha256', $raw);
        try {
            return DB::transaction(function () use ($initial, $object, $type, $reference, $eventKey, $hash) {
                $s = DB::table('commercial_subscriptions')->where('id', $initial->subscription_id)->lockForUpdate()->first();
                $c = DB::table('subscription_gateway_checkouts')->where('id', $initial->id)->lockForUpdate()->first();
                if ($old = DB::table('subscription_gateway_webhooks')->where('provider_event_id', $eventKey)->first()) {
                    abort_unless(hash_equals($old->fingerprint, $hash), 409, 'Subscription event payload mismatch.');

                    return ['accepted' => true, 'duplicate' => true, 'evidence' => 'STRIPE_TEST_CONTRACT'];
                }
                abort_unless(($object['currency'] ?? null) === strtolower($c->currency) && (int) ($object['amount'] ?? -1) === (int) $c->amount_minor, 409, 'Subscription provider amount or currency mismatch.');
                $i = $this->invoice($s->id, $c->invoice_id);
                if ($type === 'payment_intent.succeeded') {
                    abort_unless(($object['status'] ?? null) === 'succeeded' && (int) ($object['amount_received'] ?? -1) === (int) $c->amount_minor, 409, 'Subscription provider capture is not final.');
                    $operationRef = $reference.':capture';
                    $receipt = $this->subscriptions->recordProviderReceipt($s->id, $i->id, $operationRef, (int) $c->amount_minor, $c->currency, 'STRIPE_TEST_CONTRACT', null);
                    if (! DB::table('subscription_gateway_operations')->where('provider_operation_reference', $operationRef)->exists()) {
                        DB::table('subscription_gateway_operations')->insert(['checkout_id' => $c->id, 'kind' => 'capture', 'request_key' => hash('sha256', 'subscription-stripe-capture:'.$operationRef), 'fingerprint' => hash('sha256', $operationRef), 'provider_operation_reference' => $operationRef, 'amount_minor' => $c->amount_minor, 'receipt_id' => $receipt->id, 'created_at' => now()]);
                    }
                    DB::table('subscription_gateway_checkouts')->where('id', $c->id)->update(['provider_reference' => $reference, 'status' => 'captured', 'updated_at' => now()]);
                } elseif ($type === 'payment_intent.amount_capturable_updated') {
                    abort_unless(($object['status'] ?? null) === 'requires_capture' && (int) ($object['amount_capturable'] ?? -1) === (int) $c->amount_minor, 409);
                    if (in_array($c->status, ['pending', 'action_required', 'authorized'], true)) {
                        DB::table('subscription_gateway_checkouts')->where('id', $c->id)->update(['provider_reference' => $reference, 'status' => 'authorized', 'authorization_expires_at' => $c->authorization_expires_at ?? now()->addMinutes(30), 'updated_at' => now()]);
                    }
                } elseif (in_array($c->status, ['pending', 'action_required', 'authorized'], true)) {
                    DB::table('subscription_gateway_checkouts')->where('id', $c->id)->update(['provider_reference' => $reference, 'status' => 'failed', 'failure_code' => 'payment_declined', 'updated_at' => now()]);
                }
                DB::table('subscription_gateway_webhooks')->insert(['provider_event_id' => $eventKey, 'fingerprint' => $hash, 'checkout_id' => $c->id, 'kind' => $type, 'created_at' => now()]);

                return ['accepted' => true, 'duplicate' => false, 'evidence' => 'STRIPE_TEST_CONTRACT'];
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            $old = DB::table('subscription_gateway_webhooks')->where('provider_event_id', $eventKey)->first();
            if (! $old) {
                throw $e;
            }
            abort_unless(hash_equals($old->fingerprint, $hash), 409, 'Subscription event payload mismatch.');

            return ['accepted' => true, 'duplicate' => true, 'evidence' => 'STRIPE_TEST_CONTRACT'];
        }
    }

    public function reconcile(User $actor, int $id): array
    {
        $this->provider();
        $c = $this->access($actor, $id);
        $receipt = DB::table('commercial_subscription_receipts')->where('invoice_id', $c->invoice_id)->first();
        $observation = $c->provider === 'stripe_test' && str_starts_with($c->provider_reference, 'pi_') ? (new StripeSandboxProvider)->inspect($c->provider_reference) : null;
        $matched = $c->status === 'captured' ? $receipt && (int) $receipt->amount_minor === (int) $c->amount_minor && $receipt->currency === $c->currency : ! $receipt;
        if ($observation) {
            $matched = $matched && $observation['currency'] === $c->currency && $observation['captured_minor'] === ($receipt ? (int) $receipt->amount_minor : 0) && $observation['refunded_minor'] === 0;
        }

        return ['checkout' => $c, 'receipt' => $receipt, 'observation' => $observation, 'matched' => (bool) $matched, 'evidence' => $c->provider === 'stripe_test' ? 'STRIPE_TEST_CONTRACT' : 'MOCK_PROVIDER', 'external_provider_verified' => false, 'real_payment_processed' => false];
    }
}
