<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class CpoCatalogSource
{
    private const STANDARDS = ['CCS1' => 'IEC_62196_T1_COMBO', 'CCS2' => 'IEC_62196_T2_COMBO', 'Type1' => 'IEC_62196_T1', 'Type2' => 'IEC_62196_T2', 'CHAdeMO' => 'CHADEMO'];

    public function payload(OcpiProvider $provider, array $contract, string $module, int $source): array
    {
        $role = app(CredentialExchange::class)->localConfiguration($provider)['roles'][0];
        $identity = ['country_code' => $role['country_code'], 'party_id' => $role['party_id'], 'id' => ($module === 'locations' ? 'S' : 'T').$source];

        if ($module === 'locations') {
            return $identity + $this->location($provider, $contract, $source);
        }

        return $identity + $this->tariff($provider, $contract, $source);
    }

    private function site(OcpiProvider $provider, array $contract, int $source): object
    {
        abort_unless(is_array($contract['sites'][$source] ?? null), 404);
        $site = DB::table('vendor_sites')
            ->join('vendor_companies', 'vendor_sites.company_id', '=', 'vendor_companies.id')
            ->join('stations', 'vendor_sites.station_id', '=', 'stations.id')
            ->where('vendor_sites.id', $source)
            ->where('vendor_companies.owner_id', $provider->owner_id)
            ->where('stations.vendor_id', $provider->owner_id)
            ->whereColumn('vendor_companies.country', 'vendor_sites.country')
            ->whereColumn('stations.country', 'vendor_sites.country')
            ->where('vendor_companies.status', 'approved')
            ->select('vendor_sites.*', 'vendor_companies.license_expires_on', 'stations.currency', 'stations.source')
            ->first();
        abort_unless($site, 404);
        abort_unless($site->license_expires_on >= now($site->timezone)->toDateString(), 409, 'CPO site authority has expired.');
        $connection = config('ocpi.connections.'.$provider->connection_key);
        abort_if($site->source === 'simulator' && ($connection['environment'] ?? null) !== 'sandbox', 409, 'Simulator inventory requires a sandbox contract.');

        return $site;
    }

    private function location(OcpiProvider $provider, array $contract, int $source): array
    {
        $site = $this->site($provider, $contract, $source);
        $metadata = $contract['sites'][$source];
        $v = Validator::make($metadata, [
            'city' => 'required|string|max:45',
            'country' => 'required|string|regex:/^[A-Z]{3}$/D',
            'country_code' => 'required|in:'.$site->country,
            'connectors' => 'required|array|max:1000',
            'connectors.*' => 'required|array:format,power_type,max_voltage,max_amperage,tariff_ids',
            'connectors.*.format' => 'required|in:SOCKET,CABLE',
            'connectors.*.power_type' => 'required|in:AC_1_PHASE,AC_2_PHASE,AC_2_PHASE_SPLIT,AC_3_PHASE,DC',
            'connectors.*.max_voltage' => 'required|integer|min:1|max:100000',
            'connectors.*.max_amperage' => 'required|integer|min:1|max:100000',
            'connectors.*.tariff_ids' => 'sometimes|array|list|max:20',
            'connectors.*.tariff_ids.*' => 'integer|min:1',
        ]);
        abort_if($v->fails(), 409, 'CPO site and electrical metadata require contract review.');
        abort_if(mb_strlen($site->address) > 45 || mb_strlen($site->name) > 255 || strlen($site->timezone) > 255, 409, 'Canonical site fields exceed OCPI bounds.');
        $topology = DB::table('charging_connector_mappings as mapping')
            ->join('charging_evses as evse', 'mapping.evse_id', '=', 'evse.id')
            ->join('vendor_chargers as charger', 'evse.charger_id', '=', 'charger.id')
            ->join('vendor_connectors as connector', 'mapping.connector_id', '=', 'connector.id')
            ->where('mapping.station_id', $site->station_id)
            ->where('charger.site_id', $source)
            ->where('charger.company_id', $site->company_id)
            ->whereColumn('connector.charger_id', 'charger.id')
            ->whereIn('connector.id', array_keys($metadata['connectors']))
            ->orderBy('evse.id')->orderBy('connector.id')
            ->get(['evse.id as evse_id', 'connector.id', 'connector.connector_type', 'connector.max_power_kw']);
        abort_unless($topology->count() === count($metadata['connectors']), 409, 'Contract connectors must resolve to canonical owned EVSEs.');

        $tariffIds = [];
        $evses = [];
        foreach ($topology as $row) {
            $electrical = $metadata['connectors'][$row->id];
            $standard = self::STANDARDS[$row->connector_type] ?? null;
            abort_unless($standard && $row->max_power_kw > 0, 409, 'Unsupported canonical connector.');
            $tariffs = [];
            foreach (array_unique($electrical['tariff_ids'] ?? []) as $id) {
                $tariffIds[$id] ??= $this->tariff($provider, $contract, (int) $id, $site);
                $tariffs[] = 'T'.$id;
            }
            $evses[$row->evse_id] ??= ['uid' => 'E'.$row->evse_id, 'status' => 'UNKNOWN', 'connectors' => []];
            $evses[$row->evse_id]['connectors'][] = [
                'id' => 'C'.$row->id, 'standard' => $standard, 'format' => $electrical['format'],
                'power_type' => $electrical['power_type'], 'max_voltage' => (int) $electrical['max_voltage'],
                'max_amperage' => (int) $electrical['max_amperage'], 'max_electric_power' => $row->max_power_kw * 1000,
            ] + ($tariffs === [] ? [] : ['tariff_ids' => $tariffs]);
        }

        return [
            'publish' => true, 'name' => $site->name, 'address' => $site->address, 'city' => $metadata['city'],
            'country' => $metadata['country'], 'coordinates' => ['latitude' => number_format((float) $site->latitude, 6, '.', ''), 'longitude' => number_format((float) $site->longitude, 6, '.', '')],
            'time_zone' => $site->timezone, 'evses' => array_values($evses),
        ];
    }

    private function tariff(OcpiProvider $provider, array $contract, int $source, ?object $site = null): array
    {
        abort_unless(in_array($source, $contract['tariffs'], true), 404);
        $version = DB::table('finance_tariff_versions')->where('id', $source)->first();
        abort_unless($version, 404);
        if ($site === null) {
            $siteId = DB::table('vendor_sites')->where('station_id', $version->station_id)->value('id');
            abort_unless($siteId, 404);
            $site = $this->site($provider, $contract, (int) $siteId);
        }
        abort_unless((int) $version->station_id === (int) $site->station_id && $version->currency === $site->currency, 409, 'Tariff and location source disagree.');
        abort_unless($version->minor_units <= 3 && $version->minor_units >= 0 && $version->tax_bps <= 10000 && $version->tax_bps >= 0, 409, 'Unsupported tariff currency or tax precision.');

        return ['currency' => $version->currency, 'start_date_time' => CarbonImmutable::parse($version->effective_from, 'UTC')->utc()->toISOString(),
            'elements' => [['price_components' => [[
                'type' => 'ENERGY', 'price' => $version->rate_minor_per_kwh / (10 ** $version->minor_units),
                'step_size' => 1, 'vat' => $version->tax_bps / 100,
            ]]]]];
    }
}
