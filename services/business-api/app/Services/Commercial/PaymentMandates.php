<?php

namespace App\Services\Commercial;

use App\Models\User;
use App\Services\Commercial\Payments\StripeSandboxProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Provider-owned consent evidence; opaque identifiers are encrypted, never client proof. */
class PaymentMandates
{
    public function provider(): StripeSandboxProvider
    {
        abort_unless(! app()->environment('production') && config('payments.provider') === 'stripe_test', 503, 'Hosted test-provider enrollment is not configured.');

        return new StripeSandboxProvider;
    }

    public function returnUrl(): string
    {
        $url = config('payments.hosted_return_url');
        $p = is_string($url) ? parse_url($url) : false;
        abort_unless($p && ($p['scheme'] ?? '') === 'https' && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['fragment']) && ! empty($p['host']), 503, 'Configure the trusted HTTPS payment return URL.');

        return $url;
    }

    public function hostedUrl(?string $url, array $hosts = ['checkout.stripe.com', 'hooks.stripe.com']): ?string
    {
        if ($url === null) {
            return null;
        }
        $p = parse_url($url);
        abort_unless($p && ($p['scheme'] ?? '') === 'https' && in_array($p['host'] ?? '', $hosts, true) && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['port']), 502, 'Untrusted provider action URL.');

        return $url;
    }

    public function event(int $user, string $subject, string $kind, ?string $providerEvent = null, ?string $fingerprint = null): void
    {
        DB::table('recurring_payment_events')->insert(['user_id' => $user, 'subject' => $subject, 'kind' => $kind, 'provider_event' => $providerEvent, 'fingerprint' => $fingerprint, 'created_at' => now()]);
    }

    public function begin(User $user, string $key, string $consent): array
    {
        $provider = $this->provider();
        $return = $this->returnUrl();
        abort_unless(in_array($user->role, ['customer', 'vendor'], true), 403);
        abort_unless($consent === config('payments.mandate_consent_version'), 409, 'Refresh the current consent terms.');
        $enrollment = DB::transaction(function () use ($user, $key, $consent) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $account = DB::table('payment_customer_accounts')->where('user_id', $user->id)->first();
            if (! $account) {
                $id = DB::table('payment_customer_accounts')->insertGetId(['user_id' => $user->id, 'reference' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
                $account = DB::table('payment_customer_accounts')->find($id);
            }
            $k = hash('sha256', $user->id.':'.$key);
            if ($old = DB::table('payment_enrollments')->where('request_key', $k)->first()) {
                abort_unless($old->consent_version === $consent, 409);

                return $old;
            }
            abort_if(DB::table('payment_enrollments')->where('user_id', $user->id)->whereIn('status', ['prepared', 'executing', 'uncertain', 'pending'])->exists(), 409, 'Refresh the existing enrollment before starting another.');
            $id = DB::table('payment_enrollments')->insertGetId(['account_id' => $account->id, 'user_id' => $user->id, 'reference' => (string) Str::uuid(), 'request_key' => $k, 'consent_version' => $consent, 'consented_at' => now(), 'consent_expires_at' => now()->addDays((int) config('payments.mandate_consent_days', 365)), 'expires_at' => now()->addMinutes(30), 'status' => 'prepared', 'created_at' => now(), 'updated_at' => now()]);
            $this->event($user->id, 'enrollment:'.$id, 'consent_recorded');

            return DB::table('payment_enrollments')->find($id);
        }, 3);
        if ($enrollment->status === 'completed') {
            return $this->enrollmentDto($enrollment);
        }
        $lease = Str::random(48);
        DB::transaction(function () use ($enrollment, $lease) {
            $e = DB::table('payment_enrollments')->where('id', $enrollment->id)->lockForUpdate()->first();
            abort_if($e->lease_until && CarbonImmutable::parse($e->lease_until)->isFuture(), 409, 'Enrollment is executing; refresh status.');
            abort_unless(CarbonImmutable::parse($e->expires_at)->isFuture(), 409, 'Enrollment expired; inspect its provider outcome before replacing it.');
            DB::table('payment_enrollments')->where('id', $e->id)->update(['lease' => $lease, 'lease_until' => now()->addMinutes(3), 'status' => 'executing', 'updated_at' => now()]);
        });
        try {
            $a = DB::table('payment_customer_accounts')->find($enrollment->account_id);
            if (! $a->provider_customer) {
                $customer = $provider->createCustomer($a->reference);
                $this->customerEvidence($a, $customer);
                DB::table('payment_customer_accounts')->where('id', $a->id)->update(['provider_customer' => Crypt::encryptString($customer['id']), 'updated_at' => now()]);
                $a = DB::table('payment_customer_accounts')->find($a->id);
            }
            $customer = Crypt::decryptString($a->provider_customer);
            $session = $provider->hostedEnrollment($customer, $enrollment->reference, CarbonImmutable::parse($enrollment->expires_at)->timestamp, $return);
            $this->sessionEvidence($enrollment, $customer, $session);
            $changed = DB::table('payment_enrollments')->where('id', $enrollment->id)->where('lease', $lease)->update(['provider_session' => Crypt::encryptString($session['id']), 'session_hash' => hash('sha256', $session['id']), 'status' => 'pending', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
            abort_unless($changed, 409, 'Refresh enrollment after a lost lease.');

            return $this->enrollmentDto(DB::table('payment_enrollments')->find($enrollment->id)) + ['hosted_url' => $this->hostedUrl($session['url'] ?? null)];
        } catch (\Throwable $e) {
            DB::table('payment_enrollments')->where('id', $enrollment->id)->where('lease', $lease)->update(['status' => 'uncertain', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
            throw $e;
        }
    }

    private function customerEvidence(object $account, array $c): void
    {
        abort_unless(($c['livemode'] ?? null) === false && is_string($c['id'] ?? null) && preg_match('/^cus_[A-Za-z0-9]+$/D', $c['id']) && ($c['metadata']['creabiz_account'] ?? null) === $account->reference, 409, 'Provider customer ownership does not reconcile.');
    }

    private function sessionEvidence(object $e, string $customer, array $s): void
    {
        abort_unless(($s['livemode'] ?? null) === false && is_string($s['id'] ?? null) && preg_match('/^cs_test_[A-Za-z0-9]+$/D', $s['id']) && ($s['mode'] ?? null) === 'setup' && ($s['customer'] ?? null) === $customer && ($s['client_reference_id'] ?? null) === $e->reference && ($s['metadata']['creabiz_enrollment'] ?? null) === $e->reference, 409, 'Enrollment provider identity does not reconcile.');
        if ($e->session_hash) {
            abort_unless(hash_equals($e->session_hash, hash('sha256', $s['id'])), 409, 'Enrollment session identity changed.');
        }
    }

    public function access(User $user, int $id): object
    {
        $e = DB::table('payment_enrollments')->where('id', $id)->where('user_id', $user->id)->first();
        abort_unless($e, 404);

        return $e;
    }

    public function inspect(User $user, int $id): array
    {
        $e = $this->access($user, $id);
        $provider = $this->provider();
        if ($e->status === 'completed') {
            return $this->enrollmentDto($e);
        }
        abort_if($e->lease_until && CarbonImmutable::parse($e->lease_until)->isFuture(), 409, 'Enrollment is executing; refresh later.');
        $a = DB::table('payment_customer_accounts')->find($e->account_id);
        if (! $a->provider_customer) {
            $found = $provider->findCustomer($a->reference);
            $matches = array_values(array_filter($found['data'] ?? [], fn ($r) => ($r['metadata']['creabiz_account'] ?? null) === $a->reference));
            abort_unless(($found['has_more'] ?? null) === false && count($matches) === 1, 409, 'Provider customer outcome is not uniquely established.');
            $this->customerEvidence($a, $matches[0]);
            DB::table('payment_customer_accounts')->where('id', $a->id)->update(['provider_customer' => Crypt::encryptString($matches[0]['id']), 'updated_at' => now()]);
            $a = DB::table('payment_customer_accounts')->find($a->id);
        }
        $customer = Crypt::decryptString($a->provider_customer);
        $response = $provider->readEnrollment($e->provider_session ? Crypt::decryptString($e->provider_session) : null, $customer);
        if (! $e->provider_session) {
            $matches = array_values(array_filter($response['data'] ?? [], fn ($r) => ($r['metadata']['creabiz_enrollment'] ?? null) === $e->reference));
            abort_unless(($response['has_more'] ?? null) === false && count($matches) === 1, 409, 'Provider enrollment outcome is not uniquely established.');
            $response = $matches[0];
        }
        $this->sessionEvidence($e, $customer, $response);
        $url = $this->hostedUrl($response['url'] ?? null);
        if (($response['status'] ?? null) === 'expired') {
            DB::table('payment_enrollments')->where('id', $id)->whereNotIn('status', ['completed', 'executing'])->update(['status' => 'expired', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
        } elseif (($response['status'] ?? null) === 'complete') {
            $setup = $response['setup_intent'] ?? null;
            abort_unless(is_array($setup) && ($setup['livemode'] ?? null) === false && ($setup['customer'] ?? null) === $customer && ($setup['status'] ?? null) === 'succeeded' && ($setup['usage'] ?? null) === 'off_session' && ($setup['metadata']['creabiz_enrollment'] ?? null) === $e->reference && is_string($setup['id'] ?? null) && preg_match('/^seti_[A-Za-z0-9]+$/D', $setup['id']) && is_string($setup['payment_method'] ?? null), 409, 'Authenticated off-session consent is not complete.');
            $method = $provider->method($setup['payment_method']);
            abort_unless(($method['id'] ?? null) === $setup['payment_method'] && ($method['customer'] ?? null) === $customer && ($method['type'] ?? null) === 'card' && preg_match('/^[0-9]{4}$/D', $method['card']['last4'] ?? '') && is_int($method['card']['exp_month'] ?? null) && is_int($method['card']['exp_year'] ?? null) && ($method['card']['exp_month'] ?? 0) >= 1 && $method['card']['exp_month'] <= 12 && ($method['card']['exp_year'] ?? 0) >= now()->year && CarbonImmutable::create($method['card']['exp_year'], $method['card']['exp_month'], 1)->addMonth()->isFuture(), 409, 'Payment method ownership or expiry does not reconcile.');
            DB::transaction(function () use ($user, $e, $response, $setup, $method) {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $current = DB::table('payment_enrollments')->where('id', $e->id)->lockForUpdate()->first();
                if ($current->status === 'completed') {
                    return;
                }
                abort_unless(CarbonImmutable::parse($current->consent_expires_at)->isFuture(), 409, 'Enrollment consent expired.');
                $hash = hash('sha256', $method['id']);
                $old = DB::table('payment_mandates')->where('user_id', $user->id)->where('method_hash', $hash)->first();
                abort_if($old && $old->status === 'revoked' && $old->detach_status !== 'detached', 409, 'Reconcile removal before enrolling that method again.');
                $values = ['account_id' => $e->account_id, 'user_id' => $user->id, 'enrollment_id' => $e->id, 'provider_method' => Crypt::encryptString($method['id']), 'provider_setup' => Crypt::encryptString($setup['id']), 'method_hash' => $hash, 'brand' => substr($method['card']['brand'] ?? 'card', 0, 30), 'last_four' => $method['card']['last4'], 'exp_month' => $method['card']['exp_month'], 'exp_year' => $method['card']['exp_year'], 'consent_version' => $e->consent_version, 'consented_at' => $e->consented_at, 'consent_expires_at' => $e->consent_expires_at, 'status' => 'active', 'version' => ($old->version ?? 0) + 1, 'detach_status' => 'not_requested', 'revoked_at' => null, 'updated_at' => now()];
                if ($old) {
                    DB::table('payment_mandates')->where('id', $old->id)->update($values);
                    $methodId = $old->id;
                } else {
                    $methodId = DB::table('payment_mandates')->insertGetId($values + ['created_at' => now()]);
                }
                $account = DB::table('payment_customer_accounts')->where('id', $e->account_id)->lockForUpdate()->first();
                if (! $account->default_method_id) {
                    DB::table('payment_customer_accounts')->where('id', $account->id)->update(['default_method_id' => $methodId, 'version' => $account->version + 1, 'updated_at' => now()]);
                }
                DB::table('payment_enrollments')->where('id', $e->id)->update(['provider_session' => Crypt::encryptString($response['id']), 'session_hash' => hash('sha256', $response['id']), 'status' => 'completed', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
                $this->event($user->id, 'mandate:'.$methodId, 'provider_consent_verified');
            }, 3);
            $url = null;
        } else {
            DB::table('payment_enrollments')->where('id', $id)->whereNotIn('status', ['completed', 'executing'])->update(['provider_session' => Crypt::encryptString($response['id']), 'session_hash' => hash('sha256', $response['id']), 'status' => 'pending', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
        }

        return $this->enrollmentDto(DB::table('payment_enrollments')->find($id)) + ['hosted_url' => $url];
    }

    public function enrollmentDto(object $e): array
    {
        return array_intersect_key((array) $e, array_flip(['id', 'status', 'consent_version', 'consented_at', 'consent_expires_at', 'expires_at', 'created_at'])) + ['evidence' => 'STRIPE_TEST_CONTRACT', 'external_provider_verified' => false];
    }

    public function method(User $user, int $id, bool $lock = false): object
    {
        $q = DB::table('payment_mandates')->where('id', $id)->where('user_id', $user->id);
        $m = ($lock ? $q->lockForUpdate() : $q)->first();
        abort_unless($m, 404);

        return $m;
    }

    public function usable(object $m): bool
    {
        return $m->status === 'active' && $m->consent_version === config('payments.mandate_consent_version') && CarbonImmutable::parse($m->consent_expires_at)->isFuture() && CarbonImmutable::create($m->exp_year, $m->exp_month, 1)->addMonth()->isFuture();
    }

    public function credentials(User $user, int $id, int $version): array
    {
        $m = $this->method($user, $id, true);
        abort_unless($m->version === $version && $this->usable($m), 409, 'A current verified mandate is required.');
        $a = DB::table('payment_customer_accounts')->where('id', $m->account_id)->where('user_id', $user->id)->lockForUpdate()->first();
        abort_unless($a && $a->provider_customer, 409);

        return ['method' => Crypt::decryptString($m->provider_method), 'customer' => Crypt::decryptString($a->provider_customer)];
    }

    public function setDefault(User $user, int $id, int $expected): void
    {
        DB::transaction(function () use ($user, $id, $expected) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $m = $this->method($user, $id, true);
            $this->credentials($user, $id, $m->version);
            $a = DB::table('payment_customer_accounts')->where('id', $m->account_id)->lockForUpdate()->first();
            abort_unless($a->version === $expected, 409, 'Payment preferences changed; refresh first.');
            DB::table('payment_customer_accounts')->where('id', $a->id)->update(['default_method_id' => $id, 'version' => $a->version + 1, 'updated_at' => now()]);
            $this->event($user->id, 'mandate:'.$id, 'default_selected');
        }, 3);
    }

    public function revoke(User $user, int $id, int $expected): void
    {
        DB::transaction(function () use ($user, $id, $expected) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $m = $this->method($user, $id, true);
            if ($m->status === 'revoked') {
                return;
            }
            abort_unless($m->version === $expected, 409, 'Payment method changed; refresh first.');
            DB::table('payment_mandates')->where('id', $id)->update(['status' => 'revoked', 'version' => $m->version + 1, 'revoked_at' => now(), 'detach_status' => 'pending', 'updated_at' => now()]);
            DB::table('payment_customer_accounts')->where('id', $m->account_id)->where('default_method_id', $id)->update(['default_method_id' => null, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            DB::table('subscription_collection_policies')->where('user_id', $user->id)->where('mandate_id', $id)->where('enabled', true)->update(['enabled' => false, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            $this->event($user->id, 'mandate:'.$id, 'revoked');
        }, 3);
    }

    public function detach(User $user, int $id): void
    {
        $m = $this->method($user, $id);
        abort_unless($m->status === 'revoked', 409);
        if ($m->detach_status === 'detached') {
            return;
        }
        $r = $this->provider()->method(Crypt::decryptString($m->provider_method));
        $a = DB::table('payment_customer_accounts')->find($m->account_id);
        abort_unless(array_key_exists('customer', $r) && ($r['id'] ?? null) === Crypt::decryptString($m->provider_method) && (($r['customer'] ?? null) === null || $r['customer'] === Crypt::decryptString($a->provider_customer)), 409);
        if (($r['customer'] ?? null) !== null) {
            $r = $this->provider()->detachMethod(Crypt::decryptString($m->provider_method), $m->version);
        }
        abort_unless(($r['id'] ?? null) === Crypt::decryptString($m->provider_method) && ($r['customer'] ?? null) === null, 409);
        DB::table('payment_mandates')->where('id', $id)->where('version', $m->version)->where('status', 'revoked')->update(['detach_status' => 'detached', 'updated_at' => now()]);
    }
}
