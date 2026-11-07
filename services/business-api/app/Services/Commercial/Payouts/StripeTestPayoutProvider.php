<?php

namespace App\Services\Commercial\Payouts;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/** Bank payout on an already approved/funded connected TEST account, never a transfer. */
class StripeTestPayoutProvider implements PayoutProvider
{
    private function request(string $method, string $path, object $account, array $data = [], ?string $key = null, bool $connected = true): array
    {
        $secret = config('payments.stripe_test_secret');
        abort_unless(! app()->environment('production') && is_string($secret) && preg_match('/^sk_test_[A-Za-z0-9]+$/D', $secret), 503, 'Stripe TEST credentials are required; live payouts are prohibited.');
        abort_unless(preg_match('/^acct_[A-Za-z0-9]+$/D', $account->account_reference) && preg_match('/^ba_[A-Za-z0-9]+$/D', $account->bank_reference), 409, 'Verified connected test account and bank references are required.');
        $headers = ['Stripe-Version' => '2024-06-20'];
        if ($connected) {
            $headers['Stripe-Account'] = $account->account_reference;
        }
        if ($key) {
            $headers['Idempotency-Key'] = hash('sha256', 'creabiz-payout-test:'.$key);
        }
        $client = Http::baseUrl('https://api.stripe.com/v1')->withToken($secret)->asForm()->acceptJson()->withHeaders($headers)->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(20);
        $response = $method === 'GET' ? $client->get($path, $data) : $client->post($path, $data);
        $body = $response->json();
        if ($method === 'POST' && $response->status() === 400 && in_array($body['error']['code'] ?? '', ['balance_insufficient', 'insufficient_funds', 'bank_account_unusable', 'payouts_not_allowed'], true)) {
            throw new PayoutRejected($body['error']['code']);
        }
        abort_unless($response->successful() && is_array($body) && ($body['livemode'] ?? false) === false, 503, 'Stripe test payout evidence is unavailable or invalid.');

        return $body;
    }

    public function verify(object $account): void
    {
        $body = $this->request('GET', '/accounts/'.$account->account_reference, $account, [], null, false);
        abort_unless(($body['object'] ?? null) === 'account' && ($body['id'] ?? null) === $account->account_reference && ($body['metadata']['creabiz_vendor_id'] ?? null) === (string) $account->vendor_id && ($body['payouts_enabled'] ?? null) === true && ($body['details_submitted'] ?? null) === true && in_array($body['type'] ?? '', ['custom', 'express'], true), 409, 'Connected account is not an approved, vendor-bound payout account.');
        // Retrieve the exact bank token: list truncation cannot silently select another destination.
        $bank = $this->request('GET', '/accounts/'.$account->account_reference.'/external_accounts/'.$account->bank_reference, $account, [], null, false);
        abort_unless(($bank['object'] ?? null) === 'bank_account' && ($bank['id'] ?? null) === $account->bank_reference && strtoupper($bank['currency'] ?? '') === $account->currency && in_array($bank['status'] ?? '', ['verified', 'validated'], true), 409, 'Connected bank destination is not verified for this currency.');
    }

