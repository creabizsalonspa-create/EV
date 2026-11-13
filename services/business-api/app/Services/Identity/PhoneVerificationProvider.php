<?php

namespace App\Services\Identity;

use Illuminate\Support\Facades\Http;

final class PhoneVerificationProvider
{
    public function enabled(): bool
    {
        return config('phone_verification.enabled') === true
            && preg_match('/^AC[0-9a-fA-F]{32}$/D', (string) config('phone_verification.account_sid'))
            && preg_match('/^VA[0-9a-fA-F]{32}$/D', (string) config('phone_verification.service_sid'))
            && strlen((string) config('phone_verification.auth_token')) >= 16;
    }

    private function send(string $resource, array $fields): array
    {
        abort_unless($this->enabled(), 503, 'Phone verification delivery is unavailable.');
        // Fixed TLS origin, bounded timeout, no redirect or automatic SMS retry.
        $response = Http::asForm()->withBasicAuth(config('phone_verification.account_sid'), config('phone_verification.auth_token'))
            ->connectTimeout(3)->timeout(10)->withoutRedirecting()
            ->post('https://verify.twilio.com/v2/Services/'.config('phone_verification.service_sid').'/'.$resource, $fields);
        if (! $response->successful() || ! is_array($data = $response->json())) {
            throw new \RuntimeException('Verification provider did not confirm the request.');
        }
        if (($data['account_sid'] ?? null) !== config('phone_verification.account_sid')
            || ($data['service_sid'] ?? null) !== config('phone_verification.service_sid')
            || ($data['channel'] ?? null) !== 'sms'
            || ! preg_match('/^VE[0-9a-fA-F]{32}$/D', (string) ($data['sid'] ?? ''))) {
            throw new \RuntimeException('Verification provider response does not match the configured service.');
        }

        return $data;
    }

    public function start(string $phone, string $locale): string
    {
        $data = $this->send('Verifications', ['To' => $phone, 'Channel' => 'sms', 'Locale' => $locale]);
        if (($data['to'] ?? null) !== $phone || ($data['status'] ?? null) !== 'pending') {
            throw new \RuntimeException('Verification delivery was not confirmed.');
        }

        return $data['sid'];
    }

    public function check(string $sid, string $phone, string $code): bool
    {
        $data = $this->send('VerificationCheck', ['VerificationSid' => $sid, 'Code' => $code]);
        if ($data['sid'] !== $sid || ($data['to'] ?? null) !== $phone) {
            throw new \RuntimeException('Verification response identity mismatch.');
        }

        return ($data['status'] ?? null) === 'approved';
    }
}
