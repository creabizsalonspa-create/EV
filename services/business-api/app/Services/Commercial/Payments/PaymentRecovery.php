<?php

namespace App\Services\Commercial\Payments;

use App\Services\Commercial\SubscriptionCreditService;
use App\Services\Commercial\SubscriptionGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Durable operational journal; immutable invoices/payment journals remain the financial truth. */
class PaymentRecovery
{
    private static ?array $context = null;

    public static function providerKey(string $fallback): string
    {
        return self::$context ? 'recovery:'.self::$context['id'] : $fallback;
    }

    public static function metadata(): array
    {
        return self::$context ? ['creabiz_recovery' => (string) self::$context['id']] : [];
    }

    public static function sent(): void
    {
        if (! self::$context) {
            return;
        }
        abort_unless(DB::table('payment_recovery_intents')->where('id', self::$context['id'])->where('lease', self::$context['lease'])->where('lease_until', '>', now())->exists(), 409, 'Payment operation lease expired; reconcile before retrying.');
        self::$context['sent'] = true;
    }

    public static function observe(array $body): void
    {
        if (! self::$context) {
            return;
        }
        // Never retain PaymentMethod tokens, client secrets, card details or raw provider payloads.
        self::$context['observation'] = array_intersect_key($body, array_flip(['id', 'status', 'amount', 'amount_received', 'currency', 'payment_intent', 'livemode']));
    }