    /** Provider-hosted collection for an already provisioned, vendor-bound TEST recipient. */
    public function onboarding(object $account, string $requestKey): array
    {
        $body = $this->request('GET', '/accounts/'.$account->account_reference, $account, [], null, false);
        abort_unless(($body['object'] ?? null) === 'account' && ($body['id'] ?? null) === $account->account_reference && ($body['metadata']['creabiz_vendor_id'] ?? null) === (string) $account->vendor_id && in_array($body['type'] ?? '', ['custom', 'express'], true), 409, 'Recipient must be an existing vendor-bound connected TEST account.');
        $base = rtrim((string) config('app.url'), '/');
        $url = parse_url($base);
        $local = app()->environment(['local', 'testing']) && in_array($url['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true);
        abort_unless(is_array($url) && isset($url['host']) && ! isset($url['user']) && ! isset($url['pass']) && (($url['scheme'] ?? '') === 'https' || ($local && ($url['scheme'] ?? '') === 'http')), 503, 'A trusted application HTTPS return origin must be configured.');
        $link = $this->request('POST', '/account_links', $account, ['account' => $account->account_reference, 'type' => 'account_onboarding', 'return_url' => $base.'/?payout_onboarding=return', 'refresh_url' => $base.'/?payout_onboarding=refresh'], 'onboarding:'.$account->id.':'.$requestKey, false);
        abort_unless(is_string($link['url'] ?? null), 502, 'Invalid hosted recipient onboarding response.');
        $target = parse_url($link['url']);
        abort_unless(($link['object'] ?? null) === 'account_link' && is_array($target) && ($target['scheme'] ?? '') === 'https' && ($target['host'] ?? '') === 'connect.stripe.com' && ! isset($target['user']) && ! isset($target['pass']) && ! isset($target['port']) && is_int($link['expires_at'] ?? null) && $link['expires_at'] > now()->timestamp && $link['expires_at'] <= now()->addHour()->timestamp, 502, 'Invalid hosted recipient onboarding response.');

        return ['url' => $link['url'], 'expires_at' => $link['expires_at'], 'evidence' => 'STRIPE_TEST_HOSTED_ONBOARDING', 'real_money_moved' => false];
    }

    public function balance(object $account): int
    {
        $body = $this->request('GET', '/balance', $account);
        abort_unless(($body['object'] ?? null) === 'balance' && ($body['livemode'] ?? null) === false && is_array($body['available'] ?? null), 502, 'A test available balance is required before payout.');
        $amount = 0;
        foreach ($body['available'] as $row) {
            if (strtoupper($row['currency'] ?? '') === $account->currency) {
                abort_unless(is_int($row['amount'] ?? null), 502);
                $amount += $row['amount'];
            }
        }

        return $amount;
    }

    public function create(object $payout, object $account, object $attempt): array
    {
        $body = $this->request('POST', '/payouts', $account, ['amount' => (int) $payout->amount_minor, 'currency' => strtolower($payout->currency), 'destination' => $account->bank_reference, 'method' => 'standard', 'metadata' => ['creabiz_payout_attempt' => $attempt->reference, 'creabiz_settlement' => (string) $payout->settlement_id]], $attempt->reference);

        return $this->normalize($body, $payout, $account, $attempt);
    }

    public function retrieve(object $payout, object $account, object $attempt): ?array
    {
        if ($attempt->provider_reference) {
            return $this->normalize($this->request('GET', '/payouts/'.$attempt->provider_reference, $account), $payout, $account, $attempt);
        }
        $starting = null;
        for ($page = 0; $page < 5; $page++) {
            $data = ['limit' => 100, 'created' => ['gte' => Carbon::parse($attempt->created_at)->subMinute()->timestamp]];
            if ($starting) {
                $data['starting_after'] = $starting;
            }
            $body = $this->request('GET', '/payouts', $account, $data);
            abort_unless(($body['object'] ?? null) === 'list' && is_array($body['data'] ?? null), 502);
            $matches = array_filter($body['data'], fn ($row) => ($row['metadata']['creabiz_payout_attempt'] ?? null) === $attempt->reference);
            abort_if(count($matches) > 1, 409, 'Multiple provider payouts require operational review.');
            if ($matches) {
                return $this->normalize(array_values($matches)[0], $payout, $account, $attempt);
            }
            if (($body['has_more'] ?? false) !== true || ! $body['data']) {
                return null;
            }
            $starting = end($body['data'])['id'];
        }
        abort(409, 'Provider search bound reached; the payout remains unresolved.');
    }

    private function normalize(array $body, object $payout, object $account, object $attempt): array
    {
        abort_unless(($body['object'] ?? null) === 'payout' && ($body['livemode'] ?? null) === false && is_string($body['id'] ?? null) && preg_match('/^po_[A-Za-z0-9]+$/D', $body['id']) && ($body['amount'] ?? null) === (int) $payout->amount_minor && strtoupper($body['currency'] ?? '') === $payout->currency && ($body['destination'] ?? null) === $account->bank_reference && ($body['metadata']['creabiz_payout_attempt'] ?? null) === $attempt->reference && ($body['metadata']['creabiz_settlement'] ?? null) === (string) $payout->settlement_id && in_array($body['status'] ?? '', ['paid', 'pending', 'in_transit', 'failed', 'canceled'], true), 409, 'Provider payout does not match its exact settlement, destination and amount.');

        return ['id' => $body['id'], 'amount' => $body['amount'], 'currency' => $payout->currency, 'status' => $body['status'], 'attempt_reference' => $attempt->reference, 'failure_code' => in_array($body['failure_code'] ?? '', ['account_closed', 'account_frozen', 'bank_account_restricted', 'bank_ownership_changed', 'could_not_process', 'debit_not_authorized', 'declined', 'insufficient_funds', 'invalid_account_number', 'incorrect_account_holder_name', 'incorrect_account_holder_address', 'incorrect_account_holder_tax_id', 'invalid_currency', 'no_account', 'unsupported_card', 'transaction_limit'], true) ? $body['failure_code'] : null];
    }
}
