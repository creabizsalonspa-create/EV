<?php

namespace App\Services\Charging;

use App\Models\Charging\ConnectorMapping;
use App\Models\Charging\Evse;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use App\Services\VendorOps\VendorAccess;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ConnectorTopology
{
    public function target(Station $station, int $mappingId, bool $lock = false): array
    {
        $map = ConnectorMapping::findOrFail($mappingId);
        abort_unless($map->station_id === $station->id, 404);
        $evse = Evse::findOrFail($map->evse_id);
        $connector = VendorConnector::findOrFail($map->connector_id);
        $charger = VendorCharger::findOrFail($evse->charger_id);
        $site = VendorSite::findOrFail($charger->site_id);
        $company = VendorCompany::whereKey($charger->company_id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        if ($lock) {
            $evse = Evse::whereKey($evse->id)->lockForUpdate()->firstOrFail();
            $connector = VendorConnector::whereKey($connector->id)->lockForUpdate()->firstOrFail();
        }
        abort_unless($station->source === 'simulator' && $site->station_id === $station->id && $site->company_id === $company->id && $company->owner_id === $station->vendor_id && $company->country === $station->country && $site->country === $station->country && $connector->charger_id === $charger->id && $charger->source === 'simulator', 409, 'Canonical inventory ownership is unavailable.');
        app(VendorAccess::class)->operational($company);
        abort_if($connector->maintenance_blocked, 409, 'Selected simulator connector is unavailable.');
        $binding = $evse->binding_id ? DB::table('csms_bindings')->where('id', $evse->binding_id)->when($lock, fn ($q) => $q->lockForUpdate())->first() : null;
        if ($evse->mode === 'csms') {
            abort_unless($binding && $binding->station_id === $station->id && $binding->vendor_charger_id === $charger->id && $binding->protocol === $charger->ocpp_protocol && preg_match('/^ev-test-[a-zA-Z0-9-]{1,80}$/D', $binding->identifier) && $map->source_connector_number >= 1 && ($binding->protocol === '1.6' ? $evse->source_evse_number === 0 : $evse->source_evse_number >= 1), 409, 'Canonical simulator source is unavailable.');
        } else {
            abort_unless($evse->mode === 'application' && $binding === null, 409);
        }

        return compact('map', 'evse', 'connector', 'charger', 'site', 'company', 'binding');
    }

    public function publicOption(Station $station, ConnectorMapping $map): array
    {
        $connector = VendorConnector::findOrFail($map->connector_id);
        $evse = Evse::findOrFail($map->evse_id);
        $ready = true;
        try {
            $this->target($station, $map->id);
        } catch (HttpException $e) {
            $ready = false;
        }

        return ['id' => $map->id, 'station_id' => $station->id, 'charger_id' => $evse->charger_id, 'evse_id' => $evse->id, 'evse_number' => $evse->number,
            'connector_number' => $connector->connector_number, 'connector_type' => $connector->connector_type, 'max_power_kw' => $connector->max_power_kw,
            'reservable' => $ready, 'csms_bound' => $evse->mode === 'csms', 'availability' => 'unknown', 'evidence' => 'SIMULATED'];
    }

    public function binding(Reservation $reservation, bool $lock = false): ?object
    {
        if ($reservation->connector_mapping_id) {
            $map = ConnectorMapping::findOrFail($reservation->connector_mapping_id);
            $evse = Evse::findOrFail($map->evse_id);
            abort_unless($map->station_id === $reservation->station_id && $evse->id === $reservation->charging_evse_id, 409, 'Reservation mapping changed.');

            return $evse->binding_id ? DB::table('csms_bindings')->where('id', $evse->binding_id)->when($lock, fn ($q) => $q->lockForUpdate())->first() : null;
        }
        $bindings = DB::table('csms_bindings')->where('station_id', $reservation->station_id)->when($lock, fn ($q) => $q->lockForUpdate())->get();
        abort_if($bindings->count() > 1, 409, 'Legacy source is ambiguous.');

        return $bindings->first();
    }
}
