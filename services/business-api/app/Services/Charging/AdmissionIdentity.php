<?php

namespace App\Services\Charging;

use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorSite;
use Illuminate\Support\Facades\DB;

/** Consult the canonical source of truth; admission cannot claim another source. */
class AdmissionIdentity
{
    public function compatible(VendorCompany $company, VendorCharger $charger, string $identity, string $protocol, bool $requireBound = false): bool
    {
        $site = VendorSite::find($charger->site_id);
        if ($charger->ocpp_protocol !== $protocol || ! $site || $site->company_id !== $company->id || $charger->company_id !== $company->id) {
            return false;
        }
        $bindings = DB::table('csms_bindings as b')->join('stations as s', 's.id', '=', 'b.station_id')
            ->where(fn ($q) => $q->where('b.identifier', $identity)->orWhere('b.vendor_charger_id', $charger->id))
            ->get(['b.identifier', 'b.protocol', 'b.vendor_charger_id', 'b.station_id', 's.vendor_id']);
        if ($requireBound && $bindings->isEmpty()) {
            return false;
        }
        foreach ($bindings as $binding) {
            if ((int) $binding->station_id !== $site->station_id || $binding->identifier !== $identity || $binding->protocol !== $protocol || (int) $binding->vendor_charger_id !== $charger->id || (int) $binding->vendor_id !== $company->owner_id) {
                return false;
            }
        }

        return true;
    }
}
