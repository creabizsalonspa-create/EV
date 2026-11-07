<?php

namespace App\Services\Commercial\Payments;

use App\Models\Finance\Invoice;
use App\Models\User;
use App\Services\Commercial\SubscriptionGateway;
use App\Services\Finance\FinanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/** Operational checkout identity only: canonical invoice/receipt/journals remain authoritative. */
class HostedCheckout
{
    private static ?int $allowed = null;

    public static function within(int $id, callable $action): mixed
    {
        $prior = self::$allowed;
        self::$allowed = $id;
        try {
            return $action();
        } finally {
            self::$allowed = $prior;
        }
    }

    public static function guard(string $scope, int $invoice, ?int $checkout = null): void
    {
        if (! Schema::hasTable('hosted_payment_checkouts')) {
            return;
        }
        $q = DB::table('hosted_payment_checkouts')->where('scope', $scope)->where('invoice_id', $invoice)->whereNotIn('status', ['captured', 'cancelled', 'expired']);
        foreach ($q->get() as $row) {
            abort_unless((int) $row->id === self::$allowed && ($checkout === null || (int) $row->canonical_checkout_id === $checkout), 409, 'An existing hosted checkout holds this invoice. Inspect its original state before another payment.');
        }
    }

    private function configuration(string $provider): string
    {
        return hash('sha256', $provider === 'mock' ? 'creabiz-hosted:mock:v1' : 'creabiz-hosted:stripe_test:'.(string) config('payments.stripe_test_secret'));
    }

    private function assertConfiguration(object $h): void
    {
        abort_unless($h->provider === config('payments.provider') && is_string($h->provider_configuration) && hash_equals($h->provider_configuration, $this->configuration($h->provider)), 503, 'Restore the original approved provider configuration before operating this checkout.');
    }

    public static function guardCanonicalConfiguration(string $scope, int $checkout): void
    {
        if (! Schema::hasTable('hosted_payment_checkouts')) {
            return;
        }
        $h = DB::table('hosted_payment_checkouts')->where('scope', $scope)->where('canonical_checkout_id', $checkout)->first();
        if ($h) {
            (new self)->assertConfiguration($h);
        }
    }

    private function enabled(): void
    {
        abort_if(app()->environment('production'), 503, 'Hosted payment adapters are not approved for production.');
        abort_unless(in_array(config('payments.provider'), ['mock', 'stripe_test'], true), 503);
        if (config('payments.provider') === 'mock') {
            abort_unless(app()->environment('local', 'testing'), 503, 'Mock hosted checkout is available only in local/testing.');
        }
        if (config('payments.provider') === 'stripe_test') {
            abort_unless(is_string(config('payments.stripe_test_secret')) && preg_match('/^sk_test_[A-Za-z0-9]+$/D', config('payments.stripe_test_secret')), 503, 'Configure an approved Stripe TEST key before opening a checkout.');
        }
    }

    private function root(string $scope, int $invoice, ?int $subscription = null): object
    {
        if ($scope === 'charging') {
            return DB::table('finance_invoices')->where('id', $invoice)->lockForUpdate()->firstOrFail();
        }
        $hint = DB::table('commercial_subscriptions')->find($subscription);
        abort_unless($hint, 404);
        User::whereKey($hint->user_id)->lockForUpdate()->firstOrFail();
        DB::table('commercial_subscriptions')->where('id', $subscription)->lockForUpdate()->firstOrFail();

        return DB::table('commercial_subscription_invoices')->where('id', $invoice)->where('subscription_id', $subscription)->lockForUpdate()->firstOrFail();
    }

    private function table(object $h): string
    {
        return $h->scope === 'charging' ? 'gateway_checkouts' : 'subscription_gateway_checkouts';
    }

    private function event(int $id, string $kind): void
    {
        DB::table('hosted_payment_events')->insert(['hosted_checkout_id' => $id, 'kind' => $kind, 'created_at' => now()]);
    }

