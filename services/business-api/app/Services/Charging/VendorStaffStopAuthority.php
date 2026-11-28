<?php

namespace App\Services\Charging;

use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use App\Models\VendorOps\VendorSite;
use App\Services\VendorOps\VendorAccess;
use Laravel\Sanctum\PersonalAccessToken;

/** Authority for a single active simulator session. Read access alone grants no control. */
final class VendorStaffStopAuthority
{
    public const PERMISSION = 'charging.stop';

    public function grant(User $actor, VendorCompany $company): ?VendorCompanyStaff
    {
        if (! app()->environment(['local', 'testing']) || ! config('csms.simulator_enabled')
            || ! config('csms.session_bridge_enabled') || $company->owner_id === $actor->id
            || ! in_array($actor->role, ['customer', 'vendor'], true)
            || $actor->account_status !== 'active' || ! $actor->hasVerifiedEmail()
            || $company->status !== 'approved' || ! app(VendorAccess::class)->licenseValid($company)
            || ! User::whereKey($company->owner_id)->where('role', 'vendor')->where('account_status', 'active')->exists()) {
            return null;
        }

        $grant = VendorCompanyStaff::where('company_id', $company->id)->where('user_id', $actor->id)
            ->where('status', 'active')->whereNotNull('accepted_at')->whereNull('admin_revoked_at')
            ->lockForUpdate()->first();

        return $grant && in_array('charging.read', $grant->permissions, true)
            && in_array(self::PERMISSION, $grant->permissions, true) ? $grant : null;
    }

    public function snapshot(User $actor, array $request): array
    {
        $token = $actor->currentAccessToken();

        $request['bearer_id'] = $token instanceof PersonalAccessToken ? (int) $token->getKey() : null;

        return $request;
    }

    public function permits(User $actor, Station $station, ?array $authority, ?int $epoch): bool
    {
        if (! is_int($authority['company_id'] ?? null) || ! is_int($authority['grant_id'] ?? null)
            || ! is_int($authority['grant_version'] ?? null) || ! array_key_exists('bearer_id', $authority)
            || ! app(ChargingActorConsent::class)->matches($actor, $epoch) || $station->source !== 'simulator') {
            return false;
        }

        $company = VendorCompany::whereKey($authority['company_id'])->lockForUpdate()->first();
        if (! $company || $company->owner_id !== $station->vendor_id || $company->country !== $station->country
            || ! VendorSite::where('station_id', $station->id)->where('company_id', $company->id)
                ->where('country', $station->country)->exists()) {
            return false;
        }

        $grant = $this->grant($actor, $company);
        if (! $grant || $grant->id !== $authority['grant_id'] || $grant->invitation_version !== $authority['grant_version']) {
            return false;
        }

        $bearer = $authority['bearer_id'];
        if ($bearer === null) {
            return true;
        }
        if (! is_int($bearer) || $bearer < 1) {
            return false;
        }
        $token = PersonalAccessToken::whereKey($bearer)->where('tokenable_type', $actor->getMorphClass())
            ->where('tokenable_id', $actor->id)->lockForUpdate()->first();
        $expiration = config('sanctum.expiration');

        return $token && (! $token->expires_at || $token->expires_at->isFuture())
            && (! $expiration || $token->created_at->gt(now()->subMinutes($expiration)));
    }
}