    public function run(string $scope, object $checkout, int $actor, string $kind, string $key, array $payload, callable $action): object
    {
        abort_unless(in_array($scope, ['charging', 'subscription'], true) && in_array($kind, ['authorize', 'capture', 'refund', 'cancel'], true), 422);
        if ($checkout->provider !== 'stripe_test') {
            return $action();
        }
        abort_if(app()->environment('production'), 503);
        // A caller-owned outer transaction would roll back the supposedly durable preparation.
        abort_unless(DB::transactionLevel() === 0 || app()->runningUnitTests(), 409, 'Invoke payment operations outside caller transactions.');
        $identity = hash('sha256', json_encode([$scope, $checkout->id, $kind, $kind === 'refund' ? $key : 'single'], JSON_THROW_ON_ERROR));
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        abort_if(DB::table('payment_recovery_intents')->where('identity', $identity)->where('status', 'executing')->where('lease_until', '>', now())->exists(), 409, 'Payment operation is executing; refresh its recovery state.');
        $lease = Str::random(48);
        $row = DB::transaction(function () use ($scope, $checkout, $actor, $kind, $identity, $fingerprint, $lease) {
            // Both billable gateways share this serialization policy; different checkouts remain independent.
            $table = $scope === 'charging' ? 'gateway_checkouts' : 'subscription_gateway_checkouts';
            if ($scope === 'charging') {
                DB::table('finance_invoices')->where('id', $checkout->invoice_id)->lockForUpdate()->firstOrFail();
            }
            DB::table($table)->where('id', $checkout->id)->lockForUpdate()->firstOrFail();
            $old = DB::table('payment_recovery_intents')->where('identity', $identity)->lockForUpdate()->first();
            if ($old) {
                abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, 'Recovery operation payload changed.');
                if ($old->status === 'completed') {
                    return $old;
                }
                abort_if($old->status === 'executing' && $old->lease_until > now()->toDateTimeString(), 409, 'Payment operation is executing; refresh its recovery state.');
                abort_if(in_array($old->status, ['uncertain', 'executing', 'needs_review'], true) && $old->retry_until <= now()->toDateTimeString(), 409, 'Provider retry window expired; signed evidence or reconciliation is required.');
            }
            if ($scope === 'charging') {
                abort_if(DB::table('payment_recovery_intents as p')->join('gateway_checkouts as c', 'c.id', '=', 'p.checkout_id')->where('p.scope', 'charging')->where('c.invoice_id', $checkout->invoice_id)->where('p.identity', '!=', $identity)->whereIn('p.status', ['executing', 'uncertain', 'needs_review'])->exists(), 409, 'An unresolved provider operation holds this invoice.');
            }
            abort_if(DB::table('payment_recovery_intents')->where('scope', $scope)->where('checkout_id', $checkout->id)->when($old, fn ($q) => $q->where('id', '!=', $old->id))->whereIn('status', ['executing', 'uncertain', 'needs_review'])->exists(), 409, 'Another unresolved payment operation blocks this checkout.');
            if (! $old) {
                $id = DB::table('payment_recovery_intents')->insertGetId(['identity' => $identity, 'scope' => $scope, 'checkout_id' => $checkout->id, 'actor_id' => $actor, 'kind' => $kind, 'fingerprint' => $fingerprint, 'provider' => 'stripe_test', 'currency' => $checkout->currency, 'amount_minor' => (int) ($payload[1] ?? $checkout->amount_minor), 'status' => 'prepared', 'retry_until' => now()->addHours(23), 'created_at' => now(), 'updated_at' => now()]);
                $old = DB::table('payment_recovery_intents')->find($id);
            }
            DB::table('payment_recovery_intents')->where('id', $old->id)->update(['status' => 'executing', 'lease' => $lease, 'lease_until' => now()->addMinutes(3), 'attempts' => $old->attempts + 1, 'updated_at' => now()]);
            $this->event($old->id, 'executing');

            $claimed = DB::table('payment_recovery_intents')->find($old->id);
            $claimed->prior_uncertain = in_array($old->status, ['uncertain', 'executing', 'needs_review'], true);

            return $claimed;
        }, 3);
        if ($row->status === 'completed') {
            return DB::table($scope === 'charging' ? 'gateway_checkouts' : 'subscription_gateway_checkouts')->find($checkout->id);
        }
        $prior = self::$context;
        self::$context = ['id' => $row->id, 'lease' => $lease, 'sent' => false, 'observation' => null, 'prior_uncertain' => $row->prior_uncertain];
        try {
            $result = $action();
            $this->finish($row->id, $lease, 'completed', self::$context['observation']);

            return $result;
        } catch (Throwable $error) {
            $this->finish($row->id, $lease, (self::$context['sent'] || self::$context['prior_uncertain']) ? 'uncertain' : 'rejected', self::$context['observation']);
            throw $error;
        } finally {
            self::$context = $prior;
        }
    }

    /** Internal boundary: invoke only after verified provider evidence and its ledger post, in one transaction. */
    public function resolveEvidence(string $scope, int $checkout, string $kind, array $evidence): void
    {
        $query = DB::table('payment_recovery_intents')->where('scope', $scope)->where('checkout_id', $checkout)->where('kind', $kind)->where('status', '!=', 'completed');
        foreach ($query->lockForUpdate()->get() as $intent) {
            if ((int) $intent->amount_minor !== ($evidence['amount'] ?? null) || $intent->currency !== ($evidence['currency'] ?? null)) {
                continue;
            }
            $observation = $intent->observation ? json_decode($intent->observation, true, 16, JSON_THROW_ON_ERROR) : [];
            if ($kind === 'refund' && (isset($evidence['recovery_id']) ? (string) $evidence['recovery_id'] !== (string) $intent->id : ($observation['id'] ?? null) !== ($evidence['id'] ?? null))) {
                continue;
            }
            if ($kind === 'refund' && ! is_string($evidence['id'] ?? null)) {
                continue;
            }
            DB::table('payment_recovery_intents')->where('id', $intent->id)->update(['status' => 'completed', 'lease' => null, 'lease_until' => null, 'observation' => json_encode(array_intersect_key($evidence, array_flip(['id', 'amount', 'currency'])), JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            $this->event($intent->id, 'completed');
        }
    }

    /** Bounded worker reconciles only committed local/provider evidence; it never sends payment mutations. */
    public function scan(int $limit = 100): int
    {
        $ids = DB::table('payment_recovery_intents')->whereIn('status', ['executing', 'uncertain', 'needs_review'])->where(function ($q) {
            $q->whereNull('lease_until')->orWhere('lease_until', '<=', now());
        })->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                $hint = DB::table('payment_recovery_intents')->find($id);
                $table = $hint->scope === 'charging' ? 'gateway_checkouts' : 'subscription_gateway_checkouts';
                $checkout = DB::table($table)->where('id', $hint->checkout_id)->lockForUpdate()->first();
                $intent = DB::table('payment_recovery_intents')->where('id', $id)->lockForUpdate()->first();
                if (! in_array($intent->status, ['executing', 'uncertain', 'needs_review'], true) || ($intent->lease_until && $intent->lease_until > now()->toDateTimeString())) {
                    return;
                }
                $operations = $hint->scope === 'charging' ? 'gateway_operations' : 'subscription_gateway_operations';
                $op = DB::table($operations)->where('checkout_id', $intent->checkout_id)->where('kind', $intent->kind);
                if ($intent->kind === 'refund') {
                    $observation = $intent->observation ? json_decode($intent->observation, true, 16, JSON_THROW_ON_ERROR) : [];
                    $op->where($hint->scope === 'charging' ? 'provider_reference' : 'provider_operation_reference', $observation['id'] ?? '');
                }
                $match = $op->where('amount_minor', $intent->amount_minor)->first();
                if ($hint->scope === 'subscription' && $intent->kind === 'refund') {
                    $match = DB::table('subscription_credits as c')->join('subscription_credit_requests as r', 'r.id', '=', 'c.request_id')->where('r.recovery_identity', $intent->identity)->where('c.amount_minor', $intent->amount_minor)->first();
                }
                if ($checkout && $match) {
                    DB::table('payment_recovery_intents')->where('id', $id)->update(['status' => 'completed', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
                    $this->event($id, 'completed');
                } elseif ($intent->status !== 'needs_review') {
                    DB::table('payment_recovery_intents')->where('id', $id)->update(['status' => 'needs_review', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
                    $this->event($id, 'needs_review');
                }
            }, 3);
        }

        return $ids->count();
    }

    public function reconcileProvider(int $id): object
    {
        abort_if(app()->environment('production'), 503);
        $intent = DB::table('payment_recovery_intents')->find($id);
        abort_unless($intent, 404);
        if ($intent->status === 'completed') {
            return $intent;
        }
        abort_if($intent->status === 'executing' && $intent->lease_until > now()->toDateTimeString(), 409, 'Payment operation is still executing.');
        if ($intent->scope === 'subscription' && $intent->kind === 'refund') {
            app(SubscriptionCreditService::class)->recoverFromProvider($intent);
        } elseif ($intent->scope === 'subscription') {
            app(SubscriptionGateway::class)->recoverFromProvider($intent);
        } else {
            app(GatewayService::class)->recoverFromProvider($intent);
        }

        return DB::table('payment_recovery_intents')->find($id);
    }

    public function applyCancellation(object $intent, array $evidence): array
    {
        abort_unless(($evidence['status'] ?? null) === 'canceled', 409, 'Provider cancellation is not final.');

        return DB::transaction(function () use ($intent, $evidence) {
            $charging = $intent->scope === 'charging';
            $table = $charging ? 'gateway_checkouts' : 'subscription_gateway_checkouts';
            $checkout = DB::table($table)->where('id', $intent->checkout_id)->lockForUpdate()->first();
            abort_unless($checkout && in_array($checkout->status, ['pending', 'authorized', 'action_required', 'cancelled'], true), 409, 'Captured payments cannot be cancelled.');
            $operations = $charging ? 'gateway_operations' : 'subscription_gateway_operations';
            $key = hash('sha256', 'recovered-cancel:'.$intent->id);
            if (! DB::table($operations)->where('request_key', $key)->exists()) {
                DB::table($operations)->insert(['checkout_id' => $checkout->id, 'kind' => 'cancel', 'request_key' => $key, 'fingerprint' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)), 'amount_minor' => $intent->amount_minor, $charging ? 'provider_reference' : 'provider_operation_reference' => 'recovered-cancel:'.$intent->id, 'created_at' => now()]);
            }
            DB::table($table)->where('id', $checkout->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->resolveEvidence($intent->scope, $checkout->id, 'cancel', ['id' => $evidence['id'], 'amount' => (int) $evidence['amount'], 'currency' => strtoupper($evidence['currency'])]);
            DB::table('audit_events')->insert(['actor_id' => $intent->actor_id, 'action' => 'finance.recovery.cancellation_observed', 'subject' => (string) $intent->id, 'created_at' => now()]);

            return ['accepted' => true, 'provenance' => 'TEST_PROVIDER_GET', 'real_money_moved' => false];
        }, 3);
    }

    private function finish(int $id, string $lease, string $status, ?array $observation): void
    {
        DB::transaction(function () use ($id, $lease, $status, $observation) {
            $changed = DB::table('payment_recovery_intents')->where('id', $id)->where('lease', $lease)->update(['status' => $status, 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
            if ($changed && $observation) {
                DB::table('payment_recovery_intents')->where('id', $id)->update(['observation' => json_encode($observation, JSON_THROW_ON_ERROR)]);
            }
            if ($changed) {
                $this->event($id, $status);
            }
        });
    }

    private function event(int $id, string $status): void
    {
        DB::table('payment_recovery_events')->insert(['intent_id' => $id, 'status' => $status, 'created_at' => now()]);
    }
}