    public function access(User $actor, int $id, bool $owner = false): object
    {
        $h = DB::table('hosted_payment_checkouts')->find($id);
        abort_unless($h, 404);
        $ok = (int) $h->user_id === $actor->id;
        if (! $owner && $actor->role === 'admin') {
            $ok = true;
        }
        if (! $owner && $actor->role === 'vendor' && $h->scope === 'charging') {
            $ok = $ok || DB::table('finance_invoices')->where('id', $h->invoice_id)->where('vendor_id', $actor->id)->exists();
        }
        abort_unless($ok, 404);

        return $h;
    }

    public function dto(object $h, User $actor): array
    {
        $c = DB::table($this->table($h))->find($h->canonical_checkout_id);
        $own = (int) $h->user_id === $actor->id;
        $active = ! in_array($h->status, ['captured', 'cancelled', 'expired'], true);
        $available = ! app()->environment('production') && $h->provider === config('payments.provider') && is_string($h->provider_configuration) && hash_equals($h->provider_configuration, $this->configuration($h->provider)) && ($h->provider !== 'mock' || app()->environment('local', 'testing'));
        $actions = $available ? ($own && $active ? ['inspect', 'cancel'] : ($own ? ['inspect'] : ($actor->role === 'admin' ? ['inspect'] : []))) : [];
        if ($available && $own && $h->status === 'authorized' && $c->status === 'authorized' && $c->authorization_expires_at && now()->lt($c->authorization_expires_at)) {
            $actions[] = 'capture';
        }
        $pending = DB::table('payment_recovery_intents')->where('scope', $h->scope)->where('checkout_id', $h->canonical_checkout_id)->whereIn('status', ['executing', 'uncertain', 'needs_review'])->orderByDesc('id')->first();
        if ($pending) {
            $actions = array_values(array_diff($actions, ['cancel', ...($pending->kind === 'capture' && $pending->status === 'uncertain' ? [] : ['capture'])]));
        }
        $effective = $pending && $h->status !== 'captured' ? 'uncertain' : $h->status;
        $retryPreparation = $available && $own && ! $h->provider_session && in_array($h->status, ['preparing', 'uncertain', 'needs_review'], true) && now()->lt($h->expires_at) && (! $h->lease_until || now()->gte($h->lease_until));
        if ($retryPreparation) {
            $actions[] = 'retry_preparation';
        }
        $mock = $h->provider === 'mock';

        return ['id' => (int) $h->id, 'reference' => $h->reference, 'user_id' => (int) $h->user_id, 'scope' => $h->scope, 'invoice_id' => (int) $h->invoice_id, 'subscription_id' => $h->subscription_id ? (int) $h->subscription_id : null, 'canonical_checkout_id' => (int) $h->canonical_checkout_id, 'status' => $effective, 'hosted_status' => $h->status, 'recovery_status' => $pending?->status, 'recovery_operation' => $pending?->kind, 'canonical_status' => $c->status, 'amount_minor' => (int) $h->amount_minor, 'currency' => $h->currency, 'minor_units' => (int) $h->minor_units, 'expires_at' => CarbonImmutable::parse($h->expires_at, 'UTC')->toIso8601String(), 'created_at' => CarbonImmutable::parse($h->created_at, 'UTC')->toIso8601String(), 'updated_at' => CarbonImmutable::parse($h->updated_at, 'UTC')->toIso8601String(), 'hosted_url' => $available && $own && $h->hosted_url && $h->status === 'open' ? Crypt::decryptString($h->hosted_url) : null, 'simulation_url' => $available && $own && $mock && $h->status === 'open' ? '/payments/hosted-simulation/'.$h->id : null, 'evidence' => $mock ? 'MOCK_HOSTED' : 'STRIPE_TEST_CONTRACT', 'real_money_moved' => false, 'external_provider_verified' => false, 'owner' => $own, 'allowed_actions' => $actions, 'actions' => $actions, 'can_retry_preparation' => $retryPreparation, 'can_inspect' => in_array('inspect', $actions), 'can_capture' => in_array('capture', $actions), 'can_cancel' => in_array('cancel', $actions), 'can_simulate' => $available && $own && $mock && $h->status === 'open', 'can_retry' => $available && $own && $this->retryable($h)];
    }

