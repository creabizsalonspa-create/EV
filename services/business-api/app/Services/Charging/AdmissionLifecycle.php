<?php

namespace App\Services\Charging;

use App\Models\Charging\AdmissionProfile;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use Illuminate\Support\Facades\DB;

/** Credential bookkeeping only: never a command, physical stop or CA revocation. */
class AdmissionLifecycle
{
    public function state(AdmissionProfile $p): string
    {
        if ($p->revoked_at !== null) {
            return 'revoked';
        }
        if ($p->expires_at?->lessThanOrEqualTo(now())) {
            return 'expired';
        }
        if (! $p->enabled) {
            return $p->credential_version === 0 ? 'prepared' : 'disabled';
        }
        if (! $p->credential_digest || ! $p->expires_at) {
            return 'disabled';
        }

        return $p->expires_at->lessThanOrEqualTo(now()->addDays(7)) ? 'expiring' : 'active';
    }

    public function expire(int $id): bool
    {
        $hint = AdmissionProfile::find($id);
        if (! $hint) {
            return false;
        }
        $charger = VendorCharger::find($hint->charger_id);
        if (! $charger) {
            return false;
        }

        return DB::transaction(function () use ($id, $charger) {
            VendorCompany::whereKey($charger->company_id)->lockForUpdate()->firstOrFail();
            $p = AdmissionProfile::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $p->enabled || ! $p->expires_at || $p->expires_at->isFuture()) {
                return false;
            }
            $p->update(['enabled' => false, 'credential_digest' => null, 'credential_version' => $p->credential_version + 1]);
            DB::table('charger_admission_events')->insert(['profile_id' => $p->id, 'actor_id' => null, 'action' => 'expired', 'credential_version' => $p->credential_version, 'created_at' => now()]);

            return true;
        }, 3);
    }

    public function scan(int $limit): int
    {
        abort_unless($limit >= 1 && $limit <= 1000, 422, 'Limit must be between1 and1000.');
        $ids = AdmissionProfile::where('enabled', true)->whereNotNull('expires_at')->where('expires_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            if ($this->expire($id)) {
                $count++;
            }
        }

        return $count;
    }
}
