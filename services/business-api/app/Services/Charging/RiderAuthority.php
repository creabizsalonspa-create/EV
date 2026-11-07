<?php

namespace App\Services\Charging;

use App\Models\Charging\RiderAccessToken;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use Illuminate\Support\Facades\DB;

class RiderAuthority
{
    /** Station/company locks must precede token/grant locks. */
    public function requireGrant(Reservation $reservation, int $tokenId, int $grantId): RiderAccessToken
    {
        abort_unless(config('csms.rider_enabled') && ! app()->environment('production'), 503, 'Rider source adapter is disabled.');
        $sites = VendorSite::where('station_id', $reservation->station_id)->limit(2)->get();
        abort_unless($sites->count() === 1, 409);
        $company = VendorCompany::whereKey($sites->first()->company_id)->lockForUpdate()->firstOrFail();
        $token = RiderAccessToken::whereKey($tokenId)->lockForUpdate()->firstOrFail();
        $grant = DB::table('rider_charging_grants')->where('id', $grantId)->where('token_id', $token->id)->where('company_id', $company->id)->where('station_id', $reservation->station_id)->lockForUpdate()->first();
        abort_unless($token->user_id === $reservation->user_id && User::whereKey($token->user_id)->where('role', 'customer')->exists() && $token->revoked_at === null && $token->expires_at->isFuture() && $token->provisioning_state === 'active' && $token->provisioned_at !== null && $token->source_authorization_id !== null, 409, 'Rider token is not source-authorized.');
        abort_unless($grant && $grant->state === 'approved' && $grant->revoked_at === null && $grant->expires_at && now()->lt($grant->expires_at), 409, 'Rider station approval is unavailable.');

        return $token;
    }
}