    private function retryable(object $h): bool
    {
        if (! in_array($h->status, ['cancelled', 'expired'], true)) {
            return false;
        }
        if (DB::table('hosted_payment_checkouts')->where('scope', $h->scope)->where('invoice_id', $h->invoice_id)->whereNotIn('status', ['captured', 'cancelled', 'expired'])->exists()) {
            return false;
        }
        if (DB::table('payment_recovery_intents as p')->join($this->table($h).' as c', 'c.id', '=', 'p.checkout_id')->where('p.scope', $h->scope)->where('c.invoice_id', $h->invoice_id)->whereIn('p.status', ['executing', 'uncertain', 'needs_review'])->exists()) {
            return false;
        }
        if (DB::table($this->table($h))->where('invoice_id', $h->invoice_id)->whereIn('status', ['pending', 'authorized', 'action_required'])->exists()) {
            return false;
        }
        if ($h->scope === 'charging') {
            if (DB::table('finance_payments')->where('invoice_id', $h->invoice_id)->exists()) {
                return false;
            }

            return app(FinanceService::class)->summary(Invoice::findOrFail($h->invoice_id))['amount_due_minor'] > 0;
        }
        $s = DB::table('commercial_subscriptions')->find($h->subscription_id);
        $i = DB::table('commercial_subscription_invoices')->find($h->invoice_id);

        return $s && $i && $s->status !== 'cancelled' && (int) $i->cycle_number === (int) $s->cycle_number + 1 && ! DB::table('commercial_subscription_receipts')->where('invoice_id', $i->id)->exists();
    }

