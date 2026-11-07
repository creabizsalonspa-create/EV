<?php

namespace App\Services\Charging;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Caller holds the canonical binding lock and has rechecked current access. */
class FaultMonitor
{
    public static function generation(object $binding): string
    {
        return hash('sha256', json_encode([$binding->id, $binding->station_id, $binding->vendor_charger_id, $binding->identifier, $binding->protocol], JSON_THROW_ON_ERROR));
    }

    public function ingest(object $binding, array $snapshot, ?callable $onAlertCreated = null, ?callable $onAlertRecovered = null): int
    {
        $generation = self::generation($binding);
        abort_unless(hash_equals($generation, $snapshot['source_generation'] ?? ''), 409, 'Source mapping changed; inspect again.');
        $created = 0;
        foreach ($snapshot['events'] as $event) {
            try {
                $time = CarbonImmutable::parse($event['timestamp'])->utc()->format('Y-m-d H:i:s.u');
            } catch (Throwable) {
                abort(503, 'Invalid source event time.');
            }
            $values = ['binding_id' => $binding->id, 'source_generation' => $generation, 'source_event_id' => $event['id'], 'evse_number' => $event['evseId'] ?? 0, 'connector_number' => $event['connectorId'] ?? 0, 'status' => $event['connectorStatus'], 'error_code' => $event['errorCode'] ?? null, 'occurred_at' => $time];
            abort_unless(is_int($values['evse_number']) && $values['evse_number'] >= 0 && is_int($values['connector_number']) && $values['connector_number'] >= 0, 503, 'Invalid source component.');
            $fingerprint = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
            $old = DB::table('charging_status_observations')->where('source_generation', $generation)->where('source_event_id', $event['id'])->first();
            if ($old) {
                abort_unless(hash_equals($old->fingerprint, $fingerprint), 409, 'Changed source event requires reconciliation.');

                continue;
            }
            $id = DB::table('charging_status_observations')->insertGetId($values + ['fingerprint' => $fingerprint, 'created_at' => now()]);
            if ($values['status'] === 'Faulted' || ! in_array($values['error_code'], [null, 'NoError'], true)) {
                $alertId = DB::table('charging_fault_alerts')->insertGetId(['observation_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
                if ($onAlertCreated !== null) {
                    $onAlertCreated($alertId);
                }
                $created++;
            }
        }
        // Persisted recovery evidence also handles backwards pagination: an older
        // fault discovered later can still link to a previously observed recovery.
        $alerts = DB::table('charging_fault_alerts as a')->join('charging_status_observations as o', 'o.id', '=', 'a.observation_id')->where('o.source_generation', $generation)->whereNull('a.recovery_observation_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('charging_status_observations as r')->whereColumn('r.source_generation', 'o.source_generation')->whereColumn('r.evse_number', 'o.evse_number')->whereColumn('r.connector_number', 'o.connector_number')->whereColumn('r.source_event_id', '>', 'o.source_event_id')->whereColumn('r.occurred_at', '>', 'o.occurred_at')->where('r.status', 'Available')->where(fn ($codes) => $codes->whereNull('r.error_code')->orWhere('r.error_code', 'NoError')))
            ->orderBy('a.id')->limit(250)->select('a.id as alert_id', 'o.*')->get();
        foreach ($alerts as $alert) {
            $recovery = DB::table('charging_status_observations')->where('source_generation', $generation)->where('evse_number', $alert->evse_number)->where('connector_number', $alert->connector_number)->where('source_event_id', '>', $alert->source_event_id)->where('occurred_at', '>', $alert->occurred_at)->where('status', 'Available')->where(fn ($q) => $q->whereNull('error_code')->orWhere('error_code', 'NoError'))->orderBy('source_event_id')->first();
            if ($recovery) {
                // Compare-and-set ensures a replay or concurrent observer does
                // not announce recovery twice. The lock belongs to the caller.
                $linked = DB::table('charging_fault_alerts')
                    ->where('id', $alert->alert_id)
                    ->whereNull('recovery_observation_id')
                    ->update(['recovery_observation_id' => $recovery->id, 'updated_at' => now()]);
                if ($linked === 1 && $onAlertRecovered !== null) {
                    $onAlertRecovered((int) $alert->alert_id);
                }
            }
        }

        return $created;
    }
}
