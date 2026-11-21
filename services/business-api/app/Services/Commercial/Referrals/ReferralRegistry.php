<?php

namespace App\Services\Commercial\Referrals;

use App\Models\User;
use App\Security\AdminTransactionAuthority;
use App\Security\CurrentFinanceReader;
use App\Services\Commercial\Loyalty\LoyaltyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ReferralRegistry
{
    public function enabled(): bool
    {
        return config('loyalty.enabled') && ! app()->environment('production');
    }

    private function requireEnabled(): void
    {
        abort_unless($this->enabled(), 503, 'Referral test economics are disabled; activation requires commercial review.');
    }

    private function customer(User $snapshot): User
    {
        $actor = app(CurrentFinanceReader::class)->requireSnapshot($snapshot);
        abort_unless($actor->role === 'customer', 403);

        return $actor;
    }

    public function programme(int $id, bool $lock = false): object
    {
        $query = DB::table('loyalty_referral_programmes')->where('id', $id);

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? abort(404);
    }

    public function active(object $programme): bool
    {
        return $this->enabled() && Carbon::parse($programme->starts_at)->lte(now()) && Carbon::parse($programme->ends_at)->gt(now())
            && ! DB::table('loyalty_referral_programme_revocations')->where('programme_id', $programme->id)->exists();
    }

    public function publicProgramme(object $p): array
    {
        $reward = fn ($id) => DB::table('loyalty_rewards')->where('id', $id)->first(['id', 'name', 'name_ar']);

        return ['id' => (int) $p->id, 'name' => $p->name, 'name_ar' => $p->name_ar, 'terms' => $p->terms, 'terms_ar' => $p->terms_ar,
            'terms_version' => $p->terms_version, 'currency' => $p->currency, 'minor_units' => (int) $p->minor_units,
            'minimum_minor' => (string) $p->minimum_minor, 'referrer_reward' => $reward($p->referrer_reward_id), 'member_reward' => $reward($p->member_reward_id),
            'starts_at' => Carbon::parse($p->starts_at)->utc()->toISOString(), 'ends_at' => Carbon::parse($p->ends_at)->utc()->toISOString(), 'active' => $this->active($p),
            'qualification_rule' => 'FIRST_POST_CONSENT_FULLY_PAID_CHARGING_INVOICE', 'refunds_disqualify' => true, 'requires_verified_email' => true,
            'cash_value' => false, 'money_movement' => false, 'fulfillment' => 'MANUAL_NON_MONETARY', 'delivery_verified' => false];
    }

    public function publish(User $snapshot, array $v): array
    {
        $this->requireEnabled();

        return DB::transaction(function () use ($snapshot, $v) {
            $actor = AdminTransactionAuthority::requireCurrent($snapshot);
            $key = hash('sha256', 'referral-programme:'.$actor->id.':'.$v['idempotency_key']);
            unset($v['idempotency_key']);
            ksort($v);
            $fingerprint = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));
            if ($old = DB::table('loyalty_referral_programmes')->where('request_key', $key)->first()) {
                abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, 'Programme publication identity changed.');

                return $this->publicProgramme($old);
            }
            abort_unless(Carbon::parse($v['ends_at'])->isFuture(), 422);
            $precision = DB::table('regions')->where('currency', $v['currency'])->pluck('minor_units')->map(fn ($x) => (int) $x)->unique();
            abort_unless($precision->count() === 1 && $precision->first() === $v['minor_units'], 409, 'Currency precision is unavailable or ambiguous.');
            $rewards = DB::table('loyalty_rewards')->whereIn('id', [$v['referrer_reward_id'], $v['member_reward_id']])->orderBy('id')->lockForUpdate()->get();
            abort_unless($rewards->count() === count(array_unique([$v['referrer_reward_id'], $v['member_reward_id']])), 404);
            foreach ($rewards as $reward) {
                abort_unless($reward->currency === $v['currency'] && $reward->starts_at <= $v['starts_at'] && $reward->ends_at >= $v['ends_at'], 422, 'Both rewards must cover the programme currency and complete validity window.');
            }
            $id = DB::table('loyalty_referral_programmes')->insertGetId($v + ['terms_version' => $fingerprint, 'created_by' => $actor->id, 'request_key' => $key, 'fingerprint' => $fingerprint, 'created_at' => now()]);
            $this->audit($actor->id, 'programme_published', $id);

            return $this->publicProgramme($this->programme($id));
        }, 3);
    }

    public function revoke(User $snapshot, int $programme, string $reason): array
    {
        return DB::transaction(function () use ($snapshot, $programme, $reason) {
            $actor = AdminTransactionAuthority::requireCurrent($snapshot);
            $p = $this->programme($programme, true);
            $old = DB::table('loyalty_referral_programme_revocations')->where('programme_id', $programme)->first();
            abort_unless(! $old || hash_equals($old->reason, $reason), 409, 'Programme revocation reason changed.');
            if (! $old) {
                DB::table('loyalty_referral_programme_revocations')->insert(['programme_id' => $programme, 'actor_id' => $actor->id, 'reason' => $reason, 'created_at' => now()]);
                $this->audit($actor->id, 'programme_revoked', $programme);
            }

            return $this->publicProgramme($p);
        }, 3);
    }

    public function code(User $snapshot, int $programme, string $key): array
    {
        $this->requireEnabled();

        return DB::transaction(function () use ($snapshot, $programme, $key) {
            $actor = $this->customer($snapshot);
            abort_unless($actor->email_verified_at !== null, 403, 'Verify your email before joining referrals.');
            $p = $this->programme($programme, true);
            abort_unless($this->active($p), 409, 'Programme is unavailable.');
            $request = hash('sha256', 'referral-code:'.$actor->id.':'.$key);
            $old = DB::table('loyalty_referral_codes')->where('request_key', $request)->first();
            abort_unless(! $old || (int) $old->programme_id === $programme, 409, 'Code request programme changed.');
            $old ??= DB::table('loyalty_referral_codes')->where('programme_id', $programme)->where('user_id', $actor->id)->first();
            if (! $old) {
                do {
                    $code = 'REF-'.strtoupper(bin2hex(random_bytes(10)));
                } while (DB::table('loyalty_referral_codes')->where('code', $code)->exists());
                $id = DB::table('loyalty_referral_codes')->insertGetId(['programme_id' => $programme, 'user_id' => $actor->id, 'code' => $code, 'request_key' => $request, 'created_at' => now()]);
                $old = DB::table('loyalty_referral_codes')->find($id);
                $this->audit($actor->id, 'code_created', $id);
            }

            return ['code' => $old->code, 'program_id' => (int) $old->programme_id];
        }, 3);
    }

    public function accept(User $snapshot, array $v): array
    {
        $this->requireEnabled();
        abort_unless($snapshot->role === 'customer', 403);
        $hint = DB::table('loyalty_referral_codes')->where('code', $v['code'])->first() ?? abort(404);
        abort_unless((int) $hint->user_id !== $snapshot->id, 409, 'Self referrals are not permitted.');

        return DB::transaction(function () use ($snapshot, $hint, $v) {
            $actors = User::whereIn('id', [$snapshot->id, $hint->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor = $this->customer($snapshot);
            $referrer = $actors->get($hint->user_id);
            abort_unless($actor->email_verified_at !== null && $referrer && $referrer->role === 'customer' && $referrer->account_status === 'active' && $referrer->email_verified_at !== null, 403, 'Verified active customer accounts are required.');
            $request = hash('sha256', 'referral-accept:'.$actor->id.':'.$v['idempotency_key']);
            $fingerprint = hash('sha256', hash('sha256', $v['code']).':'.$v['terms_version'].':'.$request);
            if ($prior = DB::table('loyalty_referrals')->where('user_id', $actor->id)->first()) {
                abort_unless(hash_equals($prior->request_key, $request) && hash_equals($prior->fingerprint, $fingerprint), 409, 'A referral is already recorded for this account.');

                return $this->referral($prior);
            }
            $p = $this->programme((int) $hint->programme_id, true);
            abort_unless($this->active($p) && hash_equals($p->terms_version, $v['terms_version']), 409, 'Refresh and review the current programme terms.');
            $this->lockInvoices([$actor->id]);
            abort_if($this->hasPayment($actor->id), 409, 'Referrals must be accepted before the first charging payment.');
            $id = DB::table('loyalty_referrals')->insertGetId(['programme_id' => $p->id, 'user_id' => $actor->id, 'referrer_id' => $referrer->id, 'code_id' => $hint->id,
                'terms_version' => $p->terms_version, 'request_key' => $request, 'fingerprint' => $fingerprint, 'created_at' => now()]);
            $this->audit($actor->id, 'accepted', $id);

            return $this->referral(DB::table('loyalty_referrals')->find($id));
        }, 3);
    }

    public function qualify(User $snapshot, int $invoiceId, string $key): array
    {
        $this->requireEnabled();
        abort_unless($snapshot->role === 'customer', 403);
        $hint = DB::table('loyalty_referrals')->where('user_id', $snapshot->id)->first() ?? abort(404);

        return DB::transaction(function () use ($snapshot, $hint, $invoiceId, $key) {
            $actors = User::whereIn('id', [$hint->user_id, $hint->referrer_id])->orderBy('id')->lockForUpdate()->get();
            $actor = $this->customer($snapshot);
            foreach ($actors as $u) {
                abort_unless($u->role === 'customer' && $u->account_status === 'active' && $u->email_verified_at !== null, 403);
            }
            $referral = DB::table('loyalty_referrals')->where('id', $hint->id)->lockForUpdate()->firstOrFail();
            $request = hash('sha256', 'referral-qualify:'.$actor->id.':'.$key);
            $fingerprint = hash('sha256', 'referral-qualify:'.$actor->id.':'.$referral->id.':'.$invoiceId.':'.$key);
            if ($old = DB::table('loyalty_referral_qualifications')->where('referral_id', $referral->id)->first()) {
                abort_unless((int) $old->invoice_id === $invoiceId && hash_equals($old->request_key, $request), 409, 'This referral has already qualified under its original request.');

                return $this->referral($referral);
            }
            abort_if(DB::table('loyalty_referral_withdrawals')->where('referral_id', $referral->id)->exists(), 409, 'Referral consent was withdrawn.');
            $p = $this->programme((int) $referral->programme_id, true);
            abort_unless($this->active($p), 409, 'Programme is unavailable.');
            $this->lockInvoices([$actor->id, $hint->referrer_id]);
            $invoice = DB::table('finance_invoices')->where('id', $invoiceId)->where('user_id', $actor->id)->first() ?? abort(404);
            abort_unless(app(ReferralSource::class)->eligible($invoice, $p, $referral), 409, 'A fully captured post-consent qualifying invoice without refunds or unresolved evidence is required.');
            $first = DB::table('finance_payments as pay')->join('finance_invoices as i', 'i.id', '=', 'pay.invoice_id')->where('i.user_id', $actor->id)->orderBy('pay.id')->value('i.id');
            abort_unless((int) $first === $invoiceId, 409, 'Only the first charging payment invoice may qualify.');
            $used = DB::table('loyalty_referral_qualifications as q')->join('loyalty_referrals as r', 'r.id', '=', 'q.referral_id')->where('r.programme_id', $p->id);
            abort_unless((clone $used)->count() < (int) $p->claim_limit && (clone $used)->where('r.referrer_id', $hint->referrer_id)->count() < (int) $p->per_referrer_limit, 409, 'Programme or referrer limit reached.');
            DB::table('loyalty_rewards')->whereIn('id', [$p->referrer_reward_id, $p->member_reward_id])->orderBy('id')->lockForUpdate()->get();
            $qualification = DB::table('loyalty_referral_qualifications')->insertGetId(['referral_id' => $referral->id, 'invoice_id' => $invoiceId, 'request_key' => $request, 'fingerprint' => $fingerprint, 'created_at' => now()]);
            foreach (['referrer' => $hint->referrer_id, 'member' => $actor->id] as $role => $user) {
                $redemption = app(LoyaltyService::class)->reserveReferralReward($qualification, $role);
                DB::table('loyalty_referral_rewards')->insert(['qualification_id' => $qualification, 'user_id' => $user, 'redemption_id' => $redemption->id, 'role' => $role, 'created_at' => now()]);
            }
            $this->audit($actor->id, 'qualified', $qualification);

            return $this->referral($referral);
        }, 3);
    }

    private function lockInvoices(array $users): void
    {
        $ids = DB::table('finance_invoices')->whereIn('user_id', $users)->orderBy('id')->limit(1001)->pluck('id');
        abort_if($ids->count() > 1000, 409, 'Referral source history requires manual review.');
        DB::table('finance_invoices')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    }

    private function hasPayment(int $user): bool
    {
        return DB::table('finance_payments as p')->join('finance_invoices as i', 'i.id', '=', 'p.invoice_id')->where('i.user_id', $user)->exists();
    }

    public function withdraw(User $snapshot, string $reason): array
    {
        return DB::transaction(function () use ($snapshot, $reason) {
            $actor = $this->customer($snapshot);
            $r = DB::table('loyalty_referrals')->where('user_id', $actor->id)->lockForUpdate()->first() ?? abort(404);
            abort_if(DB::table('loyalty_referral_qualifications')->where('referral_id', $r->id)->exists(), 409, 'Already-qualified commitments cannot be withdrawn as an unaccepted referral.');
            $old = DB::table('loyalty_referral_withdrawals')->where('referral_id', $r->id)->first();
            abort_unless(! $old || hash_equals($old->reason, $reason), 409, 'Withdrawal reason changed.');
            if (! $old) {
                DB::table('loyalty_referral_withdrawals')->insert(['referral_id' => $r->id, 'actor_id' => $actor->id, 'reason' => $reason, 'created_at' => now()]);
                $this->audit($actor->id, 'withdrawn', $r->id);
            }

            return $this->referral($r);
        }, 3);
    }

    public function referral(object $r): array
    {
        $qualification = DB::table('loyalty_referral_qualifications')->where('referral_id', $r->id)->first();
        $withdrawn = DB::table('loyalty_referral_withdrawals')->where('referral_id', $r->id)->exists();
        $p = $this->programme((int) $r->programme_id);
        $closed = Carbon::parse($p->ends_at)->lte(now()) || DB::table('loyalty_referral_programme_revocations')->where('programme_id', $p->id)->exists();
        $own = $qualification ? DB::table('loyalty_referral_rewards')->where('qualification_id', $qualification->id)->where('user_id', $r->user_id)->first() : null;

        return ['id' => (int) $r->id, 'program_id' => (int) $r->programme_id, 'request_binding' => $r->fingerprint,
            'status' => $qualification ? 'qualified' : ($withdrawn ? 'withdrawn' : ($closed ? 'expired' : 'accepted')),
            'accepted_at' => Carbon::parse($r->created_at)->utc()->toISOString(),
            'qualification' => $qualification ? ['request_binding' => $qualification->fingerprint, 'own_redemption_id' => (int) $own->redemption_id, 'qualified_at' => Carbon::parse($qualification->created_at)->utc()->toISOString()] : null,
            'cash_value' => false, 'money_movement' => false, 'delivery_verified' => false];
    }

    public function account(User $snapshot, int $programme): array
    {
        return DB::transaction(function () use ($snapshot, $programme) {
            $actor = $this->customer($snapshot);
            $p = $this->programme($programme);
            $r = DB::table('loyalty_referrals')->where('user_id', $actor->id)->first();
            $code = DB::table('loyalty_referral_codes')->where('user_id', $actor->id)->where('programme_id', $programme)->first();
            $reason = $actor->email_verified_at === null ? 'email_unverified' : ($r ? 'already_referred' : ($this->hasPayment($actor->id) ? 'existing_charging_payment' : 'available'));
            $invites = DB::table('loyalty_referrals')->where('referrer_id', $actor->id)->where('programme_id', $programme);

            return ['program_id' => $programme, 'own_code' => $code ? ['code' => $code->code, 'program_id' => $programme] : null,
                'referral' => $r ? $this->referral($r) : null, 'invited_count' => (clone $invites)->count(),
                'qualified_count' => DB::table('loyalty_referral_qualifications')->whereIn('referral_id', (clone $invites)->select('id'))->count(),
                'can_accept' => $reason === 'available' && $this->active($p), 'eligibility_reason' => $reason,
                'software_activation' => $this->enabled(), 'money_movement' => false];
        });
    }

    private function audit(int $actor, string $action, int $id): void
    {
        DB::table('audit_events')->insert(['actor_id' => $actor, 'action' => 'loyalty.referral.'.$action, 'subject' => (string) $id, 'created_at' => now()]);
    }
}