    public function create(User $actor, array $v): array
    {
        $this->enabled();
        $key = hash('sha256', $actor->id.':'.$v['idempotency_key']);
        $fp = hash('sha256', json_encode([$v['scope'], (int) $v['invoice_id'], isset($v['subscription_id']) ? (int) $v['subscription_id'] : null], JSON_THROW_ON_ERROR));
        $h = DB::transaction(function () use ($actor, $v, $key, $fp) {
            $i = $this->root($v['scope'], (int) $v['invoice_id'], isset($v['subscription_id']) ? (int) $v['subscription_id'] : null);
            $user = $v['scope'] === 'charging' ? $i->user_id : DB::table('commercial_subscriptions')->find($v['subscription_id'])->user_id;
            abort_unless((int) $user === $actor->id, 404);
            if ($old = DB::table('hosted_payment_checkouts')->where('request_key', $key)->first()) {
                abort_unless(hash_equals($old->fingerprint, $fp), 409, 'Hosted checkout key payload changed.');

                return $old;
            }
            self::guard($v['scope'], (int) $i->id);
            $minor = (int) $i->minor_units;
            $currency = $i->currency;
            abort_unless(preg_match('/^[A-Z]{3}$/D', $currency) && in_array($minor, [0, 2, 3], true), 409, 'Unsupported provider currency precision.');
            if (config('payments.provider') === 'stripe_test') {
                abort_unless(in_array($currency, ['AED', 'USD', 'EUR', 'GBP', 'SAR', 'QAR', 'JPY', 'KWD', 'BHD', 'OMR'], true) && $minor === match ($currency) {
                    'JPY' => 0,'KWD','BHD','OMR' => 3,default => 2
                }, 409, 'Currency is not configured for this test adapter.');
            }
            $reference = (string) Str::uuid();
            if ($v['scope'] === 'charging') {
                $invoice = Invoice::findOrFail($i->id);
                $amount = app(FinanceService::class)->summary($invoice)['amount_due_minor'];
                abort_if(DB::table('gateway_checkouts')->where('invoice_id', $i->id)->whereIn('status', ['pending', 'authorized', 'action_required'])->exists(), 409, 'Resolve the existing invoice checkout.');
                $c = app(GatewayService::class)->checkout($invoice, $actor->id, $amount, 'hosted:'.$reference);
            } else {
                $amount = (int) $i->total_minor;
                $c = app(SubscriptionGateway::class)->checkout($actor, (int) $v['subscription_id'], (int) $i->id, 'hosted:'.$reference);
            }
            abort_unless($amount > 0 && $amount <= 2000000000, 409, 'Unsupported invoice amount.');
            if (config('payments.provider') === 'stripe_test' && $minor === 3) {
                abort_unless($amount % 10 === 0, 409, 'The provider requires three-decimal amounts to end in zero.');
            }
            $return = config('payments.hosted_return_url') ?: url('/payments/hosted-return');
            $parts = parse_url($return);
            abort_unless(is_array($parts) && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment']) && (! app()->environment('production')), 503);
            if (config('payments.provider') === 'stripe_test') {
                abort_unless(($parts['scheme'] ?? '') === 'https' && ! isset($parts['query']) && ! empty($parts['host']), 503, 'Configure a fixed trusted HTTPS payment return URL.');
            }
            $return .= (str_contains($return, '?') ? '&' : '?').'hosted_reference='.$reference;
            $id = DB::table('hosted_payment_checkouts')->insertGetId(['reference' => $reference, 'scope' => $v['scope'], 'invoice_id' => $i->id, 'subscription_id' => $v['subscription_id'] ?? null, 'canonical_checkout_id' => $c->id, 'checkout_reference' => $c->provider_reference, 'user_id' => $actor->id, 'provider' => config('payments.provider'), 'provider_configuration' => $this->configuration(config('payments.provider')), 'status' => 'preparing', 'amount_minor' => $amount, 'currency' => $currency, 'minor_units' => $minor, 'request_key' => $key, 'fingerprint' => $fp, 'return_url' => $return, 'expires_at' => now()->addHour(), 'retry_until' => now()->addHours(23), 'created_at' => now(), 'updated_at' => now()]);
            $this->event($id, 'prepared');

            return DB::table('hosted_payment_checkouts')->find($id);
        }, 3);
        if ($h->status === 'preparing' || (in_array($h->status, ['uncertain', 'needs_review'], true) && ! $h->provider_session)) {
            $this->dispatch($h);
        }

        return $this->dto($this->access($actor, $h->id), $actor);
    }

    public function retryPreparation(User $actor, int $id): array
    {
        $this->enabled();
        $h = $this->access($actor, $id, true);
        $this->assertConfiguration($h);
        // The original row, request body and provider key are retained. A
        // negative lookup is not proof that a replacement would be safe.
        abort_unless(! $h->provider_session && in_array($h->status, ['preparing', 'uncertain', 'needs_review'], true), 409, 'Inspect the original hosted session instead.');
        $this->dispatch($h);

        return $this->dto($this->access($actor, $id), $actor);
    }

    private function dispatch(object $h): void
    {
        $this->assertConfiguration($h);
        $lease = (string) Str::uuid();
        DB::transaction(function () use ($h, $lease) {
            $this->root($h->scope, $h->invoice_id, $h->subscription_id);
            $r = DB::table('hosted_payment_checkouts')->where('id', $h->id)->lockForUpdate()->first();
            abort_if($r->lease_until && now()->lt($r->lease_until), 409, 'Hosted provider operation is executing.');
            abort_unless(in_array($r->status, ['preparing', 'uncertain', 'needs_review'], true) && ! $r->provider_session && now()->lt($r->expires_at) && now()->lt($r->retry_until), 409, 'Inspect original hosted outcome; do not replace it.');
            DB::table('hosted_payment_checkouts')->where('id', $h->id)->update(['lease' => $lease, 'lease_until' => now()->addMinutes(3), 'status' => 'uncertain', 'updated_at' => now()]);
            $this->event($h->id, 'provider_creation_dispatched');
        });
        try {
            if ($h->provider === 'mock') {
                $session = ['id' => 'mock_'.$h->reference, 'url' => null, 'status' => 'open'];
            } else {
                $session = (new StripeSandboxProvider)->hostedPayment($h, $h->checkout_reference);
                $this->validateSession($h, $session);
            }
            DB::transaction(function () use ($h, $session, $lease) {
                $this->root($h->scope, $h->invoice_id, $h->subscription_id);
                $r = DB::table('hosted_payment_checkouts')->where('id', $h->id)->lockForUpdate()->first();
                abort_unless($r->lease === $lease, 409);
                DB::table('hosted_payment_checkouts')->where('id', $h->id)->update(['provider_session' => $session['id'], 'hosted_url' => isset($session['url']) ? Crypt::encryptString($this->safeUrl($session['url'])) : null, 'status' => 'open', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
                $this->event($h->id, 'provider_session_observed');
            });
        } catch (Throwable $e) {
            DB::transaction(function () use ($h, $lease) {
                $this->root($h->scope, $h->invoice_id, $h->subscription_id);
                $r = DB::table('hosted_payment_checkouts')->where('id', $h->id)->lockForUpdate()->first();
                if ($r->lease === $lease) {
                    DB::table('hosted_payment_checkouts')->where('id', $h->id)->update(['status' => 'uncertain', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
                    $this->event($h->id, 'provider_creation_uncertain');
                }
            });
            throw $e;
        }
    }

    private function safeUrl(string $url): string
    {
        $u = parse_url($url);
        abort_unless(is_array($u) && ($u['scheme'] ?? '') === 'https' && ($u['host'] ?? '') === 'checkout.stripe.com' && ! isset($u['user']) && ! isset($u['pass']) && ! isset($u['port']) && ! isset($u['query']) && preg_match('~^/c/pay/cs_test_[A-Za-z0-9]+$~D', $u['path'] ?? ''), 502, 'Unsafe hosted provider URL.');

        return $url;
    }

    private function validateSession(object $h, array $s): void
    {
        abort_unless(($s['livemode'] ?? null) === false && ($s['mode'] ?? null) === 'payment' && ($s['client_reference_id'] ?? null) === $h->reference && ($s['metadata']['creabiz_hosted'] ?? null) === $h->reference && ($s['amount_total'] ?? null) === (int) $h->amount_minor && ($s['currency'] ?? null) === strtolower($h->currency) && preg_match('/^cs_test_[A-Za-z0-9]+$/D', $s['id'] ?? ''), 409, 'Hosted provider identity/amount does not reconcile.');
        abort_unless(! $h->provider_session || $h->provider_session === $s['id'], 409, 'Provider changed the original hosted session.');
        if (isset($s['url']) && $s['url'] !== null) {
            $this->safeUrl($s['url']);
            abort_unless(basename(parse_url($s['url'], PHP_URL_PATH)) === $s['id'], 409, 'Hosted URL belongs to a different provider session.');
        }
    }

    public function inspect(User $actor, int $id): array
    {
        $this->enabled();
        $h = $this->access($actor, $id);
        abort_unless((int) $h->user_id === $actor->id || $actor->role === 'admin', 404);
        $this->assertConfiguration($h);
        if ($h->provider === 'mock') {
            DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => 'finance.hosted.provider_inspected', 'subject' => (string) $id, 'created_at' => now()]);

            return $this->dto($h, $actor);
        }
        abort_if($h->lease_until && now()->lt($h->lease_until), 409, 'Provider creation is still executing.');
        $provider = new StripeSandboxProvider;
        $s = $h->provider_session ? $provider->readHostedPayment($h->provider_session) : $provider->findHostedPayment($h->reference);
        if (! $s) {
            DB::transaction(function () use ($h, $actor, $id) {
                $this->root($h->scope, $h->invoice_id, $h->subscription_id);
                $r = DB::table('hosted_payment_checkouts')->where('id', $id)->lockForUpdate()->first();
                if (! $r->provider_session && ! in_array($r->status, ['captured', 'cancelled', 'expired'], true)) {
                    DB::table('hosted_payment_checkouts')->where('id', $id)->update(['status' => 'needs_review', 'updated_at' => now()]);
                    $this->event($id, 'provider_no_session_observed');
                }
                DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => 'finance.hosted.provider_inspected', 'subject' => (string) $id, 'created_at' => now()]);
            });

            return $this->dto($this->access($actor, $id), $actor);
        }
        $this->validateSession($h, $s);
        $pi = $s['payment_intent'] ?? null;
        if (is_string($pi)) {
            abort(409, 'Expanded authoritative payment intent is required.');
        }
        DB::transaction(function () use ($h, $s, $pi, $actor) {
            $this->root($h->scope, $h->invoice_id, $h->subscription_id);
            $locked = DB::table('hosted_payment_checkouts')->where('id', $h->id)->lockForUpdate()->first();
            DB::table('hosted_payment_checkouts')->where('id', $h->id)->update(['provider_session' => $s['id'], 'updated_at' => now()]);
            if (is_array($pi)) {
                $c = DB::table($this->table($h))->where('id', $h->canonical_checkout_id)->lockForUpdate()->first();
                self::bindEvidence($h->scope, $c, $pi);
                $type = match ($pi['status'] ?? '') {
                    'requires_capture' => 'payment_intent.amount_capturable_updated','succeeded' => 'payment_intent.succeeded',default => null
                };
                if ($type) {
                    $event = ['id' => 'hosted_inspection_'.hash('sha256', json_encode([$h->id, $pi], JSON_THROW_ON_ERROR)), 'type' => $type, 'data' => ['object' => $pi]];
                    self::within($h->id, fn () => $h->scope === 'charging' ? app(GatewayService::class)->applyHostedEvidence($event) : app(SubscriptionGateway::class)->applyHostedEvidence($event));
                }
            }
            if (is_array($pi) && ($pi['status'] ?? null) === 'canceled') {
                $c = DB::table($this->table($h))->where('id', $h->canonical_checkout_id)->lockForUpdate()->first();
                abort_unless($c->status !== 'captured', 409, 'Provider cancellation contradicts a posted capture.');
                $intent = DB::table('payment_recovery_intents')->where('scope', $h->scope)->where('checkout_id', $c->id)->where('kind', 'cancel')->first();
                if ($intent) {
                    app(PaymentRecovery::class)->applyCancellation($intent, $pi);
                } else {
                    DB::table($this->table($h))->where('id', $c->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
                }
                $this->event($h->id, 'provider_cancellation_observed');
            }
            $fresh = DB::table($this->table($h))->find($h->canonical_checkout_id);
            $status = $fresh->status === 'captured' ? 'captured' : ($fresh->status === 'authorized' ? 'authorized' : 'open');
            if ((($s['status'] ?? '') === 'expired' && $pi === null) || (in_array($s['status'] ?? '', ['expired', 'complete'], true) && is_array($pi) && ($pi['status'] ?? null) === 'canceled')) {
                $status = ($s['status'] ?? '') === 'expired' ? 'expired' : 'cancelled';
                DB::table($this->table($h))->where('id', $fresh->id)->where('status', '!=', 'captured')->update(['status' => 'cancelled', 'updated_at' => now()]);
            }
            if (in_array($locked->status, ['captured', 'cancelled', 'expired'], true)) {
                $status = $locked->status;
            }
            DB::table('hosted_payment_checkouts')->where('id', $h->id)->update(['status' => $status, 'hosted_url' => isset($s['url']) && $s['url'] ? Crypt::encryptString($this->safeUrl($s['url'])) : null, 'updated_at' => now()]);
            $this->event($h->id, 'provider_inspected');
            DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => 'finance.hosted.provider_inspected', 'subject' => (string) $h->id, 'created_at' => now()]);
        }, 3);

        return $this->dto($this->access($actor, $id), $actor);
    }

