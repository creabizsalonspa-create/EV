<?php

namespace App\Services\Commercial\Payments;

use App\Models\Finance\Invoice;
use App\Models\Fleet;
use App\Models\User;
use App\Services\Finance\FinanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/** A statement-root operational payment identity; per-invoice journals remain the financial truth. */
class CorporateHostedCollection
{
    private static ?int $allocating = null;

    public static function guardPayment(int $invoice): void
    {
        if (! Schema::hasTable('corporate_hosted_lines')) {
            return;
        }
        $holds = DB::table('corporate_hosted_lines as l')->join('corporate_hosted_checkouts as h', 'h.id', '=', 'l.checkout_id')->where('l.invoice_id', $invoice)->whereNotIn('h.status', ['cancelled', 'expired'])->pluck('h.id');
        foreach ($holds as $id) {
            abort_unless((int) $id === self::$allocating, 409, 'A corporate provider collection holds this invoice. Reconcile its original statement.');
        }
    }

    public static function guardRefund(int $invoice): void
    {
        if (! Schema::hasTable('corporate_hosted_lines')) {
            return;
        }
        abort_if(DB::table('corporate_hosted_lines as l')->join('corporate_hosted_checkouts as h', 'h.id', '=', 'l.checkout_id')->where('l.invoice_id', $invoice)->whereNotIn('h.status', ['cancelled', 'expired'])->exists(), 409, 'Corporate provider refunds and disputes require statement-level provider review; a local credit is not a returned payment.');
    }

    public static function settlementHeld(int $invoice): bool
    {
        return Schema::hasTable('corporate_hosted_lines') && DB::table('corporate_hosted_lines as l')->join('corporate_hosted_checkouts as h', 'h.id', '=', 'l.checkout_id')->where('l.invoice_id', $invoice)->where('h.provider', 'stripe_test')->whereNotIn('h.status', ['cancelled', 'expired'])->exists();
    }

    private function enabled(): void
    {
        abort_if(app()->environment('production'), 503, 'Corporate hosted collection is not approved for production.');
        abort_unless(in_array(config('payments.provider'), ['mock', 'stripe_test'], true), 503);
        if (config('payments.provider') === 'mock') {
            abort_unless(app()->environment('local', 'testing'), 503);
        }
        if (config('payments.provider') === 'stripe_test') {
            abort_unless(is_string(config('payments.stripe_test_secret')) && preg_match('/^sk_test_[A-Za-z0-9]+$/D', config('payments.stripe_test_secret')), 503, 'Configure an approved Stripe TEST key before opening a corporate collection.');
        }
    }

    private function configuration(string $provider): string
    {
        return hash('sha256', $provider === 'mock' ? 'creabiz-corporate:mock:v1' : 'creabiz-corporate:stripe_test:'.config('payments.stripe_test_secret'));
    }

    private function configurationMatches(object $h): bool
    {
        return $h->provider === config('payments.provider') && hash_equals($h->provider_configuration, $this->configuration($h->provider));
    }

    private function assertConfiguration(object $h): void
    {
        abort_unless($this->configurationMatches($h), 503, 'Restore the original corporate merchant configuration before operating this collection.');
    }

    public function access(User $actor, int $id, bool $owner = false): object
    {
        $h = DB::table('corporate_hosted_checkouts')->find($id) ?? abort(404);
        $s = DB::table('corporate_statements')->find($h->statement_id);
        $fleet = Fleet::findOrFail($s->fleet_id);
        $own = (int) $h->payer_id === $actor->id && (int) $fleet->owner_id === $actor->id;
        abort_unless($own || (! $owner && $actor->role === 'admin'), 404);

        return $h;
    }

    private function event(int $id, string $kind): void
    {
        DB::table('corporate_hosted_events')->insert(['checkout_id' => $id, 'kind' => $kind, 'created_at' => now()]);
    }

    /** All mutation/ledger paths lock the same fleet, statement, sorted invoice roots, then checkout. */
    private function roots(int $statement, ?int $actor = null, ?int $checkout = null): array
    {
        $hint = DB::table('corporate_statements')->find($statement) ?? abort(404);
        $fleet = Fleet::whereKey($hint->fleet_id)->lockForUpdate()->firstOrFail();
        $s = DB::table('corporate_statements')->where('id', $statement)->lockForUpdate()->first();
        if ($actor !== null) {
            abort_unless($actor === (int) $s->payer_id && $actor === (int) $fleet->owner_id, 404);
        }
        $lines = DB::table('corporate_statement_lines')->where('statement_id', $statement)->orderBy('invoice_id')->get();
        abort_unless($lines->isNotEmpty(), 409, 'A statement requires immutable invoice lines.');
        $invoices = [];
        foreach ($lines as $line) {
            $invoices[$line->invoice_id] = Invoice::whereKey($line->invoice_id)->lockForUpdate()->firstOrFail();
        }
        $h = $checkout ? DB::table('corporate_hosted_checkouts')->where('id', $checkout)->lockForUpdate()->firstOrFail() : null;

        return [$s, $lines, $invoices, $h];
    }

