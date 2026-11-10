<?php

namespace App\Http\Controllers;

use App\Models\Charging\RiderAccessToken;
use App\Models\Station;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use App\Services\Charging\RiderAccessHistory;
use App\Services\Charging\RiderSourceProvisioner;
use App\Services\Charging\RiderTokenRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class RiderAccessController extends Controller
{
    public function stations(Request $r)
    {
        abort_unless($r->user()->role === 'customer', 403);
        $v = $r->validate(['q' => 'sometimes|string|max:100']);
        $rows = DB::table('stations as s')->join('vendor_sites as site', 'site.station_id', '=', 's.id')->join('vendor_companies as company', 'company.id', '=', 'site.company_id')->where('s.source', 'simulator')->where('company.status', 'approved')->whereColumn('company.owner_id', 's.vendor_id')->whereExists(fn ($q) => $q->selectRaw('1')->from('csms_bindings as b')->whereColumn('b.station_id', 's.id')->whereNotNull('b.vendor_charger_id'))->when(isset($v['q']), fn ($q) => $q->where('s.name', 'like', '%'.$v['q'].'%'))->select('s.id', 's.name', 's.country', 's.currency')->distinct()->orderBy('s.id')->paginate(25);

        return response()->json($rows)->header('Cache-Control', 'private, no-store');
    }

    public function renew(Request $r, RiderAccessToken $token)
    {
        $this->owner($r, $token);
        $v = $r->validate(['expires_at' => 'required|date_format:Y-m-d\TH:i:s\Z']);
        $expiry = CarbonImmutable::parse($v['expires_at'])->utc();

        return DB::transaction(function () use ($r, $token, $expiry) {
            $current = RiderAccessToken::whereKey($token->id)->lockForUpdate()->firstOrFail();
            $this->owner($r, $current);
            abort_unless($current->revoked_at === null, 409, 'Revoked tokens cannot be reactivated.');
            if (! $expiry->equalTo($current->expires_at)) {
                abort_unless($expiry->isFuture() && $expiry->lte(now()->addYear()), 422);
                abort_if($current->provision_lease_until?->isFuture(), 409, 'Source inspection is in progress.');
                $current->update(['expires_at' => $expiry, 'provisioned_at' => null, 'provisioning_state' => 'unregistered', 'provision_lease' => null, 'provision_lease_until' => null]);
                app(RiderTokenRegistry::class)->event($current->id, null, $r->user()->id, 'token.expiry_changed');
            }

            return response()->json(['token' => $current, 'charging_authorized' => false])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function withdraw(Request $r, RiderAccessToken $token, int $grant)
    {
        $this->owner($r, $token);

        return DB::transaction(function () use ($r, $token, $grant) {
            $current = RiderAccessToken::whereKey($token->id)->lockForUpdate()->firstOrFail();
            $this->owner($r, $current);
            $row = DB::table('rider_charging_grants')->where('id', $grant)->where('token_id', $current->id)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->revoked_at === null) {
                DB::table('rider_charging_grants')->where('id', $row->id)->update(['state' => 'revoked', 'revoked_at' => now(), 'updated_at' => now()]);
                app(RiderTokenRegistry::class)->event($current->id, $row->id, $r->user()->id, 'grant.withdrawn');
            }

            return response()->json(['revoked' => true, 'active_sessions_stopped' => false])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function provision(Request $r, VendorCompany $company, int $token, RiderSourceProvisioner $source)
    {
        $this->reviewer($r, $company);

        return response()->json($source->publish($r->user(), $company, $token))->header('Cache-Control', 'private, no-store');
    }

    private function owner(Request $r, RiderAccessToken $token): void
    {
        abort_unless($r->user()->role === 'customer' && $token->user_id === $r->user()->id, 404);
    }

    private function reviewer(Request $r, VendorCompany $company): void
    {
        abort_unless($r->user()->role === 'admin' || $company->owner_id === $r->user()->id, 404);
    }

    public function index(Request $r)
    {
        abort_unless($r->user()->role === 'customer', 403);

        return response()->json(RiderAccessToken::where('user_id', $r->user()->id)->orderByDesc('id')->paginate(25))->header('Cache-Control', 'private, no-store');
    }

    public function activity(Request $r, RiderAccessToken $token, RiderAccessHistory $history)
    {
        $this->owner($r, $token);

        return $history->response($r, $token);
    }

    public function grantActivity(Request $r, VendorCompany $company, int $grant, RiderAccessHistory $history)
    {
        abort_unless(in_array($r->user()->role, ['vendor', 'admin'], true), 404);
        $this->reviewer($r, $company);
        $row = DB::table('rider_charging_grants as g')->join('stations as s', 's.id', '=', 'g.station_id')
            ->where('g.id', $grant)->where('g.company_id', $company->id)->where('s.vendor_id', $company->owner_id)->select('g.token_id')->first();
        abort_unless($row, 404);

        return $history->response($r, RiderAccessToken::findOrFail($row->token_id), $grant);
    }

    public function issue(Request $r, RiderTokenRegistry $registry)
    {
        $v = $r->validate(['label' => 'required|string|max:80', 'token_type' => 'required|in:Central,ISO14443,ISO15693', 'value' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9]{6,20}$/D'], 'expires_at' => 'required|date_format:Y-m-d\TH:i:s\Z', 'idempotency_key' => 'required|string|max:100']);
        abort_if(empty($v['value']) && $v['token_type'] !== 'Central', 422, 'A physical-card identifier is required for this type.');
        $result = $registry->issue($r->user(), $v);

        return response()->json($result + ['hardware_verified' => false, 'charging_authorized' => false], $result['created'] ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }

    public function revoke(Request $r, RiderAccessToken $token, RiderTokenRegistry $registry)
    {
        $registry->revoke($r->user(), $token);

        return response()->json(['revoked' => true, 'active_sessions_stopped' => false])->header('Cache-Control', 'private, no-store');
    }

    public function requests(Request $r, RiderAccessToken $token)
    {
        $this->owner($r, $token);

        return response()->json(DB::table('rider_charging_grants as g')->join('stations as s', 's.id', '=', 'g.station_id')->where('g.token_id', $token->id)->select('g.id', 'g.station_id', 's.name as station_name', 'g.state', 'g.expires_at', 'g.revoked_at', 'g.created_at')->orderByDesc('g.id')->paginate(25))->header('Cache-Control', 'private, no-store');
    }

    public function requestGrant(Request $r, RiderAccessToken $token, RiderTokenRegistry $registry)
    {
        $this->owner($r, $token);
        $v = $r->validate(['station_id' => 'required|integer|exists:stations,id']);

        return DB::transaction(function () use ($r, $token, $v, $registry) {
            $station = Station::whereKey($v['station_id'])->lockForUpdate()->firstOrFail();
            $sites = VendorSite::where('station_id', $station->id)->limit(2)->get();
            abort_unless($station->source === 'simulator' && $sites->count() === 1, 409, 'One owned simulator operator is required.');
            $company = VendorCompany::whereKey($sites->first()->company_id)->lockForUpdate()->firstOrFail();
            abort_unless($company->owner_id === $station->vendor_id, 409);
            $current = RiderAccessToken::whereKey($token->id)->lockForUpdate()->firstOrFail();
            $this->owner($r, $current);
            abort_unless($current->revoked_at === null && $current->expires_at->isFuture(), 409, 'Token is expired or revoked.');
            $old = DB::table('rider_charging_grants')->where('token_id', $current->id)->where('station_id', $station->id)->first();
            if ($old) {
                return response()->json(['id' => $old->id, 'state' => $old->state]);
            }
            $id = DB::table('rider_charging_grants')->insertGetId(['token_id' => $current->id, 'company_id' => $company->id, 'station_id' => $station->id, 'state' => 'requested', 'created_at' => now(), 'updated_at' => now()]);
            $registry->event($current->id, $id, $r->user()->id, 'grant.requested');

            return response()->json(['id' => $id, 'state' => 'requested'], 201);
        }, 3);
    }

    public function operatorRequests(Request $r, VendorCompany $company)
    {
        $this->reviewer($r, $company);

        return response()->json(DB::table('rider_charging_grants as g')->join('rider_access_tokens as t', 't.id', '=', 'g.token_id')->where('g.company_id', $company->id)->select('g.id', 'g.station_id', 'g.state', 'g.expires_at', 'g.revoked_at', 't.id as token_id', 't.label', 't.suffix', 't.token_type', 't.provisioning_state', 't.revoked_at as token_revoked_at')->orderByDesc('g.id')->paginate(25)->toArray() + ['source_adapter_enabled' => (bool) config('csms.rider_enabled')])->header('Cache-Control', 'private, no-store');
    }

    public function review(Request $r, VendorCompany $company, int $grant, RiderTokenRegistry $registry)
    {
        $this->reviewer($r, $company);
        $v = $r->validate(['state' => 'required|in:approved,rejected,revoked', 'note' => 'required|string|min:10|max:2000', 'expires_at' => 'required_if:state,approved|nullable|date_format:Y-m-d\TH:i:s\Z']);

        return DB::transaction(function () use ($r, $company, $grant, $v, $registry) {
            $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $this->reviewer($r, $company);
            $candidate = DB::table('rider_charging_grants')->where('id', $grant)->where('company_id', $company->id)->first();
            abort_unless($candidate, 404);
            $token = RiderAccessToken::whereKey($candidate->token_id)->lockForUpdate()->firstOrFail();
            $current = DB::table('rider_charging_grants')->where('id', $candidate->id)->lockForUpdate()->first();
            abort_unless($current->revoked_at === null, 409, 'Revoked grants cannot be reactivated.');
            $expiry = $v['state'] === 'approved' ? CarbonImmutable::parse($v['expires_at'])->utc() : null;
            if ($expiry) {
                abort_unless($token->revoked_at === null && $token->expires_at->isFuture() && $expiry->isFuture() && $expiry->lte($token->expires_at), 422, 'Approval must expire no later than the active token.');
            }
            DB::table('rider_charging_grants')->where('id', $current->id)->update(['state' => $v['state'], 'reviewed_by' => $r->user()->id, 'review_note' => Crypt::encryptString($v['note']), 'expires_at' => $expiry, 'revoked_at' => $v['state'] === 'revoked' ? now() : null, 'updated_at' => now()]);
            $registry->event($token->id, $current->id, $r->user()->id, 'grant.'.$v['state'], $v['note']);

            return response()->json(['state' => $v['state'], 'charging_authorized' => false, 'source_provisioning_verified' => $token->provisioned_at !== null, 'hardware_verified' => false]);
        }, 3);
    }
}