    public static function bindEvidence(string $scope, object $c, array $pi): ?int
    {
        if (! Schema::hasTable('hosted_payment_checkouts')) {
            return null;
        }
        $h = DB::table('hosted_payment_checkouts')->where('scope', $scope)->where('canonical_checkout_id', $c->id)->lockForUpdate()->first();
        if (! $h) {
            return null;
        }
        (new self)->assertConfiguration($h);
        abort_unless(($pi['livemode'] ?? null) === false && ($pi['capture_method'] ?? null) === 'manual' && ($pi['metadata']['creabiz_hosted'] ?? null) === $h->reference && ($pi['metadata']['creabiz_checkout'] ?? null) === $h->checkout_reference && ($pi['amount'] ?? null) === (int) $h->amount_minor && ($pi['currency'] ?? null) === strtolower($h->currency) && preg_match('/^pi_[A-Za-z0-9]+$/D', $pi['id'] ?? '') && (! $h->provider_intent || $h->provider_intent === $pi['id']), 409, 'Hosted payment intent does not reconcile.');
        DB::table('hosted_payment_checkouts')->where('id', $h->id)->update(['provider_intent' => $pi['id'], 'updated_at' => now()]);

        return (int) $h->id;
    }

    public static function observed(string $scope, int $checkout, string $canonical): void
    {
        if (! Schema::hasTable('hosted_payment_checkouts')) {
            return;
        }
        $status = match ($canonical) {
            'captured' => 'captured','authorized' => 'authorized',default => null
        };
        if ($status) {
            DB::table('hosted_payment_checkouts')->where('scope', $scope)->where('canonical_checkout_id', $checkout)->whereNotIn('status', ['captured', 'cancelled', 'expired'])->update(['status' => $status, 'updated_at' => now()]);
        }
    }