    private function lineFingerprint(object $s, iterable $lines, array $invoices): string
    {
        $sum = 0;
        $parts = [];
        foreach ($lines as $l) {
            $i = $invoices[$l->invoice_id];
            abort_unless((int) $l->total_minor === (int) $i->total_minor && $i->currency === $s->currency && (int) $i->minor_units === (int) $s->minor_units && (int) $l->driver_id === (int) $i->user_id, 409, 'Corporate invoice terms do not reconcile.');
            abort_unless($sum <= PHP_INT_MAX - (int) $l->total_minor, 409);
            $sum += (int) $l->total_minor;
            $parts[] = [(int) $l->id, (int) $l->invoice_id, (int) $l->total_minor, (int) $l->driver_id];
        }
        abort_unless($sum > 0 && $sum === (int) $s->total_minor, 409, 'Corporate total does not reconcile.');

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function unpaid(iterable $lines): void
    {
        foreach ($lines as $l) {
            abort_if(DB::table('finance_payments')->where('invoice_id', $l->invoice_id)->exists() || DB::table('gateway_checkouts')->where('invoice_id', $l->invoice_id)->exists(), 409, 'A corporate source invoice already has a payment path.');
            abort_if(DB::table('finance_refund_requests')->where('invoice_id', $l->invoice_id)->where('status', 'pending')->exists() || DB::table('gateway_cases')->where('invoice_id', $l->invoice_id)->where('status', 'open')->exists(), 409, 'A financial case holds this corporate invoice.');
        }
    }

    public function statementContract(object $s, iterable $lines, int $actor): array
    {
        $invoices = [];
        foreach ($lines as $l) {
            $invoices[$l->invoice_id] = Invoice::findOrFail($l->invoice_id);
        }

        return ['line_fingerprint' => $this->lineFingerprint($s, $lines, $invoices), 'can_collect_hosted' => (config('payments.provider') !== 'stripe_test' || (is_string(config('payments.stripe_test_secret')) && preg_match('/^sk_test_[A-Za-z0-9]+$/D', config('payments.stripe_test_secret')) === 1)) && ! app()->environment('production') && in_array(config('payments.provider'), ['mock', 'stripe_test'], true) && (config('payments.provider') !== 'mock' || app()->environment('local', 'testing')) && (int) $s->payer_id === $actor && (int) Fleet::findOrFail($s->fleet_id)->owner_id === $actor && ! DB::table('corporate_receipts')->where('statement_id', $s->id)->exists()];
    }

    public function dto(object $h, User $actor): array
    {
        $s = DB::table('corporate_statements')->find($h->statement_id);
        $own = (int) $h->payer_id === $actor->id && (int) Fleet::findOrFail($s->fleet_id)->owner_id === $actor->id;
        $available = ! app()->environment('production') && $this->configurationMatches($h) && ($h->provider !== 'mock' || app()->environment('local', 'testing'));
        $pending = DB::table('corporate_hosted_operations')->where('checkout_id', $h->id)->where('status', '!=', 'completed')->first();
        if ($pending && $pending->status === 'executing' && $pending->lease_until && now()->gte($pending->lease_until)) {
            $pending->status = 'uncertain';
        }
        $active = ! in_array($h->status, ['captured', 'cancelled', 'expired'], true);
        $actions = $available && ($own || $actor->role === 'admin') ? ['inspect'] : [];
        if ($available && $own && $active && in_array($h->status, ['open', 'authorized'], true) && (! $pending || (in_array($pending->kind, ['cancel', 'expire'], true) && $pending->status === 'uncertain' && now()->lt($pending->retry_until)))) {
            $actions[] = 'cancel';
        }
        if ($available && $own && $h->status === 'authorized' && (! $pending || ($pending->kind === 'capture' && $pending->status === 'uncertain' && now()->lt($pending->retry_until)))) {
            $actions[] = 'capture';
        }
        $prep = $available && $own && ! $h->provider_session && in_array($h->status, ['preparing', 'uncertain', 'needs_review'], true) && now()->lt($h->expires_at) && (! $h->lease_until || now()->gte($h->lease_until));
        if ($prep) {
            $actions[] = 'retry_preparation';
        }
        $retry = $available && $own && in_array($h->status, ['cancelled', 'expired'], true) && ! $pending && ! DB::table('corporate_receipts')->where('statement_id', $s->id)->exists() && ! DB::table('corporate_hosted_checkouts')->where('statement_id', $s->id)->where('id', '!=', $h->id)->whereNotIn('status', ['cancelled', 'expired'])->exists();

        if ($retry) {
            $ids = DB::table('corporate_hosted_lines')->where('checkout_id', $h->id)->pluck('invoice_id');
            $retry = ! DB::table('finance_payments')->whereIn('invoice_id', $ids)->exists() && ! DB::table('gateway_checkouts')->whereIn('invoice_id', $ids)->exists();
        }

        return ['id' => (int) $h->id, 'reference' => $h->reference, 'scope' => 'corporate', 'statement_id' => (int) $h->statement_id, 'payer_id' => (int) $h->payer_id, 'user_id' => (int) $h->payer_id, 'invoice_id' => null, 'subscription_id' => null, 'canonical_checkout_id' => (int) $h->id, 'line_fingerprint' => $h->line_fingerprint, 'status' => $pending && $h->status !== 'captured' ? 'uncertain' : $h->status, 'hosted_status' => $h->status, 'canonical_status' => $h->status, 'recovery_status' => $pending?->status, 'recovery_operation' => $pending?->kind, 'amount_minor' => (int) $h->amount_minor, 'currency' => $h->currency, 'minor_units' => (int) $h->minor_units, 'expires_at' => CarbonImmutable::parse($h->expires_at, 'UTC')->toIso8601String(), 'created_at' => CarbonImmutable::parse($h->created_at, 'UTC')->toIso8601String(), 'updated_at' => CarbonImmutable::parse($h->updated_at, 'UTC')->toIso8601String(), 'hosted_url' => $available && $own && $h->status === 'open' && $h->hosted_url ? Crypt::decryptString($h->hosted_url) : null, 'simulation_url' => $available && $own && $h->provider === 'mock' && $h->status === 'open' ? '/payments/corporate-hosted-simulation/'.$h->id : null, 'evidence' => $h->provider === 'mock' ? 'MOCK_HOSTED' : 'STRIPE_TEST_CONTRACT', 'real_money_moved' => false, 'external_provider_verified' => false, 'owner' => $own, 'allowed_actions' => $actions, 'actions' => $actions, 'can_inspect' => in_array('inspect', $actions), 'can_capture' => in_array('capture', $actions), 'can_cancel' => in_array('cancel', $actions), 'can_retry_preparation' => $prep, 'can_retry' => $retry, 'can_simulate' => $available && $own && $h->provider === 'mock' && $h->status === 'open', 'provider_review_required' => DB::table('corporate_hosted_events')->where('checkout_id', $h->id)->where('kind', 'provider_case_requires_review')->exists(), 'refund_dispute_policy' => 'statement_provider_review_required'];
    }

    private function providerTerms(object $s): void
    {
        $minor = (int) $s->minor_units;
        $currency = $s->currency;
        $amount = (int) $s->total_minor;
        abort_unless(preg_match('/^[A-Z]{3}$/D', $currency) && in_array($minor, [0, 2, 3], true) && $amount > 0 && $amount <= 2000000000, 409, 'Unsupported corporate provider amount or currency precision.');
        if (config('payments.provider') === 'stripe_test') {
            abort_unless(in_array($currency, ['AED', 'USD', 'EUR', 'GBP', 'SAR', 'QAR', 'JPY', 'KWD', 'BHD', 'OMR'], true) && $minor === match ($currency) {
                'JPY' => 0,'KWD','BHD','OMR' => 3,default => 2
            }, 409, 'Corporate currency is not configured for this test adapter.');
            if ($minor === 3) {
                abort_unless($amount % 10 === 0, 409, 'The provider requires three-decimal amounts to end in zero.');
            }
        }
    }

    public function create(User $actor, int $statement, string $key): array
    {
        $this->enabled();
        $id = DB::transaction(function () use ($actor, $statement, $key) {
            [$s,$lines,$invoices] = $this->roots($statement, $actor->id);
            $request = hash('sha256', $actor->id.':'.$key);
            $fingerprint = hash('sha256', (string) $statement);
            if ($old = DB::table('corporate_hosted_checkouts')->where('request_key', $request)->first()) {
                abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, 'Corporate checkout key payload changed.');
                $this->assertConfiguration($old);

                return (int) $old->id;
            }
            abort_if(DB::table('corporate_receipts')->where('statement_id', $statement)->exists(), 409, 'Statement already collected.');
            abort_if(DB::table('corporate_hosted_checkouts')->where('statement_id', $statement)->whereNotIn('status', ['cancelled', 'expired'])->exists(), 409, 'Resolve the original corporate collection before replacing it.');
            $this->unpaid($lines);
            foreach ($lines as $l) {
                self::guardPayment((int) $l->invoice_id);
            }
            $lineHash = $this->lineFingerprint($s, $lines, $invoices);
            $this->providerTerms($s);
            $return = config('payments.corporate_hosted_return_url') ?: rtrim(config('app.url'), '/').'/payments/corporate-hosted-return';
            $parts = parse_url($return);
            abort_unless(is_array($parts) && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']) && ($parts['path'] ?? null) === '/payments/corporate-hosted-return', 503, 'Configure a fixed corporate payment return URL.');
            if (config('payments.provider') === 'stripe_test') {
                abort_unless(($parts['scheme'] ?? null) === 'https' && ! empty($parts['host']), 503, 'Corporate Stripe TEST requires a fixed trusted HTTPS return URL.');
            }
            $reference = (string) Str::uuid();
            $provider = config('payments.provider');
            $id = DB::table('corporate_hosted_checkouts')->insertGetId(['reference' => $reference, 'statement_id' => $statement, 'payer_id' => $actor->id, 'provider' => $provider, 'provider_configuration' => $this->configuration($provider), 'line_fingerprint' => $lineHash, 'amount_minor' => $s->total_minor, 'currency' => $s->currency, 'minor_units' => $s->minor_units, 'request_key' => $request, 'fingerprint' => $fingerprint, 'status' => 'preparing', 'return_url' => $return.'?hosted_reference='.$reference, 'expires_at' => now()->addHour(), 'retry_until' => now()->addHours(23), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($lines as $l) {
                DB::table('corporate_hosted_lines')->insert(['checkout_id' => $id, 'invoice_id' => $l->invoice_id, 'statement_line_id' => $l->id, 'amount_minor' => $l->total_minor, 'created_at' => now()]);
            }
            $this->event($id, 'prepared');

            return $id;
        }, 3);
        $h = $this->access($actor, $id, true);
        if ($h->status === 'preparing') {
            $this->dispatch($actor, $id);
        }

        return $this->dto($this->access($actor, $id), $actor);
    }

    public function retryPreparation(User $actor, int $id): array
    {
        $this->enabled();
        $h = $this->access($actor, $id, true);
        $this->assertConfiguration($h);
        abort_unless($this->dto($h, $actor)['can_retry_preparation'], 409, 'The original preparation cannot be retried now.');
        $this->dispatch($actor, $id);

        return $this->dto($this->access($actor, $id), $actor);
    }

    private function dispatch(User $actor, int $id): void
    {
        $hint = $this->access($actor, $id, true);
        $this->assertConfiguration($hint);
        $lease = Str::random(48);
        $h = DB::transaction(function () use ($hint, $actor, $lease) {
            [,,$invoices,$h] = $this->roots((int) $hint->statement_id, $actor->id, (int) $hint->id);
            abort_unless(! $h->provider_session && in_array($h->status, ['preparing', 'uncertain', 'needs_review'], true) && now()->lt($h->expires_at) && (! $h->lease_until || now()->gte($h->lease_until)), 409, 'Inspect the original preparation before retrying.');
            DB::table('corporate_hosted_checkouts')->where('id', $h->id)->update(['status' => 'preparing', 'lease' => $lease, 'lease_until' => now()->addMinutes(3), 'updated_at' => now()]);

            return $h;
        }, 3);
        try {
            if ($h->provider === 'mock') {
                DB::transaction(function () use ($h, $actor, $lease) {
                    [, , ,$locked] = $this->roots((int) $h->statement_id, $actor->id, (int) $h->id);
                    abort_unless($locked->lease === $lease, 409);
                    DB::table('corporate_hosted_checkouts')->where('id', $h->id)->update(['status' => 'open', 'provider_session' => 'mock_corporate_'.$h->reference, 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
                    $this->event($h->id, 'mock_opened');
                }, 3);
            } else {
                $session = (new StripeSandboxProvider)->hostedPayment($h, 'corporate_'.$h->reference);
                $this->applySession($h, $session, $actor->id);
            }
        } catch (Throwable $e) {
            DB::table('corporate_hosted_checkouts')->where('id', $id)->where('lease', $lease)->where('status', 'preparing')->update(['status' => 'uncertain', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
            throw $e;
        }
    }

    private function validateSession(object $h, array $session): void
    {
        abort_unless(($session['livemode'] ?? null) === false && ($session['mode'] ?? null) === 'payment' && ($session['client_reference_id'] ?? null) === $h->reference && ($session['metadata']['creabiz_hosted'] ?? null) === $h->reference && ($session['amount_total'] ?? null) === (int) $h->amount_minor && ($session['currency'] ?? null) === strtolower($h->currency) && is_string($session['id'] ?? null) && preg_match('/^cs_test_[A-Za-z0-9]+$/D', $session['id']) && (! $h->provider_session || $h->provider_session === $session['id']), 409, 'Corporate hosted session does not reconcile.');
        if ($session['url'] ?? null) {
            $url = parse_url($session['url']);
            abort_unless(is_array($url) && ($url['scheme'] ?? null) === 'https' && ($url['host'] ?? null) === 'checkout.stripe.com' && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['port']) && ! isset($url['query']) && ($url['path'] ?? null) === '/c/pay/'.$session['id'], 502, 'Untrusted corporate hosted destination.');
        }
    }

    private function applySession(object $hint, array $session, ?int $actor = null): void
    {
        $this->assertConfiguration($hint);
        $this->validateSession($hint, $session);
        DB::transaction(function () use ($hint, $session, $actor) {
            [$s,$lines,$invoices,$h] = $this->roots((int) $hint->statement_id, null, (int) $hint->id);
            $this->assertConfiguration($h);
            $this->validateSession($h, $session);
            abort_unless(hash_equals($h->line_fingerprint, $this->lineFingerprint($s, $lines, $invoices)), 409);
            DB::table('corporate_hosted_checkouts')->where('id', $h->id)->update(['provider_session' => $session['id'], 'hosted_url' => isset($session['url']) ? Crypt::encryptString($session['url']) : $h->hosted_url, 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
            $pi = $session['payment_intent'] ?? null;
            if (is_array($pi)) {
                $this->applyIntent($h, $pi, $s, $lines, $invoices);
            } else {
                abort_unless(! $h->provider_intent && ! $pi, 409, 'Expand the original corporate payment intent before reconciliation.');
                if (! in_array($h->status, ['captured', 'cancelled', 'expired'], true)) {
                    $status = ($session['status'] ?? null) === 'expired' ? 'expired' : (($session['status'] ?? null) === 'open' ? 'open' : 'needs_review');
                    DB::table('corporate_hosted_checkouts')->where('id', $h->id)->update(['status' => $status, 'updated_at' => now()]);
                    if ($status === 'expired') {
                        DB::table('corporate_hosted_operations')->where('checkout_id', $h->id)->where('kind', 'expire')->update(['status' => 'completed', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
                    }
                }
            }
            $this->event($h->id, 'provider_inspected');
            if ($actor) {
                DB::table('audit_events')->insert(['actor_id' => $actor, 'action' => 'finance.corporate_hosted.provider_inspected', 'subject' => (string) $h->id, 'created_at' => now()]);
            }
        }, 3);
    }

    private function applyIntent(object $h, array $pi, object $s, iterable $lines, array $invoices): void
    {
        abort_unless(($pi['livemode'] ?? null) === false && ($pi['capture_method'] ?? null) === 'manual' && ($pi['metadata']['creabiz_hosted'] ?? null) === $h->reference && ($pi['metadata']['creabiz_checkout'] ?? null) === 'corporate_'.$h->reference && ($pi['amount'] ?? null) === (int) $h->amount_minor && ($pi['currency'] ?? null) === strtolower($h->currency) && is_string($pi['id'] ?? null) && preg_match('/^pi_[A-Za-z0-9]+$/D', $pi['id']) && (! $h->provider_intent || $h->provider_intent === $pi['id']), 409, 'Corporate provider intent does not reconcile.');
        DB::table('corporate_hosted_checkouts')->where('id', $h->id)->update(['provider_intent' => $pi['id'], 'updated_at' => now()]);
        if (($pi['status'] ?? null) === 'succeeded') {
            abort_unless(($pi['amount_received'] ?? null) === (int) $h->amount_minor && ! in_array($h->status, ['cancelled', 'expired'], true), 409, 'Conflicting terminal corporate outcome requires review.');
            $this->allocate($h, $s, $lines, $invoices);
            DB::table('corporate_hosted_checkouts')->where('id', $h->id)->update(['status' => 'captured', 'updated_at' => now()]);
            DB::table('corporate_hosted_operations')->where('checkout_id', $h->id)->update(['status' => 'completed', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
        } elseif ($h->status === 'captured') {
            return; // Late authorization/decline cannot rewind immutable collection.
        } elseif (($pi['status'] ?? null) === 'requires_capture') {
            abort_unless(($pi['amount_capturable'] ?? null) === (int) $h->amount_minor, 409);
            if (! in_array($h->status, ['cancelled', 'expired'], true)) {
                DB::table('corporate_hosted_checkouts')->where('id', $h->id)->update(['status' => 'authorized', 'updated_at' => now()]);
            }
        } elseif (($pi['status'] ?? null) === 'canceled') {
            DB::table('corporate_hosted_checkouts')->where('id', $h->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            DB::table('corporate_hosted_operations')->where('checkout_id', $h->id)->update(['status' => 'completed', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
        } elseif (in_array($pi['status'] ?? null, ['requires_action', 'requires_payment_method', 'requires_confirmation'], true)) {
            if (! in_array($h->status, ['authorized', 'cancelled', 'expired'], true)) {
                DB::table('corporate_hosted_checkouts')->where('id', $h->id)->update(['status' => 'open', 'updated_at' => now()]);
            }
        } else {
            DB::table('corporate_hosted_checkouts')->where('id', $h->id)->update(['status' => 'needs_review', 'updated_at' => now()]);
        }
    }

    private function allocate(object $h, object $s, iterable $lines, array $invoices): void
    {
        if ($old = DB::table('corporate_receipts')->where('statement_id', $s->id)->first()) {
            abort_unless($old->request_key === hash('sha256', 'corporate-hosted:'.$h->reference) && (int) $old->amount_minor === (int) $h->amount_minor, 409, 'A different corporate receipt already exists.');

            return;
        }
        abort_unless(hash_equals($h->line_fingerprint, $this->lineFingerprint($s, $lines, $invoices)), 409);
        $this->unpaid($lines);
        $prior = self::$allocating;
        self::$allocating = (int) $h->id;
        try {
            $payments = [];
            foreach ($lines as $l) {
                $payments[] = app(FinanceService::class)->pay($invoices[$l->invoice_id], (int) $h->payer_id, (int) $l->total_minor, 'corporate-hosted:'.$h->reference.':'.$l->id);
            }
        } finally {
            self::$allocating = $prior;
        }
        $receipt = DB::table('corporate_receipts')->insertGetId(['statement_id' => $s->id, 'actor_id' => $h->payer_id, 'number' => ($h->provider === 'mock' ? 'SIM' : 'TEST').'-CORP-RCPT-'.Str::ulid(), 'request_key' => hash('sha256', 'corporate-hosted:'.$h->reference), 'fingerprint' => $h->line_fingerprint, 'amount_minor' => $h->amount_minor, 'evidence' => $h->provider === 'mock' ? 'SIMULATED' : 'STRIPE_TEST_CONTRACT', 'created_at' => now()]);
        foreach ($payments as $p) {
            DB::table('corporate_receipt_payments')->insert(['receipt_id' => $receipt, 'payment_id' => $p->id, 'invoice_id' => $p->invoice_id, 'created_at' => now()]);
        }
        $this->event($h->id, 'canonical_allocation_observed');
    }

    public function inspect(User $actor, int $id): array
    {
        $this->enabled();
        $h = $this->access($actor, $id);
        $this->assertConfiguration($h);
        if ($h->provider === 'stripe_test') {
            abort_if($h->lease_until && now()->lt($h->lease_until), 409, 'Original preparation is executing.');
            $session = $h->provider_session ? (new StripeSandboxProvider)->readHostedPayment($h->provider_session) : (new StripeSandboxProvider)->findHostedPayment($h->reference);
            if ($session) {
                $this->applySession($h, $session, $actor->id);
            } else {
                DB::table('corporate_hosted_checkouts')->where('id', $id)->whereNull('provider_session')->update(['status' => 'needs_review', 'updated_at' => now()]);
            }
        }

        return $this->dto($this->access($actor, $id), $actor);
    }

    private function operation(User $actor, int $id, string $kind): object
    {
        $hint = $this->access($actor, $id, true);
        $this->assertConfiguration($hint);

        return DB::transaction(function () use ($hint, $actor, $kind) {
            [, , ,$h] = $this->roots((int) $hint->statement_id, $actor->id, (int) $hint->id);
            $this->assertConfiguration($h);
            abort_unless($h->status === ($kind === 'expire' ? 'open' : 'authorized'), 409, 'Inspect the original authorization first.');
            $old = DB::table('corporate_hosted_operations')->where('checkout_id', $h->id)->where('kind', $kind)->lockForUpdate()->first();
            abort_if(DB::table('corporate_hosted_operations')->where('checkout_id', $h->id)->where('kind', '!=', $kind)->where('status', '!=', 'completed')->exists(), 409, 'Resolve the original corporate provider operation first.');
            if ($old) {
                abort_unless(in_array($old->status, ['uncertain', 'executing'], true) && now()->lt($old->retry_until) && (! $old->lease_until || now()->gte($old->lease_until)), 409, 'Inspect the original provider operation before retrying.');
            }
            $lease = Str::random(48);
            if (! $old) {
                $op = DB::table('corporate_hosted_operations')->insertGetId(['checkout_id' => $h->id, 'kind' => $kind, 'status' => 'executing', 'lease' => $lease, 'lease_until' => now()->addMinutes(3), 'retry_until' => now()->addHours(23), 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $op = $old->id;
                DB::table('corporate_hosted_operations')->where('id', $op)->update(['status' => 'executing', 'lease' => $lease, 'lease_until' => now()->addMinutes(3), 'updated_at' => now()]);
            }
            $h->operation_id = $op;
            $h->operation_lease = $lease;

            return $h;
        }, 3);
    }

    public function capture(User $actor, int $id): array
    {
        $this->enabled();
        $h = $this->access($actor, $id, true);
        $this->assertConfiguration($h);
        if ($h->status === 'captured') {
            return $this->dto($h, $actor);
        }
        $h = $this->operation($actor, $id, 'capture');
        try {
            if ($h->provider === 'stripe_test') {
                (new StripeSandboxProvider)->capture($h->provider_intent, (int) $h->amount_minor, 'corporate:'.$h->reference);
                $this->applySession($h, (new StripeSandboxProvider)->readHostedPayment($h->provider_session), $actor->id);
            } else {
                DB::transaction(function () use ($h) {
                    [$s,$lines,$invoices,$locked] = $this->roots((int) $h->statement_id, null, (int) $h->id);
                    abort_unless($locked->status === 'authorized', 409);
                    $this->allocate($locked, $s, $lines, $invoices);
                    DB::table('corporate_hosted_checkouts')->where('id', $h->id)->update(['status' => 'captured', 'updated_at' => now()]);
                    DB::table('corporate_hosted_operations')->where('id', $h->operation_id)->update(['status' => 'completed', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
                }, 3);
            }
        } catch (Throwable $e) {
            DB::table('corporate_hosted_operations')->where('id', $h->operation_id)->where('lease', $h->operation_lease)->where('status', 'executing')->update(['status' => 'uncertain', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
            throw $e;
        }

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
        abort_unless(in_array('cancel', $this->dto($h, $actor)['allowed_actions']), 409, 'Resolve the original corporate outcome first.');
        if ($h->provider === 'stripe_test') {
            $this->inspect($actor, $id);
            $h = $this->access($actor, $id, true);
            abort_unless(in_array($h->status, ['open', 'authorized', 'expired', 'cancelled'], true), 409);
            if (in_array($h->status, ['expired', 'cancelled'], true)) {
                return $this->dto($h, $actor);
            }
            if ($h->status === 'authorized') {
                $h = $this->operation($actor, $id, 'cancel');
                try {
                    (new StripeSandboxProvider)->cancel($h->provider_intent, 'corporate:'.$h->reference);
                } catch (Throwable $e) {
                    DB::table('corporate_hosted_operations')->where('id', $h->operation_id)->where('lease', $h->operation_lease)->where('status', 'executing')->update(['status' => 'uncertain', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
                    throw $e;
                }
            } else {
                $h = $this->operation($actor, $id, 'expire');
                try {
                    (new StripeSandboxProvider)->expireHostedPayment($h->provider_session, 'corporate:'.$h->reference);
                } catch (Throwable $e) {
                    DB::table('corporate_hosted_operations')->where('id', $h->operation_id)->where('lease', $h->operation_lease)->where('status', 'executing')->update(['status' => 'uncertain', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
                    throw $e;
                }
            }

            return $this->inspect($actor, $id);
        }
        DB::transaction(function () use ($h, $actor) {
            [, , ,$locked] = $this->roots((int) $h->statement_id, $actor->id, (int) $h->id);
            abort_unless(in_array($locked->status, ['open', 'authorized'], true), 409);
            abort_if(DB::table('corporate_hosted_operations')->where('checkout_id', $h->id)->where('status', '!=', 'completed')->exists(), 409);
            DB::table('corporate_hosted_checkouts')->where('id', $h->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->event($h->id, 'mock_cancelled');
        }, 3);

        return $this->dto($this->access($actor, $id), $actor);
    }

    public function simulate(User $actor, int $id, string $outcome): array
    {
        $this->enabled();
        $h = $this->access($actor, $id, true);
        $this->assertConfiguration($h);
        abort_unless($h->provider === 'mock' && app()->environment('local', 'testing'), 404);
        if ($outcome === 'abandoned') {
            return $this->cancel($actor, $id);
        }
        DB::transaction(function () use ($h, $actor, $outcome) {
            [, , ,$locked] = $this->roots((int) $h->statement_id, $actor->id, (int) $h->id);
            abort_unless($locked->status === 'open', 409);
            DB::table('corporate_hosted_checkouts')->where('id', $h->id)->update(['status' => $outcome === 'authorized' ? 'authorized' : 'cancelled', 'updated_at' => now()]);
            $this->event($h->id, 'mock_'.$outcome);
        }, 3);

        return $this->dto($this->access($actor, $id), $actor);
    }

    /** Bounded read-only provider reconciliation; never dispatches creation/capture/cancel. */
    public function scan(int $limit): array
    {
        $this->enabled();
        abort_unless(config('payments.provider') === 'stripe_test', 503);
        abort_unless($limit >= 1 && $limit <= 100, 422);
        $rows = DB::table('corporate_hosted_checkouts')->where('provider', 'stripe_test')->where(function ($q) {
            $q->whereNotIn('status', ['captured', 'cancelled', 'expired'])->orWhereIn('id', DB::table('corporate_hosted_operations')->where('status', '!=', 'completed')->select('checkout_id'));
        })->where(function ($q) {
            $q->whereNull('lease_until')->orWhere('lease_until', '<=', now());
        })->orderBy('updated_at')->orderBy('id')->limit($limit)->get();
        $counts = ['checked' => 0, 'reconciled' => 0, 'unresolved' => 0];
        foreach ($rows as $h) {
            $counts['checked']++;
            if (! $this->configurationMatches($h)) {
                $counts['unresolved']++;

                continue;
            }
            try {
                $this->assertConfiguration($h);
                $session = $h->provider_session ? (new StripeSandboxProvider)->readHostedPayment($h->provider_session) : (new StripeSandboxProvider)->findHostedPayment($h->reference);
                if ($session) {
                    $this->applySession($h, $session);
                    $pending = DB::table('corporate_hosted_operations')->where('checkout_id', $h->id)->where('status', '!=', 'completed')->exists();
                    $counts[$pending ? 'unresolved' : 'reconciled']++;
                } else {
                    DB::table('corporate_hosted_checkouts')->where('id', $h->id)->whereNull('provider_session')->update(['status' => 'needs_review', 'updated_at' => now()]);
                    $counts['unresolved']++;
                }
            } catch (Throwable $error) {
                $counts['unresolved']++;
                // Retain the original holds and identity; no provider payload/credential enters logs.
                DB::table('corporate_hosted_checkouts')->where('id', $h->id)->update(['updated_at' => now()]);
                $this->event((int) $h->id, 'reconciliation_requires_review');
            }
        }

        return $counts;
    }

    public function webhook(string $raw, string $signature): array
    {
        $this->enabled();
        abort_unless(config('payments.provider') === 'stripe_test', 503);
        $event = (new StripeSandboxProvider)->verifyWebhook($raw, $signature);
        if (! in_array($event['type'] ?? null, ['payment_intent.succeeded', 'payment_intent.amount_capturable_updated', 'payment_intent.payment_failed', 'payment_intent.canceled', 'refund.updated', 'charge.dispute.created', 'charge.dispute.closed'], true)) {
            return ['accepted' => true, 'ignored' => true];
        }
        $pi = $event['data']['object'] ?? null;
        abort_unless(is_array($pi) && ($pi['livemode'] ?? null) === false, 422);
        $reference = $pi['metadata']['creabiz_hosted'] ?? null;
        $h = is_string($reference) ? DB::table('corporate_hosted_checkouts')->where('reference', $reference)->first() : null;
        if (! $h && is_string($pi['payment_intent'] ?? null)) {
            $h = DB::table('corporate_hosted_checkouts')->where('provider_intent', $pi['payment_intent'])->first();
        }
        abort_unless($h && $h->provider === 'stripe_test', 404);
        $this->assertConfiguration($h);
        $eventId = hash('sha256', 'corporate-stripe:'.$event['id']);
        $hash = hash('sha256', $raw);

        return DB::transaction(function () use ($h, $pi, $event, $eventId, $hash) {
            [$s,$lines,$invoices,$locked] = $this->roots((int) $h->statement_id, null, (int) $h->id);
            $this->assertConfiguration($locked);
            if ($old = DB::table('corporate_hosted_events')->where('provider_event_id', $eventId)->first()) {
                abort_unless(hash_equals($old->fingerprint, $hash), 409);

                return ['accepted' => true, 'duplicate' => true, 'evidence' => 'STRIPE_TEST_CONTRACT'];
            }
            $review = in_array($event['type'], ['refund.updated', 'charge.dispute.created', 'charge.dispute.closed'], true);
            if ($review) {
                abort_unless(($pi['payment_intent'] ?? null) === $locked->provider_intent, 409);
                // Record provider evidence, but never invent returned funds or release settlement holds.
                $kind = 'provider_case_requires_review';
            } else {
                $expected = match ($event['type']) {
                    'payment_intent.succeeded' => 'succeeded', 'payment_intent.amount_capturable_updated' => 'requires_capture', 'payment_intent.payment_failed' => 'requires_payment_method', 'payment_intent.canceled' => 'canceled'
                };
                abort_unless(($pi['status'] ?? null) === $expected, 409, 'Corporate event type and provider state do not reconcile.');
                $this->applyIntent($locked, $pi, $s, $lines, $invoices);
                $kind = 'signed_'.str_replace('.', '_', $event['type']);
            }
            DB::table('corporate_hosted_events')->insert(['checkout_id' => $h->id, 'kind' => $kind, 'provider_event_id' => $eventId, 'fingerprint' => $hash, 'created_at' => now()]);

            return ['accepted' => true, 'review_required' => $review, 'evidence' => 'STRIPE_TEST_CONTRACT'];
        }, 3);
    }
}
