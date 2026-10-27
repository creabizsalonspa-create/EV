<?php

namespace App\Services\Commercial\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Stripe's actual test-mode API contract. Never accepts a live secret key. */
class StripeSandboxProvider implements PaymentProvider
{
    private function request(string $path, array $data, string $key, string $method = 'post'): array
    {
        $secret = config('payments.stripe_test_secret');
        abort_unless(! app()->environment('production') && is_string($secret) && preg_match('/^sk_test_[A-Za-z0-9]+$/D', $secret), 503, 'Stripe test-mode credentials are not configured. Live keys are prohibited.');
        if ($method === 'post' && in_array($path, ['/payment_intents', '/refunds'], true)) {
            $data['metadata'] = ($data['metadata'] ?? []) + PaymentRecovery::metadata();
        }
        if ($method !== 'get') {
            PaymentRecovery::sent();
        }
        try {
            $response = Http::baseUrl('https://api.stripe.com/v1')->withToken($secret)->asForm()->acceptJson()
                ->withHeaders(['Stripe-Version' => '2024-06-20', 'Idempotency-Key' => hash('sha256', 'creabiz-test:'.PaymentRecovery::providerKey($key))])
                ->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(20)->{$method}($path, $data);
        } catch (ConnectionException $e) {
            abort(503, 'Provider outcome is uncertain. Retry the same operation key; do not create a replacement payment.');
        }
        if ($response->status() === 402) {
            return ['status' => 'failed', 'failure_code' => 'payment_declined'];
        }
        abort_unless($response->successful(), 503, 'Stripe test provider is unavailable; retry with the same operation key.');
        $body = $response->json();
        $list = $method === 'get' && in_array($path, ['/payment_intents/search', '/refunds'], true) && is_array($body) && is_array($body['data'] ?? null);
        if ($list) {
            foreach ($body['data'] as $item) {
                abort_unless(is_array($item) && ($item['livemode'] ?? null) === false, 502, 'Expected test-mode recovery evidence.');
            }
        }
        abort_unless(is_array($body) && (($body['livemode'] ?? null) === false || $list), 502, 'Provider did not confirm a test-mode result.');

        if ($method !== 'get') {
            PaymentRecovery::observe($body);
        }

        return $body;
    }

    public function authorize(string $reference, int $amount, string $currency, string $token): array
    {
        abort_unless(preg_match('/^pm_[A-Za-z0-9_]{1,100}$/D', $token), 422, 'Supply an opaque Stripe test PaymentMethod; never submit card details.');
        $result = $this->request('/payment_intents', ['amount' => $amount, 'currency' => strtolower($currency), 'payment_method' => $token, 'capture_method' => 'manual', 'confirm' => 'true', 'payment_method_types' => ['card'], 'metadata' => ['creabiz_checkout' => $reference]], 'authorize:'.$reference);
        if (($result['status'] ?? null) === 'failed') {
            return $result;
        }
        abort_unless(is_string($result['id'] ?? null) && preg_match('/^pi_[A-Za-z0-9]+$/D', $result['id']), 502, 'Invalid test provider payment reference.');
        abort_unless((int) ($result['amount'] ?? -1) === $amount && ($result['currency'] ?? null) === strtolower($currency), 502, 'Test provider authorization does not reconcile.');
        if (($result['status'] ?? null) === 'requires_action') {
            // Never log or persist a client secret. A hosted SDK flow is required for SCA.
            return ['status' => 'action_required', 'failure_code' => 'authentication_required', 'provider_reference' => $result['id']];
        }
        abort_unless(($result['status'] ?? null) === 'requires_capture', 409, 'Test provider authorization is not capture-ready.');

        return ['status' => 'authorized', 'failure_code' => null, 'provider_reference' => $result['id']];
    }

    public function capture(string $reference, int $amount, string $key): array
    {
        abort_unless(preg_match('/^pi_[A-Za-z0-9]+$/D', $reference), 409, 'Authorize the test payment first.');
        $result = $this->request('/payment_intents/'.$reference.'/capture', ['amount_to_capture' => $amount], 'capture:'.$key);
        abort_unless(($result['id'] ?? null) === $reference && ($result['status'] ?? null) === 'succeeded' && (int) ($result['amount_received'] ?? -1) === $amount, 409, 'Test capture is not confirmed; reconcile before posting.');

        return ['reference' => $reference.':capture', 'status' => 'captured'];
    }

    public function cancel(string $reference, string $key): array
    {
        abort_unless(preg_match('/^pi_[A-Za-z0-9]+$/D', $reference), 409);
        $result = $this->request('/payment_intents/'.$reference.'/cancel', [], 'cancel:'.$key);
        abort_unless(($result['id'] ?? null) === $reference && ($result['status'] ?? null) === 'canceled', 409, 'Test provider cancellation is not confirmed.');

        return ['status' => 'cancelled'];
    }

    public function refund(string $reference, int $amount, string $key): array
    {
        abort_unless(preg_match('/^pi_[A-Za-z0-9]+$/D', $reference), 409, 'Missing test provider capture.');
        $result = $this->request('/refunds', ['payment_intent' => $reference, 'amount' => $amount], 'refund:'.$key);
        abort_unless(is_string($result['id'] ?? null) && preg_match('/^re_[A-Za-z0-9]+$/D', $result['id']) && ($result['payment_intent'] ?? null) === $reference && (int) ($result['amount'] ?? -1) === $amount && ($result['status'] ?? null) === 'succeeded', 409, 'Test refund is not confirmed; reconcile before posting.');

        return ['reference' => $result['id'], 'status' => 'refunded'];
    }

