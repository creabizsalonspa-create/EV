<?php

namespace App\Services\Charging;

use App\Models\Charging\ConnectorMapping;
use App\Models\Station;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorConnector;
use App\Models\VendorOps\VendorSite;
use App\Services\VendorOps\VendorAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Admission for legacy stations and explicit canonical EVSE/connector targets. */
class StationGate
{
    public function __construct(private VendorAccess $access) {}

    private function ready(Station $station, VendorSite $site, ?VendorCompany $company, Collection $connectors, Collection $chargers, ?object $binding, ?string $timezone): bool
    {
        if (! $company || $company->owner_id !== $station->vendor_id || $site->country !== $station->country || $company->status !== 'approved' || ! $this->access->licenseValid($company, $timezone) || $connectors->count() !== 1) {
            return false;
        }
        $connector = $connectors->first();
        $charger = $chargers->get($connector->charger_id);
        if (! $charger || $charger->company_id !== $company->id || $charger->source !== 'simulator' || $connector->maintenance_blocked || $connector->connector_type !== $station->connector_type || $connector->max_power_kw < $station->power_kw) {
            return false;
        }

        return ! $binding || ((int) $connector->connector_number === 1 && $charger->ocpp_protocol === $binding->protocol);
    }

    /** Batched public metadata; never returns KYC reasons, company identity or private maintenance text. */
    public function policies(Collection $stations): array
    {
        $sites = VendorSite::whereIn('station_id', $stations->pluck('id'))->get()->keyBy('station_id');
        if ($sites->isEmpty()) {
            return [];
        }
        $companies = VendorCompany::whereIn('id', $sites->pluck('company_id'))->get()->keyBy('id');
        // Return only the unique candidate, not every private asset on a large or ambiguous site.
        $counts = DB::table('vendor_connectors as connectors')->join('vendor_chargers as chargers', 'chargers.id', '=', 'connectors.charger_id')
            ->whereIn('chargers.site_id', $sites->pluck('id'))->groupBy('chargers.site_id')
            ->selectRaw('chargers.site_id, COUNT(*) as connector_count, MIN(connectors.id) as first_id')->get()->keyBy('site_id');
        $singleIds = $counts->filter(fn ($row) => (int) $row->connector_count === 1)->pluck('first_id');
        $connectors = VendorConnector::whereIn('id', $singleIds)->get()->keyBy('id');
        $chargers = VendorCharger::whereIn('id', $connectors->pluck('charger_id'))->get()->keyBy('id');
        $bindings = DB::table('csms_bindings')->whereIn('station_id', $stations->pluck('id'))->get()->groupBy('station_id');
        $timezones = DB::table('regions')->pluck('timezone', 'country');
        $result = [];
        foreach ($stations as $station) {
            if ($station->source !== 'simulator') {
                continue;
            }
            if (! ($site = $sites->get($station->id))) {
                continue;
            }
            $count = $counts->get($site->id);
            $candidate = $count && (int) $count->connector_count === 1 ? $connectors->only([$count->first_id]) : collect();
            $company = $companies->get($site->company_id);
            $ready = ($bindings->get($station->id)?->count() ?? 0) <= 1 && $this->ready($station, $site, $company, $candidate, $chargers, $bindings->get($station->id)?->first(), $company ? $timezones->get($company->country) : null);
            $result[$station->id] = $ready ? 'managed_ready' : 'managed_blocked';
        }

        foreach (ConnectorMapping::whereIn('station_id', $stations->pluck('id'))->pluck('station_id')->unique() as $id) {
            $result[$id] = 'canonical';
        }

        return $result;
    }

    /** Call only inside a booking/start transaction. Stop and source finalization must remain available. */
    public function requireReady(Station $station, ?int $mappingId = null): void
    {
        $station = Station::whereKey($station->id)->lockForUpdate()->firstOrFail();
        abort_unless($station->source === 'simulator', 409, 'Only simulator charging admission is enabled.');
        if ($mappingId !== null) {
            app(ConnectorTopology::class)->target($station, $mappingId, true);

            return;
        }
        abort_if(ConnectorMapping::where('station_id', $station->id)->exists(), 409, 'Choose an explicit connector.');
        abort_if(DB::table('csms_bindings')->where('station_id', $station->id)->count() > 1, 409, 'Legacy source is ambiguous; use canonical mapping.');
        $site = VendorSite::where('station_id', $station->id)->first();
        if (! $site) {
            return;
        } // Preserve unlinked legacy simulator contracts.
        $company = VendorCompany::whereKey($site->company_id)->lockForUpdate()->first();
        $connectors = VendorConnector::whereIn('charger_id', VendorCharger::where('site_id', $site->id)->select('id'))->limit(2)->lockForUpdate()->get();
        $chargers = VendorCharger::whereIn('id', $connectors->pluck('charger_id'))->get()->keyBy('id');
        $binding = DB::table('csms_bindings')->where('station_id', $station->id)->first();
        abort_unless($this->ready($station, $site, $company, $connectors, $chargers, $binding, null), 409, 'Managed simulator reservations and starts are temporarily unavailable.');
    }
}
