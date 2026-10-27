<?php

namespace App\Services\VendorOps;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use Illuminate\Support\Facades\DB;

class VendorAccess
{
    public const PERMISSIONS = ['company.read', 'inventory.read', 'inventory.write'];

    public function require(User $actor, VendorCompany $company, string $permission = 'company.read'): void
    {
        if ($actor->role === 'admin' || $company->owner_id === $actor->id) {
            return;
        }
        $membership = VendorCompanyStaff::where('company_id', $company->id)->where('user_id', $actor->id)->where('status', 'active')->lockForUpdate()->first();
        abort_unless($membership && in_array($permission, $membership->permissions, true), 404);
    }

    public function owner(User $actor, VendorCompany $company): void
    {
        abort_unless($company->owner_id === $actor->id, 404);
    }

    public function operational(VendorCompany $company): void
    {
        abort_unless($company->status === 'approved', 409, 'Company is not approved for inventory operations.');
        abort_unless($company->license_expires_on->endOfDay()->isFuture(), 409, 'Company license has expired.');
    }

    public function audit(User $actor, string $action, int $subject): void
    {
        DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => 'vendor_ops.'.$action, 'subject' => (string) $subject, 'created_at' => now()]);
    }
}
