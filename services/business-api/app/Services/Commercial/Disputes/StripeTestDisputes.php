<?php

namespace App\Services\Commercial\Disputes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Fixed official Stripe TEST endpoints; never a live-money adapter. */
class StripeTestDisputes
{
    public function request(string $path, array $data = [], ?string $key = null): array
    {
        $secret = config('payments.stripe_test_secret');
        abort_unless(! app()->environment('production') && is_string($secret) && preg_match('/^sk_test_[A-Za-z0-9]+$/D', $secret), 503, 'Stripe TEST dispute credentials are unavailable.');
        $client = Http::baseUrl('https://api.stripe.com/v1')->withToken($secret)->acceptJson()->asForm()->withHeaders(['Stripe-Version' => '2024-06-20'])->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(20);
        if ($key !== null) {
            $client = $client->withHeaders(['Idempotency-Key' => 'creabiz-dispute-'.$key]);
        }
        try {
            $response = $key === null ? $client->get($path, $data) : $client->post($path, $data);
        } catch (ConnectionException $error) {
            abort(503, 'Provider dispute outcome is uncertain; inspect the existing dispute before retrying.');
        }
        abort_unless($response->successful(), 503, 'Provider dispute outcome is uncertain; inspect the existing dispute before retrying.');
        $body = $response->json();
        abort_unless(is_array($body) && ($body['livemode'] ?? null) === false, 502, 'Expected TEST dispute evidence.');

        return $body;
    }

    public function dispute(string $id): array
    {
        abort_unless(preg_match('/^dp_[A-Za-z0-9]+$/D', $id), 409);
        $body = $this->request('/disputes/'.$id);
        abort_unless(($body['id'] ?? null) === $id, 502, 'Dispute identity mismatch.');

        return $body;
    }
}
