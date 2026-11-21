<?php

namespace App\Http\Controllers;

use App\Security\CurrentFinanceReader;
use App\Services\Commercial\Referrals\ReferralRegistry;
use App\Services\Commercial\Referrals\ReferralSource;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ReferralController extends Controller
{
    private function privateJson(mixed $value, int $status = 200)
    {
        return response()->json($value, $status)->header('Cache-Control', 'private, no-store');
    }

    public function page(Request $r)
    {
        return DB::transaction(function () use ($r) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless($actor->role === 'admin', 403);

            return response()->view('referral-programmes')->header('Cache-Control', 'private, no-store');
        });
    }

    public function publicationReceipt(Request $r, ReferralRegistry $service)
    {
        return DB::transaction(function () use ($r, $service) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless($actor->role === 'admin', 403);
            $v = $r->validate(['idempotency_key' => 'required|string|max:100']);
            $key = hash('sha256', 'referral-programme:'.$actor->id.':'.$v['idempotency_key']);
            $p = DB::table('loyalty_referral_programmes')->where('created_by', $actor->id)->where('request_key', $key)->first();

            return $this->privateJson(['program' => $p ? $service->publicProgramme($p) : null]);
        });
    }

    public function programmes(Request $r, ReferralRegistry $service)
    {
        return DB::transaction(function () use ($r, $service) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless(in_array($actor->role, ['admin', 'customer'], true), 403);
            $r->validate(['page' => 'sometimes|integer|between:1,100000']);
            $query = DB::table('loyalty_referral_programmes')->when($actor->role !== 'admin', fn ($q) => $q->where('ends_at', '>', now()));

            return $this->privateJson($query->orderByDesc('id')->paginate(25)->through(fn ($row) => $service->publicProgramme($row) + ($actor->role === 'admin' ? [
                'created_by' => (int) $row->created_by, 'created_at' => Carbon::parse($row->created_at)->utc()->toISOString(),
                'revocation' => DB::table('loyalty_referral_programme_revocations')->where('programme_id', $row->id)->first(),
            ] : [])));
        });
    }

    public function publish(Request $r, ReferralRegistry $service)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['name' => 'required|string|max:150', 'name_ar' => 'required|string|max:150', 'terms' => 'required|string|min:50|max:10000', 'terms_ar' => 'required|string|min:50|max:10000',
            'currency' => ['required', 'regex:/^[A-Z]{3}$/D'], 'minor_units' => 'required|integer|between:0,3', 'minimum_minor' => 'required|integer|between:1,1000000000000',
            'referrer_reward_id' => 'required|integer|between:1,9007199254740991', 'member_reward_id' => 'required|integer|between:1,9007199254740991',
            'claim_limit' => 'required|integer|between:1,1000000', 'per_referrer_limit' => 'required|integer|between:1,1000|lte:claim_limit',
            'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'idempotency_key' => 'required|string|max:100']);
        foreach (['minor_units', 'minimum_minor', 'referrer_reward_id', 'member_reward_id', 'claim_limit', 'per_referrer_limit'] as $field) {
            $v[$field] = (int) $v[$field];
        }
        foreach (['starts_at', 'ends_at'] as $field) {
            $v[$field] = Carbon::parse($v[$field])->utc()->toDateTimeString();
        }

        return $this->privateJson(['program' => $service->publish($r->user(), $v)], 201);
    }

    public function revoke(Request $r, int $programme, ReferralRegistry $service)
    {
        abort_unless($programme > 0 && $programme <= 9007199254740991, 422);
        $v = $r->validate(['reason' => 'required|string|min:5|max:500']);

        return $this->privateJson(['program' => $service->revoke($r->user(), $programme, $v['reason'])]);
    }

    public function account(Request $r, ReferralRegistry $service)
    {
        $v = $r->validate(['program_id' => 'required|integer|between:1,9007199254740991']);

        return $this->privateJson($service->account($r->user(), (int) $v['program_id']));
    }

    public function code(Request $r, ReferralRegistry $service)
    {
        $v = $r->validate(['program_id' => 'required|integer|between:1,9007199254740991', 'idempotency_key' => 'required|string|max:100']);

        return $this->privateJson($service->code($r->user(), (int) $v['program_id'], $v['idempotency_key']), 201);
    }

    public function accept(Request $r, ReferralRegistry $service)
    {
        $v = $r->validate(['code' => ['required', 'string', 'regex:/^REF-[A-F0-9]{20}$/D'], 'terms_version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'consent' => 'required|accepted', 'idempotency_key' => 'required|string|max:100']);

        return $this->privateJson(['referral' => $service->accept($r->user(), $v)], 201);
    }

    public function qualify(Request $r, ReferralRegistry $service)
    {
        $v = $r->validate(['invoice_id' => 'required|integer|between:1,9007199254740991', 'idempotency_key' => 'required|string|max:100']);

        return $this->privateJson(['referral' => $service->qualify($r->user(), (int) $v['invoice_id'], $v['idempotency_key'])]);
    }

    public function withdraw(Request $r, ReferralRegistry $service)
    {
        $v = $r->validate(['reason' => 'required|string|min:5|max:500']);

        return $this->privateJson(['referral' => $service->withdraw($r->user(), $v['reason'])]);
    }

    public function rewards(Request $r)
    {
        return DB::transaction(function () use ($r) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless($actor->role === 'customer', 403);
            $r->validate(['page' => 'sometimes|integer|between:1,100000']);
            $rows = DB::table('loyalty_referral_rewards as rr')->join('loyalty_referral_qualifications as q', 'q.id', '=', 'rr.qualification_id')
                ->join('loyalty_referrals as ref', 'ref.id', '=', 'q.referral_id')->join('loyalty_redemptions as red', 'red.id', '=', 'rr.redemption_id')
                ->join('loyalty_rewards as reward', 'reward.id', '=', 'red.reward_id')->where('rr.user_id', $actor->id)->orderByDesc('rr.id')
                ->select(['rr.id', 'rr.redemption_id', 'rr.role', 'rr.created_at', 'ref.programme_id', 'ref.id as referral_id', 'q.invoice_id', 'red.status', 'reward.name as reward_name', 'reward.name_ar as reward_name_ar'])->paginate(25);
            $rows->through(function ($row) {
                $referral = DB::table('loyalty_referrals')->find($row->referral_id);
                $p = DB::table('loyalty_referral_programmes')->find($row->programme_id);
                $invoice = DB::table('finance_invoices')->find($row->invoice_id);

                return ['id' => (int) $row->id, 'redemption_id' => (int) $row->redemption_id, 'program_id' => (int) $row->programme_id,
                    'role' => $row->role, 'reward_name' => $row->reward_name, 'reward_name_ar' => $row->reward_name_ar,
                    'status' => $row->status, 'created_at' => Carbon::parse($row->created_at)->utc()->toISOString(),
                    'source_currently_eligible' => app(ReferralSource::class)->eligible($invoice, $p, $referral),
                    'source' => 'REFERRAL', 'points_cost' => 0, 'cash_value' => false, 'fulfillment' => 'MANUAL_NON_MONETARY', 'delivery_verified' => false];
            });

            return $this->privateJson($rows);
        });
    }
}
