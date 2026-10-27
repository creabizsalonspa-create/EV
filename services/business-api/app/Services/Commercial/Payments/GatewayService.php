<?php

namespace App\Services\Commercial\Payments;

use App\Models\Finance\Invoice;
use App\Services\Finance\FinanceService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GatewayService
{
    public function __construct(private FinanceService $finance) {}

    private function provider(): PaymentProvider
    {
        abort_if(app()->environment('production'), 503, 'Payment adapters are disabled in production.');

        return match (config('payments.provider')) {
            'mock' => new MockPaymentProvider,
            'stripe_test' => new StripeSandboxProvider,
            default => abort(503, 'No approved non-production provider is configured.'),
        };
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function audit(int $actor, string $action, int $id): void
    {
        DB::table('audit_events')->insert(['actor_id' => $actor, 'action' => 'finance.gateway.'.$action, 'subject' => (string) $id, 'created_at' => now()]);
    }

    public function checkout(Invoice $invoice, int $actor, int $amount, string $key): object
    {
        $this->provider();
        abort_unless($invoice->user_id === $actor && $invoice->evidence === 'SIMULATED', 404);

        return DB::transaction(function () use ($invoice, $actor, $amount, $key) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $requestKey = $this->fingerprint(['checkout', $invoice->id, $actor, $key]);
            $fingerprint = $this->fingerprint([$invoice->id, $amount, $invoice->currency]);
            if ($old = DB::table('gateway_checkouts')->where('request_key', $requestKey)->first()) {
                abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, 'Checkout key payload mismatch.');

                return $old;
            }
            abort_if(DB::table('payment_recovery_intents as p')->join('gateway_checkouts as c', 'c.id', '=', 'p.checkout_id')->where('p.scope', 'charging')->where('c.invoice_id', $invoice->id)->whereIn('p.status', ['executing', 'uncertain', 'needs_review'])->exists(), 409, 'Resolve the prior provider outcome before opening another checkout.');
            $due = $this->finance->summary($invoice)['amount_due_minor'];
            abort_unless($amount > 0 && $amount <= $due, 409, 'Checkout exceeds the invoice balance.');
            $id = DB::table('gateway_checkouts')->insertGetId(['invoice_id' => $invoice->id, 'user_id' => $actor, 'provider' => config('payments.provider'), 'provider_reference' => 'checkout_'.Str::ulid(), 'request_key' => $requestKey, 'fingerprint' => $fingerprint, 'currency' => $invoice->currency, 'amount_minor' => $amount, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($actor, 'checkout.created', $id);

            return DB::table('gateway_checkouts')->find($id);
        }, 3);
    }

    public function operate(int $checkoutId, int $actor, string $kind, string $key, ?int $amount = null, ?string $token = null, string $reason = ''): object
    {
        $provider = $this->provider();
        $initial = DB::table('gateway_checkouts')->find($checkoutId);
        abort_unless($initial, 404);

        return app(PaymentRecovery::class)->run('charging', $initial, $actor, $kind, $key, [$kind, $amount ?? (int) $initial->amount_minor, $token, $reason], fn () => DB::transaction(function () use ($initial, $actor, $kind, $key, $amount, $token, $reason, $provider) {
            // Invoice is the serialization root, shared with legacy payments/refunds.
            $invoice = Invoice::whereKey($initial->invoice_id)->lockForUpdate()->firstOrFail();
            $checkout = DB::table('gateway_checkouts')->where('id', $initial->id)->lockForUpdate()->first();
            abort_unless($invoice->evidence === 'SIMULATED' && $checkout->provider === config('payments.provider'), 409, 'Checkout provider or invoice evidence mismatch.');
            $amount ??= (int) $checkout->amount_minor;
            $requestKey = $this->fingerprint(['operation', $checkout->id, $kind, $key]);
            $fingerprint = $this->fingerprint([$kind, $amount, $token, $reason]);
            if ($old = DB::table('gateway_operations')->where('request_key', $requestKey)->first()) {
                abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, 'Payment operation key payload mismatch.');

                return DB::table('gateway_checkouts')->find($checkout->id);
            }
            $values = ['updated_at' => now()];
            $payment = null;
            $refund = null;
            $reference = 'mock_'.$kind.'_'.$requestKey;
            if ($kind === 'authorize') {
                abort_unless($checkout->status === 'pending', 409, 'Checkout is not pending.');
                $result = $provider->authorize($checkout->provider_reference, $amount, $checkout->currency, $token ?? '');
                $values += $result + ['authorization_expires_at' => now()->addMinutes((int) config('payments.authorization_minutes', 30))];
            } elseif ($kind === 'capture') {
                if ($checkout->status === 'captured' && $amount === (int) $checkout->amount_minor && DB::table('gateway_operations')->where('checkout_id', $checkout->id)->where('kind', 'capture')->exists()) {
                    return $checkout;
                }
                abort_unless($checkout->status === 'authorized' && $checkout->authorization_expires_at && now()->lt($checkout->authorization_expires_at), 409, 'An unexpired authorization is required.');
                abort_unless($amount === (int) $checkout->amount_minor, 409, 'This adapter captures the full authorization.');
                abort_unless($amount <= $this->finance->summary($invoice)['amount_due_minor'], 409, 'Invoice balance changed before capture.');
                $result = $provider->capture($checkout->provider_reference, $amount, $requestKey);
                $payment = $this->finance->pay($invoice, $invoice->user_id, $amount, 'gateway:'.$requestKey);
                $values['status'] = 'captured';
                $reference = $result['reference'];
            } elseif ($kind === 'refund') {
                abort_unless($checkout->status === 'captured', 409, 'Capture the checkout before refunding.');
                $refunded = (int) DB::table('gateway_operations')->where('checkout_id', $checkout->id)->where('kind', 'refund')->sum('amount_minor');
                abort_unless($amount > 0 && $amount <= (int) $checkout->amount_minor - $refunded, 409, 'Refund exceeds this capture.');
                abort_unless($amount <= $this->finance->summary($invoice)['net_collected_minor'], 409, 'Refund exceeds remaining invoice funds.');
                $result = $provider->refund($checkout->provider_reference, $amount, $requestKey);
                if ($committed = DB::table('gateway_operations')->where('provider_reference', $result['reference'])->first()) {
                    abort_unless((int) $committed->checkout_id === $checkout->id && $committed->kind === 'refund' && (int) $committed->amount_minor === $amount, 409, 'Provider refund identity conflict.');

                    return $checkout;
                }
                $refund = $this->finance->refund($invoice, $actor, $amount, 'gateway:'.$requestKey, $reason);
                $reference = $result['reference'];
            } elseif ($kind === 'cancel') {
                abort_unless(in_array($checkout->status, ['pending', 'authorized', 'action_required'], true), 409, 'Only an uncaptured checkout can be cancelled.');
                if ($checkout->status !== 'pending') {
                    $provider->cancel($checkout->provider_reference, $requestKey);
                }
                $values['status'] = 'cancelled';
            } else {
                abort(422, 'Unsupported payment operation.');
            }
            DB::table('gateway_operations')->insert(['checkout_id' => $checkout->id, 'request_key' => $requestKey, 'fingerprint' => $fingerprint, 'kind' => $kind, 'amount_minor' => $amount, 'provider_reference' => $reference, 'payment_id' => $payment?->id, 'refund_id' => $refund?->id, 'created_at' => now()]);
            DB::table('gateway_checkouts')->where('id', $checkout->id)->update($values);
            $this->audit($actor, $kind, $checkout->id);

            return DB::table('gateway_checkouts')->find($checkout->id);
        }, 3));
    }

    public function webhook(string $raw, string $timestamp, string $signature): array
    {
        $this->provider();
        abort_unless(config('payments.provider') === 'mock', 409, 'Use the matching provider webhook.');
        $secret = config('payments.webhook_secret');
        abort_unless(is_string($secret) && strlen($secret) >= 32, 503, 'Configure a non-production mock webhook signing key.');
        abort_unless(ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300 && preg_match('/^[a-f0-9]{64}$/D', $signature) && hash_equals(hash_hmac('sha256', $timestamp.'.'.$raw, $secret), $signature), 401, 'Invalid or expired webhook signature.');
        abort_if(strlen($raw) > 16384, 413);
        try {
            $event = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            abort(422, 'Invalid webhook JSON.');
        }
        $v = validator(is_array($event) ? $event : [], ['event_id' => 'required|string|max:100', 'checkout_id' => 'required|integer|min:1', 'type' => 'required|in:captured,refunded', 'amount_minor' => 'required|integer|between:1,2000000000', 'currency' => ['required', 'regex:/^[A-Z]{3}$/'], 'provider_operation_reference' => 'sometimes|required|string|max:120'])->validate();
        $initial = DB::table('gateway_checkouts')->find($v['checkout_id']);
        abort_unless($initial, 404);

        $hash = $this->fingerprint([$v['checkout_id'], $v['type'], (int) $v['amount_minor'], $v['currency'], $v['provider_operation_reference'] ?? null]);
        $eventId = hash('sha256', 'mock:'.$v['event_id']);
        try {
            return DB::transaction(function () use ($initial, $v, $hash, $eventId) {
                $invoice = Invoice::whereKey($initial->invoice_id)->lockForUpdate()->firstOrFail();
                if ($old = DB::table('gateway_webhook_events')->where('provider_event_id', $eventId)->first()) {
                    abort_unless(hash_equals($old->fingerprint, $hash), 409, 'Webhook event payload mismatch.');

                    return ['accepted' => true, 'duplicate' => true, 'evidence' => 'MOCK_PROVIDER'];
                }
                abort_unless($initial->currency === $v['currency'], 409, 'Webhook currency mismatch.');
                $kind = $v['type'] === 'captured' ? 'capture' : 'refund';
                $existing = null;
                if (isset($v['provider_operation_reference'])) {
                    $existing = DB::table('gateway_operations')->where('checkout_id', $initial->id)->where('provider_reference', $v['provider_operation_reference'])->first();
                    abort_unless($existing, 409, 'Unknown provider operation reference.');
                } elseif ($kind === 'capture') {
                    $existing = DB::table('gateway_operations')->where('checkout_id', $initial->id)->where('kind', 'capture')->first();
                }
                if ($existing) {
                    abort_unless($existing->kind === $kind && (int) $existing->amount_minor === (int) $v['amount_minor'], 409, 'Provider evidence does not match the committed operation.');
                } else {
                    $this->operate($initial->id, $invoice->user_id, $kind, 'webhook:'.$v['event_id'], (int) $v['amount_minor'], null, $kind === 'refund' ? 'Mock provider refund webhook' : '');
                }
                DB::table('gateway_webhook_events')->insert(['provider_event_id' => $eventId, 'fingerprint' => $hash, 'checkout_id' => $initial->id, 'type' => $v['type'], 'created_at' => now()]);

                return ['accepted' => true, 'duplicate' => false, 'evidence' => 'MOCK_PROVIDER'];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // A provider event identifier is global, including across invoices.
            // Roll back any competing posting before resolving its committed identity.
            $old = DB::table('gateway_webhook_events')->where('provider_event_id', $eventId)->first();
            if (! $old) {
                throw $exception;
            }
            abort_unless(hash_equals($old->fingerprint, $hash), 409, 'Webhook event payload mismatch.');

            return ['accepted' => true, 'duplicate' => true, 'evidence' => 'MOCK_PROVIDER'];
        }
    }

    public function stripeWebhook(string $raw, string $signature): array
    {
        abort_unless(config('payments.provider') === 'stripe_test', 409, 'Use the matching provider webhook.');
        $event = (new StripeSandboxProvider)->verifyWebhook($raw, $signature);

        return $this->applyProviderEvidence($event, $raw);
    }

    public function recoverFromProvider(object $intent): array
    {
        $this->provider();
        abort_unless(config('payments.provider') === 'stripe_test' && $intent->scope === 'charging', 409);
        $checkout = DB::table('gateway_checkouts')->find($intent->checkout_id);
        abort_unless($checkout && $checkout->provider === 'stripe_test', 404);
        $object = (new StripeSandboxProvider)->recoveryEvidence($intent, $checkout);
        if ($intent->kind === 'cancel') {
            return app(PaymentRecovery::class)->applyCancellation($intent, $object);
        }
        $type = match ($intent->kind) {
            'refund' => 'refund.updated','capture' => 'payment_intent.succeeded','authorize' => ($object['status'] ?? '') === 'requires_capture' ? 'payment_intent.amount_capturable_updated' : 'payment_intent.payment_failed',default => null
        };
        abort_unless($type, 409, 'Cancellation requires separate final cancellation evidence.');
        abort_unless($intent->kind !== 'authorize' || in_array($object['status'] ?? '', ['requires_capture', 'requires_payment_method'], true), 409, 'Authorization outcome is not final.');
        $object['metadata']['creabiz_checkout'] = $checkout->provider_reference;
        $object['metadata']['creabiz_recovery'] = (string) $intent->id;
        $event = ['id' => 'inspection_'.hash('sha256', json_encode([$intent->id, $object], JSON_THROW_ON_ERROR)), 'type' => $type, 'data' => ['object' => $object]];

        return $this->applyProviderEvidence($event, json_encode($event, JSON_THROW_ON_ERROR)) + ['provenance' => 'TEST_PROVIDER_GET', 'external_provider_verified' => false];
    }

    private function applyProviderEvidence(array $event, string $raw): array
    {
        $type = $event['type'] ?? '';
        if (! in_array($type, ['payment_intent.succeeded', 'payment_intent.amount_capturable_updated', 'payment_intent.payment_failed', 'refund.updated'], true)) {
            return ['accepted' => true, 'ignored' => true, 'evidence' => 'STRIPE_TEST_CONTRACT'];
        }
        $object = $event['data']['object'] ?? [];
        abort_unless(is_array($object) && ($object['livemode'] ?? null) === false, 422, 'Expected a test-mode payment object.');
        $reference = $type === 'refund.updated' ? ($object['payment_intent'] ?? '') : ($object['id'] ?? '');
        abort_unless(is_string($reference) && preg_match('/^pi_[A-Za-z0-9]+$/D', $reference), 422);
        $query = DB::table('gateway_checkouts')->where('provider', 'stripe_test');
        $query->where(function ($q) use ($reference, $object) {
            $q->where('provider_reference', $reference);
            if (is_string($object['metadata']['creabiz_checkout'] ?? null)) {
                $q->orWhere('provider_reference', $object['metadata']['creabiz_checkout']);
            }
        });
        $initial = $query->first();
        abort_unless($initial, 404, 'No matching test checkout.');
        $eventId = hash('sha256', 'stripe_test:'.$event['id']);
        $hash = hash('sha256', $raw);
        try {
            return DB::transaction(function () use ($initial, $object, $type, $eventId, $hash, $reference) {
                $invoice = Invoice::whereKey($initial->invoice_id)->lockForUpdate()->firstOrFail();
                if ($old = DB::table('gateway_webhook_events')->where('provider_event_id', $eventId)->first()) {
                    abort_unless(hash_equals($old->fingerprint, $hash), 409, 'Provider event payload mismatch.');

                    return ['accepted' => true, 'duplicate' => true, 'evidence' => 'STRIPE_TEST_CONTRACT'];
                }
                $checkout = DB::table('gateway_checkouts')->where('id', $initial->id)->lockForUpdate()->first();
                abort_unless(($object['currency'] ?? null) === strtolower($checkout->currency), 409, 'Provider currency mismatch.');
                $amount = (int) ($type === 'refund.updated' ? ($object['amount'] ?? -1) : ($object['amount'] ?? -1));
                if ($type !== 'refund.updated') {
                    abort_unless($amount === (int) $checkout->amount_minor, 409, 'Provider amount mismatch.');
                }
                if ($type === 'payment_intent.amount_capturable_updated') {
                    abort_unless(($object['status'] ?? null) === 'requires_capture' && (int) ($object['amount_capturable'] ?? -1) === $amount, 409);
                    if (in_array($checkout->status, ['pending', 'action_required', 'authorized'], true)) {
                        DB::table('gateway_checkouts')->where('id', $checkout->id)->update(['provider_reference' => $reference, 'status' => 'authorized', 'authorization_expires_at' => $checkout->authorization_expires_at ?? now()->addMinutes(30), 'updated_at' => now()]);
                    }
                } elseif ($type === 'payment_intent.payment_failed') {
                    if (in_array($checkout->status, ['pending', 'action_required'], true)) {
                        DB::table('gateway_checkouts')->where('id', $checkout->id)->update(['provider_reference' => $reference, 'status' => 'failed', 'failure_code' => 'payment_declined', 'updated_at' => now()]);
                    }
                } else {
                    $kind = $type === 'refund.updated' ? 'refund' : 'capture';
                    abort_unless(($object['status'] ?? null) === 'succeeded', 409, 'Provider operation is not final.');
                    $providerReference = $kind === 'capture' ? $reference.':capture' : ($object['id'] ?? '');
                    abort_unless(is_string($providerReference) && ($kind !== 'refund' || preg_match('/^re_[A-Za-z0-9]+$/D', $providerReference)), 422);
                    $existing = DB::table('gateway_operations')->where('provider_reference', $providerReference)->first();
                    if ($existing) {
                        abort_unless((int) $existing->checkout_id === $checkout->id && $existing->kind === $kind && (int) $existing->amount_minor === $amount, 409, 'Provider operation does not reconcile.');
                    } else {
                        $payment = null;
                        $refund = null;
                        if ($kind === 'capture') {
                            abort_unless((int) ($object['amount_received'] ?? -1) === $amount, 409);
                            $payment = $this->finance->pay($invoice, $invoice->user_id, $amount, 'stripe-event:'.$eventId);
                            DB::table('gateway_checkouts')->where('id', $checkout->id)->update(['provider_reference' => $reference, 'status' => 'captured', 'updated_at' => now()]);
                        } else {
                            $priorRefunded = (int) DB::table('gateway_operations')->where('checkout_id', $checkout->id)->where('kind', 'refund')->sum('amount_minor');
                            abort_unless($checkout->status === 'captured' && $amount > 0 && $amount <= (int) $checkout->amount_minor - $priorRefunded, 409, 'Provider refund exceeds capture.');
                            $refund = $this->finance->refund($invoice, $invoice->user_id, $amount, 'stripe-event:'.$eventId, 'Verified Stripe test refund event');
                        }
                        DB::table('gateway_operations')->insert(['checkout_id' => $checkout->id, 'request_key' => hash('sha256', 'stripe-operation:'.$providerReference), 'fingerprint' => hash('sha256', $providerReference.':'.$amount), 'kind' => $kind, 'amount_minor' => $amount, 'provider_reference' => $providerReference, 'payment_id' => $payment?->id, 'refund_id' => $refund?->id, 'created_at' => now()]);
                    }
                }
                app(PaymentRecovery::class)->resolveEvidence('charging', (int) $checkout->id, $type === 'refund.updated' ? 'refund' : ($type === 'payment_intent.succeeded' ? 'capture' : 'authorize'), ['id' => $object['id'], 'amount' => $amount, 'currency' => strtoupper($object['currency']), 'recovery_id' => $object['metadata']['creabiz_recovery'] ?? null]);
                DB::table('gateway_webhook_events')->insert(['provider_event_id' => $eventId, 'fingerprint' => $hash, 'checkout_id' => $initial->id, 'type' => $type, 'created_at' => now()]);

                return ['accepted' => true, 'duplicate' => false, 'evidence' => 'STRIPE_TEST_CONTRACT'];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $old = DB::table('gateway_webhook_events')->where('provider_event_id', $eventId)->first();
            if (! $old) {
                throw $exception;
            }
            abort_unless(hash_equals($old->fingerprint, $hash), 409, 'Provider event payload mismatch.');

            return ['accepted' => true, 'duplicate' => true, 'evidence' => 'STRIPE_TEST_CONTRACT'];
        }
    }

    public function createCase(Invoice $invoice, int $actor, string $kind, int $amount, string $reason, string $key): object
    {
        $this->provider();
        abort_unless($invoice->user_id === $actor, 404);

        return DB::transaction(function () use ($invoice, $actor, $kind, $amount, $reason, $key) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $requestKey = $this->fingerprint(['case', $invoice->id, $actor, $key]);
            $fingerprint = $this->fingerprint([$kind, $amount, $reason]);
            if ($old = DB::table('gateway_cases')->where('request_key', $requestKey)->first()) {
                abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, 'Case request key payload mismatch.');

                return $old;
            }
            abort_unless($kind === 'dispute' && $amount > 0 && $amount <= $this->finance->summary($invoice)['net_collected_minor'], 409, 'Case exceeds net captured funds.');
            $id = DB::table('gateway_cases')->insertGetId(['invoice_id' => $invoice->id, 'user_id' => $actor, 'kind' => $kind, 'request_key' => $requestKey, 'fingerprint' => $fingerprint, 'amount_minor' => $amount, 'reason' => $reason, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($actor, 'case.opened', $id);

            return DB::table('gateway_cases')->find($id);
        }, 3);
    }

    public function resolveCase(int $id, int $actor, string $outcome, string $resolution): object
    {
        $this->provider();
        $initial = DB::table('gateway_cases')->find($id);
        abort_unless($initial, 404);

        return DB::transaction(function () use ($initial, $actor, $outcome, $resolution) {
            $invoice = Invoice::whereKey($initial->invoice_id)->lockForUpdate()->firstOrFail();
            $case = DB::table('gateway_cases')->where('id', $initial->id)->lockForUpdate()->first();
            if ($case->status !== 'open') {
                abort_unless($case->status === $outcome && $case->resolution === $resolution, 409, 'Case already resolved differently.');

                return $case;
            }
            abort_unless(in_array($outcome, ['approved', 'rejected'], true), 422);
            abort_unless((config('payments.provider') === 'mock' && ! DB::table('gateway_checkouts')->where('invoice_id', $invoice->id)->where('provider', '!=', 'mock')->exists()) || $outcome === 'rejected', 409, 'Approve Stripe test disputes through the captured checkout refund operation; provider dispute submission requires external integration.');
            $refund = $outcome === 'approved' ? $this->finance->refund($invoice, $actor, (int) $case->amount_minor, 'gateway-case:'.$case->id, $resolution) : null;
            DB::table('gateway_cases')->where('id', $case->id)->update(['status' => $outcome, 'resolution' => $resolution, 'resolved_by' => $actor, 'refund_id' => $refund?->id, 'updated_at' => now()]);
            $this->audit($actor, 'case.'.$outcome, $case->id);

            return DB::table('gateway_cases')->find($case->id);
        }, 3);
    }

    public function reconcile(Invoice $invoice): array
    {
        $operations = DB::table('gateway_operations as o')->join('gateway_checkouts as c', 'c.id', '=', 'o.checkout_id')->where('c.invoice_id', $invoice->id)->select('o.*')->get();
        $issues = [];
        foreach ($operations as $op) {
            if ($op->kind === 'capture') {
                $payment = DB::table('finance_payments')->find($op->payment_id);
                if (! $payment || (int) $payment->invoice_id !== $invoice->id || (int) $payment->amount_minor !== (int) $op->amount_minor) {
                    $issues[] = ['operation_id' => $op->id, 'code' => 'capture_ledger_mismatch'];
                }
            } elseif ($op->kind === 'refund') {
                $refund = DB::table('finance_refunds')->find($op->refund_id);
                if (! $refund || (int) $refund->invoice_id !== $invoice->id || (int) $refund->amount_minor !== (int) $op->amount_minor) {
                    $issues[] = ['operation_id' => $op->id, 'code' => 'refund_ledger_mismatch'];
                }
            }
        }

        $observations = [];
        foreach (DB::table('gateway_checkouts')->where('invoice_id', $invoice->id)->where('provider', 'stripe_test')->get()->filter(fn ($row) => str_starts_with($row->provider_reference, 'pi_')) as $checkout) {
            $observation = (new StripeSandboxProvider)->inspect($checkout->provider_reference);
            $captured = (int) $operations->where('checkout_id', $checkout->id)->where('kind', 'capture')->sum('amount_minor');
            $refunded = (int) $operations->where('checkout_id', $checkout->id)->where('kind', 'refund')->sum('amount_minor');
            if ($observation['currency'] !== $invoice->currency || $observation['captured_minor'] !== $captured || $observation['refunded_minor'] !== $refunded) {
                $issues[] = ['checkout_id' => $checkout->id, 'code' => 'external_test_provider_mismatch'];
            }
            $observations[] = $observation + ['checkout_id' => $checkout->id];
        }

        return ['invoice_id' => $invoice->id, 'evidence' => config('payments.provider') === 'stripe_test' ? 'STRIPE_TEST_CONTRACT' : 'MOCK_PROVIDER', 'external_provider_verified' => false, 'provider_observations' => $observations, 'operations_checked' => $operations->count(), 'matched' => $issues === [], 'issues' => $issues, 'ledger' => $this->finance->summary($invoice)];
    }
}
