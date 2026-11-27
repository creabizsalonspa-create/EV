<?php

namespace App\Services\Ocpi;

use App\Models\Finance\Invoice;
use App\Models\OcpiProvider;
use App\Services\Finance\ChargingProvenance;
use App\Services\Finance\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Export only explicitly bound, reconciled sandbox charging and finance sources. */
final class CpoSessionSource
{
    public function payload(OcpiProvider $provider, array $contract, string $module, int $sessionId): array
    {
        $connection = config('ocpi.connections.'.$provider->connection_key);
        abort_unless(app()->environment(['local', 'testing']) && ($connection['environment'] ?? null) === 'sandbox', 404);
        abort_unless(in_array($module, CpoCatalogContract::SESSION_MODULES, true), 404);
        $mapping = app(CpoCatalogContract::class)->sessionExports($provider, $contract)[$sessionId] ?? null;
        abort_unless(is_array($mapping), 409, 'Explicit synthetic token and EVSE mapping requires review.');

        $siteId = (int) $mapping['site_id'];
        $location = app(CpoCatalogSource::class)->payload($provider, $contract, 'locations', $siteId);
        $session = DB::table('charging_sessions as session')
            ->join('reservations as reservation', 'reservation.id', '=', 'session.reservation_id')
            ->join('vendor_sites as site', 'site.station_id', '=', 'reservation.station_id')
            ->join('stations as station', 'station.id', '=', 'site.station_id')
            ->join('csms_session_links as link', 'link.charging_session_id', '=', 'session.id')
            ->join('csms_bindings as binding', 'binding.id', '=', 'link.binding_id')
            ->whereColumn('binding.station_id', 'station.id')->whereColumn('binding.identifier', 'link.identifier')->whereColumn('binding.protocol', 'link.protocol')
            ->join('charging_connector_mappings as map', 'map.id', '=', 'link.connector_mapping_id')
            ->whereColumn('map.id', 'reservation.connector_mapping_id')->whereColumn('map.station_id', 'station.id')
            ->whereColumn('map.evse_id', 'session.charging_evse_id')
            ->where('site.id', $siteId)->where('station.vendor_id', $provider->owner_id)->where('station.source', 'simulator')
            ->where('session.id', $sessionId)->where('session.status', 'stopped')->whereNotNull('session.stopped_at')
            ->whereNotNull('link.transaction_id')->whereNotNull('link.started_at')->whereNotNull('link.ended_at')
            ->whereNull('link.closed_without_dispatch_at')
            ->first(['session.currency', 'session.energy_wh', 'session.total_minor', 'session.rate_minor_per_kwh', 'session.stopped_at', 'link.meter_start_wh', 'link.last_meter_wh',
                'link.id as link_id', 'link.started_at', 'link.ended_at', 'link.last_sequence', 'reservation.user_id', 'map.evse_id', 'map.connector_id']);
        abort_unless($session && $session->last_sequence > 0 && $session->meter_start_wh !== null && $session->last_meter_wh !== null
            && $session->energy_wh > 0 && $session->last_meter_wh - $session->meter_start_wh === $session->energy_wh,
            409, 'Final charging meter evidence is required.');
        $start = CarbonImmutable::parse($session->started_at, 'UTC')->utc();
        $end = CarbonImmutable::parse($session->ended_at, 'UTC')->utc();
        abort_unless($start->lt($end) && $end->equalTo(CarbonImmutable::parse($session->stopped_at, 'UTC')), 409);
        $applied = DB::table('csms_inbox_events')->where('link_id', $session->link_id)->where('outcome', 'applied');
        abort_unless((clone $applied)->where(['type' => 'started', 'occurred_at' => $session->started_at, 'meter_wh' => $session->meter_start_wh])->exists()
            && (clone $applied)->where(['type' => 'ended', 'occurred_at' => $session->ended_at, 'meter_wh' => $session->last_meter_wh,
                'sequence' => $session->last_sequence])->exists(), 409, 'Final event timestamps require review.');

        $evseId = 'E'.$session->evse_id;
        $connectorId = 'C'.$session->connector_id;
        $connector = collect($location['evses'])->firstWhere('uid', $evseId);
        abort_unless($connector && collect($connector['connectors'])->contains('id', $connectorId), 409, 'Session connector is outside the approved location.');

        $invoice = Invoice::query()->where('charging_session_id', $sessionId)->where('vendor_id', $provider->owner_id)
            ->where('user_id', $session->user_id)->where('evidence', 'SIMULATED')->first();
        abort_unless($invoice && $invoice->currency === $session->currency && $invoice->energy_wh === $session->energy_wh
            && $invoice->minor_units >= 0 && $invoice->minor_units <= 3
            && $invoice->total_minor === $invoice->subtotal_minor + $invoice->tax_minor
            && $invoice->subtotal_minor === $session->total_minor
            && $invoice->subtotal_minor === Money::multiplyDivide((int) $session->energy_wh, (int) $session->rate_minor_per_kwh, 1000),
            409, 'Final canonical invoice evidence is required.');
        $provenance = app(ChargingProvenance::class)->inspect($invoice);
        abort_unless($provenance['kind'] === 'OCPP_SIMULATOR' && $provenance['source_verified'], 409, 'Applied charging source receipts are required.');
        $cost = ['excl_vat' => $invoice->subtotal_minor / (10 ** $invoice->minor_units), 'incl_vat' => $invoice->total_minor / (10 ** $invoice->minor_units)];
        $kwh = $session->energy_wh / 1000;
        $identity = ['country_code' => $location['country_code'], 'party_id' => $location['party_id']];
        $common = $identity + [
            'start_date_time' => $start->toISOString(), 'end_date_time' => $end->toISOString(), 'currency' => $session->currency,
            'cdr_token' => ['country_code' => $connection['party_country'], 'party_id' => $connection['party_id'],
                'uid' => $mapping['token_uid'], 'type' => 'APP_USER', 'contract_id' => $mapping['contract_id']],
            'auth_method' => 'WHITELIST', 'total_cost' => $cost,
        ];
        if ($module === 'sessions') {
            return $common + ['id' => 'Q'.$sessionId, 'kwh' => $kwh, 'status' => 'COMPLETED',
                'location_id' => $location['id'], 'evse_uid' => $evseId, 'connector_id' => $connectorId];
        }

        $cdrLocation = array_intersect_key($location, array_flip(['id', 'name', 'address', 'city', 'country', 'coordinates']));
        $cdrLocation['evse_uid'] = $evseId;
        $cdrLocation['evse_id'] = $mapping['evse_id'];
        $cdrLocation['connector_id'] = $connectorId;
        $rating = collect($connector['connectors'])->firstWhere('id', $connectorId);
        $cdrLocation += ['connector_standard' => $rating['standard'], 'connector_format' => $rating['format'], 'connector_power_type' => $rating['power_type']];

        return $common + ['id' => 'D'.$sessionId, 'session_id' => 'Q'.$sessionId, 'cdr_location' => $cdrLocation,
            'total_energy' => $kwh, 'total_time' => round($start->diffInSeconds($end) / 3600, 4),
            'charging_periods' => [['start_date_time' => $start->toISOString(), 'dimensions' => [['type' => 'ENERGY', 'volume' => $kwh]]]]];
    }
}