    /** Read-only authoritative test-provider evidence; never interprets absence as permission to resend. */
    public function recoveryEvidence(object $intent, object $checkout): array
    {
        $prior = $intent->observation ? json_decode($intent->observation, true, 16, JSON_THROW_ON_ERROR) : [];
        $reference = $prior['id'] ?? $checkout->provider_reference;
        if ($intent->kind === 'refund') {
            if (is_string($reference) && preg_match('/^re_[A-Za-z0-9]+$/D', $reference)) {
                $object = $this->request('/refunds/'.$reference, [], 'read-refund:'.$intent->id, 'get');
                abort_unless(($object['id'] ?? null) === $reference, 409, 'Provider refund identity mismatch.');
            } else {
                abort_unless(preg_match('/^pi_[A-Za-z0-9]+$/D', $checkout->provider_reference), 409, 'Missing capture reference for refund recovery.');
                $list = $this->request('/refunds', ['payment_intent' => $checkout->provider_reference, 'limit' => 100], 'find-refund:'.$intent->id, 'get');
                $matches = array_values(array_filter($list['data'], fn ($row) => (string) ($row['metadata']['creabiz_recovery'] ?? '') === (string) $intent->id));
                abort_unless(count($matches) === 1, 409, 'Provider refund identity is not uniquely established; no retry was sent.');
                $object = $matches[0];
            }
            abort_unless(($object['payment_intent'] ?? null) === $checkout->provider_reference, 409, 'Refund belongs to another capture.');
        } elseif (is_string($reference) && preg_match('/^pi_[A-Za-z0-9]+$/D', $reference)) {
            $object = $this->request('/payment_intents/'.$reference, [], 'read-intent:'.$intent->id, 'get');
            abort_unless(($object['id'] ?? null) === $reference, 409, 'Provider payment identity mismatch.');
        } else {
            $list = $this->request('/payment_intents/search', ['query' => "metadata['creabiz_recovery']:'".(int) $intent->id."'", 'limit' => 100], 'find-intent:'.$intent->id, 'get');
            $matches = array_values(array_filter($list['data'], fn ($row) => (string) ($row['metadata']['creabiz_recovery'] ?? '') === (string) $intent->id));
            abort_unless(count($matches) === 1, 409, 'Provider payment identity is not uniquely established; no retry was sent.');
            $object = $matches[0];
        }
        abort_unless(is_string($object['id'] ?? null) && preg_match($intent->kind === 'refund' ? '/^re_[A-Za-z0-9]+$/D' : '/^pi_[A-Za-z0-9]+$/D', $object['id']), 409, 'Invalid provider recovery identity.');
        abort_unless((int) ($object['amount'] ?? -1) === (int) $intent->amount_minor && strtoupper($object['currency'] ?? '') === $intent->currency, 409, 'Recovery evidence amount or currency mismatch.');

        return array_intersect_key($object, array_flip(['id', 'livemode', 'status', 'amount', 'amount_received', 'amount_capturable', 'currency', 'payment_intent', 'metadata']));
    }

    public function inspect(string $reference): array
    {
        abort_unless(preg_match('/^pi_[A-Za-z0-9]+$/D', $reference), 409, 'Test checkout is not externally authorized.');
        $result = $this->request('/payment_intents/'.$reference, ['expand' => ['latest_charge']], 'inspect:'.$reference, 'get');
        abort_unless(($result['id'] ?? null) === $reference && is_string($result['currency'] ?? null), 502, 'Invalid provider reconciliation response.');
        $charge = $result['latest_charge'] ?? null;
        abort_unless(is_array($charge) || $charge === null, 502, 'Provider did not expand reconciliation evidence.');

        return ['reference' => $reference, 'status' => $result['status'] ?? 'unknown', 'currency' => strtoupper($result['currency']), 'captured_minor' => (int) ($result['amount_received'] ?? 0), 'refunded_minor' => (int) ($charge['amount_refunded'] ?? 0), 'evidence' => 'STRIPE_TEST_RESPONSE'];
    }

    /** Verify Stripe's timestamped raw-body signature before interpreting any event. */
    public function verifyWebhook(string $raw, string $signature): array
    {
        abort_if(app()->environment('production'), 503);
        $secret = config('payments.stripe_test_webhook_secret');
        abort_unless(is_string($secret) && str_starts_with($secret, 'whsec_') && strlen($secret) >= 32, 503, 'Stripe test webhook signing key is missing.');
        preg_match('/(?:^|,)t=([0-9]+)(?:,|$)/', $signature, $time);
        preg_match_all('/(?:^|,)v1=([a-f0-9]{64})(?=,|$)/', $signature, $signatures);
        $timestamp = $time[1] ?? '';
        $expected = hash_hmac('sha256', $timestamp.'.'.$raw, $secret);
        $valid = false;
        foreach ($signatures[1] as $candidate) {
            $valid = hash_equals($expected, $candidate) || $valid;
        }
        abort_unless($timestamp !== '' && abs(time() - (int) $timestamp) <= 300 && $valid, 401, 'Invalid or expired Stripe webhook signature.');
        abort_if(strlen($raw) > 65536, 413);
        try {
            $event = json_decode($raw, true, 24, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            abort(422, 'Invalid Stripe event JSON.');
        }
        abort_unless(is_array($event) && ($event['livemode'] ?? null) === false && is_string($event['id'] ?? null) && str_starts_with($event['id'], 'evt_'), 422, 'Expected a test-mode provider event.');

        return $event;
    }
}
