<?php

namespace App\Services\Commercial\Payouts;

use App\Models\User;
use App\Services\Commercial\SettlementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PayoutService
{
    public function enabled(): void
    {
        abort_unless(config('payouts.enabled') && ! app()->environment('production'), 503, 'Test payout execution is disabled; real payouts are prohibited.');
    }

    public function provider(string $name): PayoutProvider
    {
        abort_unless(in_array($name, ['mock', 'stripe_test'], true), 422);

        return app($name === 'mock' ? MockPayoutProvider::class : StripeTestPayoutProvider::class);
    }

    public function account(int $id): object
    {
        $row = DB::table('commercial_payout_accounts')->find($id);
        abort_unless($row, 404);
        $row->account_reference = Crypt::decryptString($row->account_reference);
        $row->bank_reference = Crypt::decryptString($row->bank_reference);

        return $row;
    }

    public function publicAccount(object $row): array
    {
        $safe = (array) $row;
        unset($safe['account_reference'], $safe['bank_reference'], $safe['fingerprint'], $safe['request_key']);
        $safe['required_consent_version'] = config('payouts.consent_version');
        $safe['real_money_moved'] = false;
        $safe['evidence'] = $row->provider === 'mock' ? 'MOCK_PAYOUT_ACCOUNT' : 'STRIPE_TEST_ACCOUNT';

        return $safe;
    }

    public function configure(int $vendor, int $actor, array $v): object
    {
        $this->enabled();

        return DB::transaction(function () use ($vendor, $actor, $v) {
            User::whereKey($actor)->lockForUpdate()->firstOrFail();
            User::whereKey($vendor)->where('role', 'vendor')->lockForUpdate()->firstOrFail();
            $key = hash('sha256', $actor.':'.$v['idempotency_key']);
            $fingerprint = hash('sha256', json_encode([$vendor, $v['provider'], $v['currency'], $v['account_reference'], $v['bank_reference']], JSON_THROW_ON_ERROR));
            if ($old = DB::table('commercial_payout_accounts')->where('request_key', $key)->first()) {
                abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, 'Payout account key payload changed.');

                return $old;
            }
            $id = DB::table('commercial_payout_accounts')->insertGetId(['vendor_id' => $vendor, 'provider' => $v['provider'], 'currency' => $v['currency'], 'minor_units' => app(SettlementService::class)->currencyPrecision($vendor, $v['currency']), 'account_reference' => Crypt::encryptString($v['account_reference']), 'bank_reference' => Crypt::encryptString($v['bank_reference']), 'fingerprint' => $fingerprint, 'request_key' => $key, 'status' => 'pending_consent', 'configured_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            $this->event(null, $id, $actor, 'account.configured');

            return DB::table('commercial_payout_accounts')->find($id);
        }, 3);
    }

    public function consent(int $id, int $actor, string $version): object
    {
        $this->enabled();

        return DB::transaction(function () use ($id, $actor, $version) {
            $hint = $this->account($id);
            User::whereKey($hint->vendor_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('commercial_payout_accounts')->where('id', $id)->lockForUpdate()->first();
            abort_unless((int) $row->vendor_id === $actor && $version === config('payouts.consent_version'), 403);
            abort_if($row->status === 'revoked', 409, 'A revoked payout account cannot be reused.');
            if (! $row->consented_at) {
                DB::table('commercial_payout_accounts')->where('id', $id)->update(['consented_by' => $actor, 'consent_version' => $version, 'consented_at' => now(), 'status' => 'pending_verification', 'updated_at' => now()]);
                $this->event(null, $id, $actor, 'account.consented');
            }

            return DB::table('commercial_payout_accounts')->find($id);
        }, 3);
    }

    public function verify(int $id, int $actor): object
    {
        $this->enabled();
        $account = $this->account($id);
        abort_unless(in_array($account->status, ['pending_verification', 'active'], true) && $account->consented_at && $account->consent_version === config('payouts.consent_version'), 409, 'Vendor consent is required.');
        $this->provider($account->provider)->verify($account);

        return DB::transaction(function () use ($account, $id, $actor) {
            User::whereKey($account->vendor_id)->lockForUpdate()->firstOrFail();
            $fresh = DB::table('commercial_payout_accounts')->where('id', $id)->lockForUpdate()->first();
            abort_if($fresh->status === 'revoked', 409);
            DB::table('commercial_payout_accounts')->where('id', $id)->update(['status' => 'active', 'verified_by' => $actor, 'verified_at' => now(), 'updated_at' => now()]);
            $this->event(null, $id, $actor, 'account.verified');

            return DB::table('commercial_payout_accounts')->find($id);
        }, 3);
    }

    public function revoke(int $id, int $actor): object
    {
        $this->enabled();

        return DB::transaction(function () use ($id, $actor) {
            $hint = $this->account($id);
            User::whereKey($hint->vendor_id)->lockForUpdate()->firstOrFail();
            $row = DB::table('commercial_payout_accounts')->where('id', $id)->lockForUpdate()->first();
            if ($row->status !== 'revoked') {
                DB::table('commercial_payout_accounts')->where('id', $id)->update(['status' => 'revoked', 'revoked_at' => now(), 'updated_at' => now()]);
                $this->event(null, $id, $actor, 'account.revoked');
            }

            return DB::table('commercial_payout_accounts')->find($id);
        }, 3);
    }

    public function onboarding(int $id, int $actor, string $key): array
    {
        $this->enabled();
        $account = $this->account($id);
        abort_unless((int) $account->vendor_id === $actor, 404);
        abort_unless($account->provider === 'stripe_test' && in_array($account->status, ['pending_verification', 'active'], true) && $account->consented_at && $account->consent_version === config('payouts.consent_version'), 409, 'Active owner consent for a Stripe TEST recipient is required.');
        $link = app(StripeTestPayoutProvider::class)->onboarding($account, hash('sha256', $actor.':'.$key));
        // Returning from the hosted page never verifies the bank or approves any payout.
        $this->event(null, $id, $actor, 'account.onboarding_link_requested');

        return $link;
    }

    /** Caller must lock the vendor and settlement first. No external I/O occurs here. */
    private function eligible(object $settlement): void
    {
        abort_unless($settlement->state === 'approved', 409, 'Only an approved unexecuted settlement can initiate a payout.');
        $schedule = DB::table('commercial_settlement_schedules')->where('vendor_id', $settlement->vendor_id)->where('currency', $settlement->currency)->first();
        abort_unless($schedule && $schedule->enabled, 409, 'Settlement schedule is disabled.');
        $service = app(SettlementService::class);
        $balance = $service->balances((int) $settlement->vendor_id, $settlement->currency, (int) $schedule->hold_days, true);
        abort_unless($balance['minor_units'] === (int) $settlement->minor_units && $balance['available_minor'] >= 0, 409, 'Payable debt, precision or adjustment requires reconciliation.');
        $lines = DB::table('commercial_settlement_lines')->where('settlement_id', $settlement->id)->get();
        abort_unless((int) $lines->sum('amount_minor') === (int) $settlement->amount_minor, 409, 'Settlement lines do not reconcile.');
        foreach ($lines->where('amount_minor', '>', 0)->whereNotNull('invoice_id') as $line) {
            $invoice = DB::table('finance_invoices')->where('id', $line->invoice_id)->first();
            abort_unless(Carbon::parse($invoice->created_at)->addDays((int) $schedule->hold_days)->lte(now()), 409, 'Invoice holding period has not elapsed.');
            abort_if(DB::table('finance_refund_requests')->where('invoice_id', $line->invoice_id)->where('status', 'pending')->exists() || DB::table('gateway_cases')->where('invoice_id', $line->invoice_id)->where('status', 'open')->exists() || DB::table('payment_recovery_intents as p')->join('gateway_checkouts as c', 'c.id', '=', 'p.checkout_id')->where('p.scope', 'charging')->where('c.invoice_id', $line->invoice_id)->whereIn('p.status', ['executing', 'uncertain', 'needs_review'])->exists(), 409, 'Unresolved refund, dispute or payment evidence holds the payout.');
        }
    }

    private function lock(int $id): array
    {
        $hint = DB::table('commercial_payouts')->find($id);
        abort_unless($hint, 404);
        User::whereKey($hint->vendor_id)->lockForUpdate()->firstOrFail();
        $settlement = DB::table('commercial_settlements')->where('id', $hint->settlement_id)->lockForUpdate()->first();
        $payout = DB::table('commercial_payouts')->where('id', $id)->lockForUpdate()->first();

        return [$payout, $settlement];
    }

    public function create(int $settlementId, int $accountId, int $actor, string $key): object
    {
        $this->enabled();

        return DB::transaction(function () use ($settlementId, $accountId, $actor, $key) {
            User::whereKey($actor)->lockForUpdate()->firstOrFail();
            $hint = DB::table('commercial_settlements')->find($settlementId);
            abort_unless($hint, 404);
            User::whereKey($hint->vendor_id)->lockForUpdate()->firstOrFail();
            $settlement = DB::table('commercial_settlements')->where('id', $settlementId)->lockForUpdate()->first();
            $requestKey = hash('sha256', $actor.':'.$key);
            if ($old = DB::table('commercial_payouts')->where('request_key', $requestKey)->first()) {
                abort_unless((int) $old->settlement_id === $settlementId && (int) $old->account_id === $accountId, 409, 'Payout request key changed.');

                return $old;
            }
            abort_if(DB::table('commercial_payouts')->where('settlement_id', $settlementId)->exists(), 409, 'This settlement already has a payout; use its existing recovery/retry workflow.');
            $account = DB::table('commercial_payout_accounts')->where('id', $accountId)->lockForUpdate()->first();
            abort_unless($account && $account->status === 'active' && (int) $account->vendor_id === (int) $settlement->vendor_id && $account->currency === $settlement->currency && (int) $account->minor_units === (int) $settlement->minor_units, 409, 'An active consenting vendor account with matching currency is required.');
            $this->eligible($settlement);
            $id = DB::table('commercial_payouts')->insertGetId(['reference' => (string) Str::ulid(), 'settlement_id' => $settlementId, 'account_id' => $accountId, 'vendor_id' => $settlement->vendor_id, 'provider' => $account->provider, 'currency' => $settlement->currency, 'minor_units' => $settlement->minor_units, 'amount_minor' => $settlement->amount_minor, 'request_key' => $requestKey, 'status' => 'prepared', 'created_by' => $actor, 'evidence' => $account->provider === 'mock' ? 'MOCK_PAYOUT' : 'STRIPE_TEST_HTTP', 'created_at' => now(), 'updated_at' => now()]);
            $this->event($id, $accountId, $actor, 'payout.prepared');

            return DB::table('commercial_payouts')->find($id);
        }, 3);
    }

    public function approve(int $id, int $actor): object
    {
        $this->enabled();

        return DB::transaction(function () use ($id, $actor) {
            [$row, $settlement] = $this->lock($id);
            abort_if((int) $row->created_by === $actor, 403, 'Another administrator must approve the payout.');
            if ($row->status === 'approved') {
                return $row;
            }
            abort_unless($row->status === 'prepared', 409);
            $this->eligible($settlement);
            DB::table('commercial_payouts')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $actor, 'approved_at' => now(), 'updated_at' => now()]);
            $this->event($id, $row->account_id, $actor, 'payout.approved');

            return DB::table('commercial_payouts')->find($id);
        }, 3);
    }

    public function execute(int $id, int $actor): object
    {
        $this->enabled();
        abort_unless(DB::transactionLevel() === 0 || app()->runningUnitTests(), 409, 'Payout execution must run outside caller transactions.');
        $lease = Str::random(48);
        [$payout, $attempt, $done] = DB::transaction(function () use ($id, $actor, $lease) {
            [$row, $settlement] = $this->lock($id);
            if (in_array($row->status, ['paid', 'pending'], true)) {
                return [$row, null, true];
            }
            $attempt = DB::table('commercial_payout_attempts')->where('payout_id', $id)->orderByDesc('number')->lockForUpdate()->first();
            if ($row->status === 'executing') {
                abort_if($attempt && $attempt->lease_until > now()->toDateTimeString(), 409, 'Payout request is still executing.');
            }
            abort_unless(in_array($row->status, ['approved', 'uncertain', 'executing'], true) && $row->approved_by && (int) $row->approved_by !== (int) $row->created_by, 409, 'Payout approval or reconciliation is required.');
            $this->eligible($settlement);
            if ($row->status === 'approved') {
                $number = (int) $row->attempt_number + 1;
                $attemptId = DB::table('commercial_payout_attempts')->insertGetId(['payout_id' => $id, 'number' => $number, 'reference' => (string) Str::ulid(), 'status' => 'prepared', 'retry_until' => now()->addHours(23), 'created_at' => now(), 'updated_at' => now()]);
                $attempt = DB::table('commercial_payout_attempts')->find($attemptId);
                DB::table('commercial_payouts')->where('id', $id)->update(['attempt_number' => $number]);
            } else {
                abort_unless($attempt && $attempt->retry_until > now()->toDateTimeString(), 409, 'Retry window expired; provider reconciliation is required.');
            }
            DB::table('commercial_payout_attempts')->where('id', $attempt->id)->update(['status' => 'executing', 'lease' => $lease, 'lease_until' => now()->addMinutes(3), 'updated_at' => now()]);
            DB::table('commercial_payouts')->where('id', $id)->update(['status' => 'executing', 'updated_at' => now()]);
            $this->event($id, $row->account_id, $actor, 'payout.executing');

            return [DB::table('commercial_payouts')->find($id), DB::table('commercial_payout_attempts')->find($attempt->id), false];
        }, 3);
        if ($done) {
            return $payout;
        }
        $sent = (bool) $attempt->request_sent;
        try {
            $account = $this->account((int) $payout->account_id);
            abort_unless($account->status === 'active' && $account->consent_version === config('payouts.consent_version'), 409, 'Payout account consent is no longer active.');
            $provider = $this->provider($payout->provider);
            $provider->verify($account);
            if (! $sent) {
                abort_unless($provider->balance($account) >= (int) $payout->amount_minor, 409, 'The approved connected TEST account must be pre-funded before a bank payout.');
            }
            DB::transaction(function () use ($id, $attempt, $lease) {
                [$row, $settlement] = $this->lock($id);
                $this->eligible($settlement);
                abort_unless(DB::table('commercial_payout_accounts')->where('id', $row->account_id)->where('status', 'active')->exists(), 409, 'Payout account has been revoked.');
                $changed = DB::table('commercial_payout_attempts')->where('id', $attempt->id)->where('lease', $lease)->where('lease_until', '>', now())->update(['request_sent' => true, 'updated_at' => now()]);
                abort_unless($changed, 409, 'Payout request lease expired; reconcile before retrying.');
            }, 3);
            $sent = true;
            $result = $provider->create($payout, $account, $attempt);
            $this->observe($payout, $attempt, $result, $actor, false);
        } catch (Throwable $error) {
            $status = $error instanceof PayoutRejected ? 'failed' : ($sent ? 'uncertain' : 'failed');
            DB::transaction(function () use ($id, $attempt, $lease, $status, $actor, $error) {
                [$row] = $this->lock($id);
                $changed = DB::table('commercial_payout_attempts')->where('id', $attempt->id)->where('lease', $lease)->update(['status' => $status, 'lease' => null, 'lease_until' => null, 'failure_code' => $error instanceof PayoutRejected ? $error->failureCode : ($status === 'failed' ? 'preflight_rejected' : 'outcome_unknown'), 'updated_at' => now()]);
                if ($changed) {
                    DB::table('commercial_payouts')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
                    $this->event($id, $row->account_id, $actor, 'payout.'.$status);
                }
            }, 3);
            if (! $error instanceof PayoutRejected) {
                abort(503, $status === 'uncertain' ? 'Payout outcome is uncertain; reconcile or replay the same attempt before any replacement.' : 'Payout preflight failed; no new request was sent. Review account, payable and funding readiness.');
            }
        }

        return DB::table('commercial_payouts')->find($id);
    }

    public function reconcile(int $id, int $actor): object
    {
        $this->enabled();
        $payout = DB::table('commercial_payouts')->find($id);
        abort_unless($payout, 404);
        $attempt = DB::table('commercial_payout_attempts')->where('payout_id', $id)->orderByDesc('number')->first();
        abort_unless($attempt, 409, 'No provider attempt exists.');
        abort_if($attempt->status === 'executing' && $attempt->lease_until > now()->toDateTimeString(), 409, 'Payout request is still executing.');
        $result = $this->provider($payout->provider)->retrieve($payout, $this->account((int) $payout->account_id), $attempt);
        if ($result) {
            $this->observe($payout, $attempt, $result, $actor, true);
        } else {
            DB::transaction(function () use ($payout, $attempt, $actor) {
                [$fresh] = $this->lock($payout->id);
                if (in_array($fresh->status, ['executing', 'uncertain'], true)) {
                    DB::table('commercial_payouts')->where('id', $payout->id)->update(['status' => 'uncertain', 'updated_at' => now()]);
                    DB::table('commercial_payout_attempts')->where('id', $attempt->id)->update(['status' => 'uncertain', 'lease' => null, 'lease_until' => null, 'updated_at' => now()]);
                    $this->event($payout->id, $payout->account_id, $actor, 'payout.not_found_unresolved');
                }
            }, 3);
        }

        return DB::table('commercial_payouts')->find($id);
    }

    /** Verified source facts are retained even if a refund changed eligibility after outbound dispatch. */
    public function observe(object $hint, object $attempt, array $result, ?int $actor, bool $authoritative): void
    {
        abort_unless(($result['attempt_reference'] ?? null) === $attempt->reference && ($result['amount'] ?? null) === (int) $hint->amount_minor && ($result['currency'] ?? null) === $hint->currency && is_string($result['id'] ?? null), 409, 'Payout evidence mismatch.');
        DB::transaction(function () use ($hint, $attempt, $result, $actor, $authoritative) {
            [$row, $settlement] = $this->lock($hint->id);
            $fresh = DB::table('commercial_payout_attempts')->where('id', $attempt->id)->lockForUpdate()->first();
            abort_unless(! $fresh->provider_reference || $fresh->provider_reference === $result['id'], 409, 'A different provider payout already owns this attempt.');
            abort_if(DB::table('commercial_payout_attempts')->where('provider_reference', $result['id'])->where('id', '!=', $fresh->id)->exists(), 409, 'A provider payout identity already belongs to another attempt; operational review is required.');
            $status = match ($result['status'] ?? '') {
                'paid' => 'paid', 'pending','in_transit' => 'pending', 'failed','canceled' => 'failed', default => abort(409, 'Unknown provider payout status.')
            };
            if ((int) $row->attempt_number !== (int) $fresh->number) {
                $this->event($row->id, $row->account_id, $actor, 'payout.late_attempt_evidence', $result['id']);
                if ($status === 'paid') {
                    DB::table('commercial_payouts')->where('id', $row->id)->update(['status' => 'needs_review', 'updated_at' => now()]);
                }

                return;
            }
            if (($fresh->status === 'paid' || $fresh->status === 'reversed') && $status === 'pending') {
                return;
            }
            if ($fresh->status === 'failed' && $status === 'paid' && ! $authoritative) {
                return;
            }
            if ($fresh->status === 'reversed' && in_array($status, ['paid', 'failed'], true)) {
                return;
            }
            if ($fresh->status === 'paid' && $status === 'failed') {
                if (! $authoritative) {
                    return;
                }
                $status = 'reversed';
            }
            $changed = $fresh->status !== $status || $fresh->provider_reference !== $result['id'];
            DB::table('commercial_payout_attempts')->where('id', $fresh->id)->update(['status' => $status, 'provider_reference' => $result['id'], 'lease' => null, 'lease_until' => null, 'failure_code' => $result['failure_code'] ?? null, 'observed_at' => now(), 'updated_at' => now()]);
            DB::table('commercial_payouts')->where('id', $row->id)->update(['status' => $status, 'updated_at' => now()]);
            if ($status === 'paid' && $settlement->state !== 'provider_paid') {
                DB::table('commercial_settlements')->where('id', $settlement->id)->update(['state' => 'provider_paid', 'executed_at' => now(), 'updated_at' => now()]);
                $schedule = DB::table('commercial_settlement_schedules')->where('vendor_id', $row->vendor_id)->where('currency', $row->currency)->first();
                if ($schedule) {
                    DB::table('commercial_settlement_schedules')->where('id', $schedule->id)->update(['next_due_at' => now()->addDays((int) $schedule->interval_days), 'updated_at' => now()]);
                }
            } elseif ($status === 'reversed' && $settlement->state === 'provider_paid') {
                DB::table('commercial_settlements')->where('id', $settlement->id)->update(['state' => 'approved', 'executed_at' => null, 'updated_at' => now()]);
            }
            if ($changed) {
                $this->event($row->id, $row->account_id, $actor, 'payout.'.$status, $result['id'], $result['failure_code'] ?? null);
                DB::table('commercial_settlement_events')->insert(['settlement_id' => $settlement->id, 'actor_id' => $actor ?? $row->approved_by, 'action' => 'payout.'.$status, 'created_at' => now()]);
            }
        }, 3);
    }

    public function retry(int $id, int $actor): object
    {
        $this->enabled();

        return DB::transaction(function () use ($id, $actor) {
            [$row, $settlement] = $this->lock($id);
            abort_unless(in_array($row->status, ['failed', 'reversed'], true), 409, 'Only a known failed or reversed payout may create a replacement attempt.');
            $this->eligible($settlement);
            DB::table('commercial_payouts')->where('id', $id)->update(['status' => 'prepared', 'created_by' => $actor, 'approved_by' => null, 'approved_at' => null, 'updated_at' => now()]);
            $this->event($id, $row->account_id, $actor, 'payout.retry_prepared');

            return DB::table('commercial_payouts')->find($id);
        }, 3);
    }

    public function cancel(int $id, int $actor): object
    {
        $this->enabled();

        return DB::transaction(function () use ($id, $actor) {
            [$row] = $this->lock($id);
            if ($row->status === 'cancelled') {
                return $row;
            }
            abort_unless(in_array($row->status, ['prepared', 'approved', 'failed', 'reversed'], true), 409, 'Unresolved or paid payouts cannot be cancelled locally.');
            DB::table('commercial_payouts')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->event($id, $row->account_id, $actor, 'payout.cancelled');

            return DB::table('commercial_payouts')->find($id);
        }, 3);
    }

    /** Bounded operational recovery; retrieves provider facts and never creates a payout. */
    public function scan(int $limit = 100): array
    {
        $this->enabled();
        abort_unless($limit >= 1 && $limit <= 500, 422);
        $rows = DB::table('commercial_payouts as p')->leftJoin('commercial_payout_attempts as a', function ($join) {
            $join->on('a.payout_id', '=', 'p.id')->on('a.number', '=', 'p.attempt_number');
        })
            ->whereIn('p.status', ['pending', 'uncertain', 'executing'])->where(function ($q) {
                $q->whereNull('a.lease_until')->orWhere('a.lease_until', '<=', now());
            })
            ->orderBy('p.updated_at')->orderBy('p.id')->limit($limit)->select('p.id', 'p.approved_by')->get();
        $counts = ['attempted' => 0, 'checked' => 0, 'unavailable' => 0];
        foreach ($rows as $row) {
            $counts['attempted']++;
            try {
                $this->reconcile((int) $row->id, (int) $row->approved_by);
                $counts['checked']++;
            } catch (Throwable $e) {
                $counts['unavailable']++;
                // Rotate unavailable rows so one provider outage cannot starve later records.
                DB::table('commercial_payouts')->where('id', $row->id)->update(['updated_at' => now()]);
            }
        }

        return $counts;
    }

    private function event(?int $payout, ?int $account, ?int $actor, string $action, ?string $reference = null, ?string $failure = null): void
    {
        DB::table('commercial_payout_events')->insert(['payout_id' => $payout, 'account_id' => $account, 'actor_id' => $actor, 'action' => $action, 'provider_reference' => $reference, 'failure_code' => $failure, 'created_at' => now()]);
        DB::table('audit_events')->insert(['actor_id' => $actor ?? DB::table('commercial_payouts')->where('id', $payout)->value('approved_by'), 'action' => 'finance.'.$action, 'subject' => (string) ($payout ?? $account), 'created_at' => now()]);
    }
}