    public function capture(User $actor, int $id): array
    {
        $this->enabled();
        $h = $this->access($actor, $id, true);
        $this->assertConfiguration($h);
        abort_unless($h->status === 'authorized' || $h->status === 'captured', 409, 'Inspect the provider authorization before capture.');
        self::within($id, fn () => $h->scope === 'charging' ? app(GatewayService::class)->operate($h->canonical_checkout_id, $actor->id, 'capture', 'hosted-capture:'.$h->reference) : app(SubscriptionGateway::class)->operate($actor, $h->canonical_checkout_id, 'capture', 'hosted-capture:'.$h->reference, null));
        self::observed($h->scope, $h->canonical_checkout_id, 'captured');
        $this->event($id, 'capture_observed');

        return $this->dto($this->access($actor, $id), $actor);
    }

    public function cancel(User $actor, int $id): array
    {
        $this->enabled();
        $h = $this->access($actor, $id, true);
        $this->assertConfiguration($h);
        if (in_array($h->status, ['cancelled', 'expired'], true)) {
            return $this->dto($h, $actor);
        }
        abort_unless($h->status !== 'captured', 409, 'Captured funds require the canonical refund workflow.');
        if ($h->provider === 'stripe_test') {
            $this->inspect($actor, $id);
            $h = $this->access($actor, $id, true);
            $this->assertConfiguration($h);
            abort_unless(! in_array($h->status, ['captured', 'uncertain', 'needs_review', 'preparing'], true), 409, 'Resolve the original provider outcome before cancellation.');
            if ($h->status === 'expired') {
                return $this->dto($h, $actor);
            }
            if ($h->status === 'open') {
                (new StripeSandboxProvider)->expireHostedPayment($h->provider_session, $h->reference);

                return $this->inspect($actor, $id);
            }
        }
        self::within($id, fn () => $h->scope === 'charging' ? app(GatewayService::class)->operate($h->canonical_checkout_id, $actor->id, 'cancel', 'hosted-cancel:'.$h->reference) : app(SubscriptionGateway::class)->operate($actor, $h->canonical_checkout_id, 'cancel', 'hosted-cancel:'.$h->reference, null));
        DB::table('hosted_payment_checkouts')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        $this->event($id, 'cancellation_observed');

        return $this->dto($this->access($actor, $id), $actor);
    }

