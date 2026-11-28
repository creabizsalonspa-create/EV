<?php

namespace App\Services\VendorOps;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use App\Services\Charging\VendorStaffStopAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class VendorAccess
{
    public const PERMISSIONS = ['company.read', 'inventory.read', 'inventory.write', 'charging.read', VendorStaffStopAuthority::PERMISSION];

    /** UI hints only; mutation handlers still enforce current permissions. */
    public function capabilities(User $actor, VendorCompany $company): array
    {
        $owner = $company->owner_id === $actor->id;
        $reviewer = $actor->role === 'admin';
        // An invitation addressed to an unverified mailbox is not proof
        // that the account holder owns that address. Never expose delegated
        // capabilities until the invited account verifies its email.
        $permissions = $owner || $reviewer
            ? self::PERMISSIONS
            : ($actor->hasVerifiedEmail()
                ? (VendorCompanyStaff::where('company_id', $company->id)
                    ->where('user_id', $actor->id)->where('status', 'active')
                    ->whereNull('admin_revoked_at')->first()?->permissions ?? [])
                : []);

        return ['owner' => $owner, 'reviewer' => $reviewer, 'company_read' => in_array('company.read', $permissions, true),
            'inventory_read' => in_array('inventory.read', $permissions, true), 'inventory_write' => in_array('inventory.write', $permissions, true),
            'charging_read' => in_array('charging.read', $permissions, true),
            'private_evidence' => $owner || $reviewer, 'inventory_operational' => $company->status === 'approved' && $this->licenseValid($company)];
    }

    public function require(User $actor, VendorCompany $company, string $permission = 'company.read'): void
    {
        if ($actor->role === 'admin' || $company->owner_id === $actor->id) {
            return;
        }
        abort_unless($actor->hasVerifiedEmail(), 404);
        $membership = VendorCompanyStaff::where('company_id', $company->id)->where('user_id', $actor->id)->where('status', 'active')->whereNull('admin_revoked_at')->lockForUpdate()->first();
        abort_unless($membership && in_array($permission, $membership->permissions, true), 404);
    }

    public function owner(User $actor, VendorCompany $company): void
    {
        abort_unless($company->owner_id === $actor->id, 404);
    }

    public function operational(VendorCompany $company): void
    {
        abort_unless($company->status === 'approved', 409, 'Company is not approved for inventory operations.');
        abort_unless($this->licenseValid($company), 409, 'Company license has expired.');
    }

    public function licenseValid(VendorCompany $company, ?string $timezone = null): bool
    {
        $timezone ??= DB::table('regions')->where('country', $company->country)->value('timezone');
        // Invalid/blank timezone configuration must never silently fall
        // back to the PHP server timezone and authorize charger operations.
        // Align this authority check with the Admin KYC unknown-date queue.
        if (! is_string($timezone)
            || ($timezone !== 'UTC' && ! str_contains($timezone, '/'))
            || ! in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)
            || ! $company->license_expires_on) {
            return false;
        }

        // A license expires at the end of its configured country-local
        // calendar day. Invalid historical dates remain ineligible.
        try {
            return CarbonImmutable::createFromFormat(
                '!Y-m-d', $company->license_expires_on->format('Y-m-d'), $timezone
            )->endOfDay()->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    public function audit(User $actor, string $action, int $subject): void
    {
        DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => 'vendor_ops.'.$action, 'subject' => (string) $subject, 'created_at' => now()]);
    }
}
