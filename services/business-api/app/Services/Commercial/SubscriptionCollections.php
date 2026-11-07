<?php

namespace App\Services\Commercial;

use App\Models\User;
use App\Services\Commercial\Payments\PaymentRecovery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Bounded orchestration only; canonical gateway/recovery/receipts own all funds. */
class SubscriptionCollections
{
    public function policy(User $user, int $subscription, bool $enabled, ?int $method, int $expected): object
    {
        return DB::transaction(function () use ($user, $subscription, $enabled, $method, $expected) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $s = app(SubscriptionService::class)->access($user, $subscription, true);
            abort_unless($s->user_id === $user->id, 404);
            $old = DB::table('subscription_collection_policies')->where('subscription_id', $subscription)->first();
            abort_unless(($old->version ?? 0) === $expected, 409, 'Collection policy changed; refresh first.');
            if ($enabled) {
                abort_unless(config('payments.collection_enabled') && config('payments.provider') === 'stripe_test' && ! app()->environment('production'), 503, 'Automatic test-provider collection is not enabled.');
                abort_unless($s->status !== 'cancelled' && $method, 409);
                $m = app(PaymentMandates::class)->method($user, $method, true);
                app(PaymentMandates::class)->credentials($user, $method, $m->version);
            }
            $values = ['user_id' => $user->id, 'mandate_id' => $enabled ? $method : ($old->mandate_id ?? null), 'enabled' => $enabled, 'version' => ($old->version ?? 0) + 1, 'updated_at' => now()];
            if ($old) {
                DB::table('subscription_collection_policies')->where('id', $old->id)->update($values);
            } else {
                DB::table('subscription_collection_policies')->insert($values + ['subscription_id' => $subscription, 'created_at' => now()]);
            }
            if ($enabled) {
                // Fresh explicit consent may resume a definitively failed/cancelled
                // attempt, never an unresolved provider operation or paid invoice.
                $jobs = DB::table('subscription_collections')->where('subscription_id', $subscription)->whereIn('state', ['cancelled', 'permanent_failure'])->get();
                foreach ($jobs as $job) {
                    $checkout = DB::table('subscription_gateway_checkouts')->find($job->checkout_id);
                    $unknown = DB::table('payment_recovery_intents')->where('scope', 'subscription')->where('checkout_id', $job->checkout_id)->whereIn('status', ['executing', 'uncertain', 'needs_review'])->exists();
                    if ($checkout && in_array($checkout->status, ['failed', 'cancelled'], true) && ! $unknown && ! DB::table('commercial_subscription_receipts')->where('invoice_id', $job->invoice_id)->exists()) {
                        DB::table('subscription_collections')->where('id', $job->id)->update(['state' => 'retry_scheduled', 'next_attempt_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
            app(PaymentMandates::class)->event($user->id, 'subscription:'.$subscription, $enabled ? 'recurring_consented' : 'recurring_disabled');

            return DB::table('subscription_collection_policies')->where('subscription_id', $subscription)->first();
        }, 3);
    }

    public function authorize(User $user, object $checkout): array
    {
        abort_unless(config('payments.collection_enabled') && config('payments.provider') === 'stripe_test' && ! app()->environment('production'), 503);
        $p = DB::table('subscription_collection_policies')->where('subscription_id', $checkout->subscription_id)->where('user_id', $user->id)->lockForUpdate()->first();
        abort_unless($p && $p->enabled && $p->version === $checkout->policy_version && $p->mandate_id === $checkout->mandate_id, 409, 'Recurring consent changed; reconcile the existing checkout.');

        return app(PaymentMandates::class)->credentials($user, $checkout->mandate_id, $checkout->mandate_version);
    }

    private function finish(int $id, string $lease, string $state, ?string $failure = null, ?int $delay = null): void
    {
        DB::transaction(function () use ($id, $lease, $state, $failure, $delay) {
            $j = DB::table('subscription_collections')->where('id', $id)->where('lease', $lease)->lockForUpdate()->first();
            if (! $j) {
                return;
            }
            DB::table('subscription_collections')->where('id', $id)->update(['state' => $state, 'failure_code' => $failure, 'next_attempt_at' => $delay ? now()->addDays($delay) : null, 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
            app(PaymentMandates::class)->event($j->user_id, 'collection:'.$id, $state);
        });
    }

    public function collect(int $invoice): void
    {
        $lease = Str::random(48);
        $job = DB::transaction(function () use ($invoice, $lease) {
            $i = DB::table('commercial_subscription_invoices')->find($invoice);
            if (! $i) {
                return null;
            }
            User::whereKey(DB::table('commercial_subscriptions')->where('id', $i->subscription_id)->value('user_id'))->lockForUpdate()->firstOrFail();
            $s = DB::table('commercial_subscriptions')->where('id', $i->subscription_id)->lockForUpdate()->first();
            if (DB::table('commercial_subscription_receipts')->where('invoice_id', $invoice)->exists()) {
                DB::table('subscription_collections')->where('invoice_id', $invoice)->update(['state' => 'collected', 'lease' => null, 'lease_until' => null, 'next_attempt_at' => null, 'updated_at' => now()]);

                return null;
            }
            $j = DB::table('subscription_collections')->where('invoice_id', $invoice)->lockForUpdate()->first();
            if ($j && (($j->lease_until && CarbonImmutable::parse($j->lease_until)->isFuture()) || ($j->next_attempt_at && CarbonImmutable::parse($j->next_attempt_at)->isFuture()) || in_array($j->state, ['permanent_failure', 'collected', 'cancelled'], true))) {
                return null;
            }
            $p = DB::table('subscription_collection_policies')->where('subscription_id', $s->id)->first();
            if (! $p || ! $p->enabled || $s->status === 'cancelled' || $s->cancel_at_period_end || (int) $i->cycle_number !== (int) $s->cycle_number + 1 || (int) $i->total_minor <= 0 || CarbonImmutable::parse($i->period_start)->isFuture()) {
                // An existing uncertain/authorized checkout still needs cancellation
                // or source reconciliation; never manufacture a terminal outcome.
                if (! $j || ! $j->checkout_id) {
                    return null;
                }
            }
            if (! $j) {
                $id = DB::table('subscription_collections')->insertGetId(['invoice_id' => $invoice, 'subscription_id' => $s->id, 'user_id' => $s->user_id, 'state' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
                $j = DB::table('subscription_collections')->find($id);
            }
            if (! $j->checkout_id || $j->state === 'retry_scheduled') {
                if (! $p || ! $p->enabled || $s->status === 'cancelled' || $s->cancel_at_period_end) {
                    return null;
                }
                try {
                    $method = app(PaymentMandates::class)->method(User::findOrFail($s->user_id), (int) $p->mandate_id, true);
                    app(PaymentMandates::class)->credentials(User::findOrFail($s->user_id), $method->id, $method->version);
                } catch (\Throwable $e) {
                    DB::table('subscription_collections')->where('id', $j->id)->update(['state' => 'payment_method_required', 'failure_code' => 'consent_unavailable', 'updated_at' => now()]);

                    return null;
                }
                $attempt = $j->attempts + 1;
                $checkout = app(SubscriptionGateway::class)->checkout(User::findOrFail($s->user_id), $s->id, $invoice, 'collection:'.$j->id.':'.$attempt.':policy:'.$p->version, $method->id, $p->version);
                DB::table('subscription_collections')->where('id', $j->id)->update(['checkout_id' => $checkout->id, 'attempts' => $attempt, 'updated_at' => now()]);
            }
            DB::table('subscription_collections')->where('id', $j->id)->update(['lease' => $lease, 'lease_until' => now()->addMinutes(3), 'state' => 'executing', 'updated_at' => now()]);

            return DB::table('subscription_collections')->find($j->id);
        }, 3);
        if (! $job) {
            return;
        }
        $user = User::findOrFail($job->user_id);
        $gateway = app(SubscriptionGateway::class);
        try {
            $p = DB::table('subscription_collection_policies')->where('subscription_id', $job->subscription_id)->first();
            $s = DB::table('commercial_subscriptions')->find($job->subscription_id);
            $invoiceRow = DB::table('commercial_subscription_invoices')->find($job->invoice_id);
            $allowed = $p && $p->enabled && $s->status !== 'cancelled' && ! $s->cancel_at_period_end && ! CarbonImmutable::parse($invoiceRow->period_start)->isFuture();
            $c = $job->checkout_id ? DB::table('subscription_gateway_checkouts')->find($job->checkout_id) : null;
            if ($c) {
                $unresolved = DB::table('payment_recovery_intents')->where('scope', 'subscription')->where('checkout_id', $c->id)->whereIn('status', ['executing', 'uncertain', 'needs_review'])->get();
                foreach ($unresolved as $intent) {
                    app(PaymentRecovery::class)->reconcileProvider($intent->id);
                }
                $c = DB::table('subscription_gateway_checkouts')->find($c->id);
                if ($c->status === 'captured') {
                    $this->finish($job->id, $lease, 'collected');

                    return;
                }
                if (! $allowed || ! $this->stillConsented($user, $c)) {
                    if (in_array($c->status, ['pending', 'authorized', 'action_required'], true)) {
                        $gateway->operate($user, $c->id, 'cancel', 'collection-cancel:'.$c->id, null);
                    }
                    $canResume = false;
                    if ($allowed && $p->version !== $c->policy_version) {
                        try {
                            $m = app(PaymentMandates::class)->method($user, $p->mandate_id);
                            app(PaymentMandates::class)->credentials($user, $m->id, $m->version);
                            $canResume = true;
                        } catch (\Throwable $e) {
                        }
                    }
                    $this->finish($job->id, $lease, $canResume ? 'retry_scheduled' : 'cancelled', 'consent_unavailable');

                    return;
                }
            }
            if (! $allowed) {
                $this->finish($job->id, $lease, 'cancelled', 'consent_unavailable');

                return;
            }
            if (! $c) {
                $this->finish($job->id, $lease, 'recovery_required', 'checkout_unresolved');

                return;
            }
            if ($c->status === 'pending') {
                $c = $gateway->operate($user, $c->id, 'authorize', 'collection-authorize:'.$c->id, null);
            }
            if ($c->status === 'authorized') {
                $c = $gateway->operate($user, $c->id, 'capture', 'collection-capture:'.$c->id, null);
            }
            if ($c->status === 'captured') {
                $this->finish($job->id, $lease, 'collected');
            } elseif ($c->status === 'action_required') {
                $this->finish($job->id, $lease, 'action_required', 'authentication_required');
            } elseif ($c->status === 'failed') {
                $days = array_values(config('payments.collection_retry_days', [1, 3, 7]));
                $retry = in_array($c->failure_code, ['insufficient_funds', 'processing_error'], true) && isset($days[$job->attempts - 1]);
                $this->finish($job->id, $lease, $retry ? 'retry_scheduled' : 'permanent_failure', $c->failure_code, $retry ? (int) $days[$job->attempts - 1] : null);
            } else {
                $this->finish($job->id, $lease, 'recovery_required');
            }
        } catch (\Throwable $e) {
            $this->finish($job->id, $lease, 'recovery_required', 'provider_or_state_unresolved');
        }
    }

    private function stillConsented(User $user, object $c): bool
    {
        try {
            $this->authorize($user, $c);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function scan(int $limit): int
    {
        abort_unless(config('payments.collection_enabled') && config('payments.provider') === 'stripe_test' && ! app()->environment('production'), 503);
        $days = config('payments.collection_retry_days');
        abort_unless(is_array($days) && count($days) <= 5 && count($days) > 0 && collect($days)->every(fn ($n) => is_int($n) && $n >= 1 && $n <= 30), 503, 'Configure bounded retry days.');
        $subscriptions = DB::table('subscription_collection_policies as p')->join('commercial_subscriptions as s', 's.id', '=', 'p.subscription_id')->where('p.enabled', true)->where('s.status', '!=', 'cancelled')->whereNotNull('s.period_end')->where('s.period_end', '<=', now())->whereNotExists(fn ($q) => $q->selectRaw('1')->from('commercial_subscription_invoices as next')->whereColumn('next.subscription_id', 's.id')->whereColumn('next.cycle_number', DB::raw('s.cycle_number + 1')))->orderBy('s.id')->limit($limit)->pluck('s.id');
        foreach ($subscriptions as $id) {
            app(SubscriptionService::class)->renew($id);
        }
        $settled = DB::table('subscription_collections as j')->join('commercial_subscription_receipts as r', 'r.invoice_id', '=', 'j.invoice_id')->where('j.state', '!=', 'collected')->orderBy('j.id')->limit($limit)->pluck('j.invoice_id');
        foreach ($settled as $id) {
            $this->collect($id);
        }
        $ids = DB::table('commercial_subscription_invoices as i')->join('commercial_subscriptions as s', 's.id', '=', 'i.subscription_id')->join('subscription_collection_policies as p', 'p.subscription_id', '=', 's.id')->leftJoin('subscription_collections as j', 'j.invoice_id', '=', 'i.id')
            ->where(function ($q) {
                $q->where('p.enabled', true)->orWhereNotNull('j.checkout_id');
            })->whereColumn('i.cycle_number', DB::raw('s.cycle_number + 1'))->where('i.period_start', '<=', now())->where('i.total_minor', '>', 0)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('commercial_subscription_receipts as r')->whereColumn('r.invoice_id', 'i.id'))
            ->where(fn ($q) => $q->whereNull('j.state')->orWhereNotIn('j.state', ['collected', 'cancelled', 'permanent_failure']))
            ->where(fn ($q) => $q->whereNull('j.next_attempt_at')->orWhere('j.next_attempt_at', '<=', now()))->orderByRaw('COALESCE(j.updated_at, i.created_at) ASC')->orderBy('i.id')->limit($limit)->pluck('i.id');
        foreach ($ids as $id) {
            $this->collect($id);
        }

        return $ids->count();
    }
}