    public function simulate(User $actor, int $id, string $outcome): array
    {
        $this->enabled();
        $h = $this->access($actor, $id, true);
        $this->assertConfiguration($h);
        abort_unless($h->provider === 'mock' && config('payments.provider') === 'mock' && app()->environment('local', 'testing'), 404);
        abort_unless($h->status === 'open', 409, 'The simulated hosted session is not open.');
        if ($outcome === 'abandoned') {
            return $this->cancel($actor, $id);
        }
        self::within($id, fn () => $h->scope === 'charging' ? app(GatewayService::class)->operate($h->canonical_checkout_id, $actor->id, 'authorize', 'hosted-auth:'.$h->reference, null, $outcome === 'authorized' ? 'mock_success' : 'mock_decline') : app(SubscriptionGateway::class)->operate($actor, $h->canonical_checkout_id, 'authorize', 'hosted-auth:'.$h->reference, $outcome === 'authorized' ? 'mock_success' : 'mock_decline'));
        DB::table('hosted_payment_checkouts')->where('id', $id)->where('status', 'open')->update(['status' => $outcome === 'authorized' ? 'authorized' : 'cancelled', 'updated_at' => now()]);
        $this->event($id, 'mock_'.$outcome);

        return $this->dto($this->access($actor, $id), $actor);
    }
}
