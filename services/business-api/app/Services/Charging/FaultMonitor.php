<?php

namespace App\Services\Charging;

use Illuminate\Support\Facades\DB;

/** Caller holds the canonical binding lock and has rechecked current access. */
class FaultMonitor
{
    public static function generation(object $binding): string
    {
        return hash('sha256', json_encode([$binding->id, $binding->station_id, $binding->vendor_charger_id, $binding->identifier, $binding->protocol], JSON_THROW_ON_ERROR));
    }

    /** Both manual inspection and continuous observation must use the same protocol receipt contract. */
    public static function requireProtocolReceipt(string $protocol, array $event): void
    {
        abort_unless(in_array($protocol, ['1.6', '2.0.1'], true), 503, 'Unsupported source protocol.');
        abort_unless(is_string($event['connectorStatus'] ?? null) &&
            (! isset($event['errorCode']) || (is_string($event['errorCode']) &&
                strlen($event['errorCode']) <= 80)), 503, 'Malformed source status receipt.');
        // All status observations must belong to the canonical OCPP
        // status vocabulary. Unknown vendor states are not evidence
        // of availability, faults or recovery and must not advance
        // the durable cursor beyond an unread source event.
        abort_unless(in_array($event['connectorStatus'], ConnectorFreshness::STATUSES, true), 503, 'Unsupported source connector status.');
        // A 1.6 StatusNotification includes an explicit fault
        // errorCode. Do not persist a healthy/status cursor from
        // a partial, contradictory or non-1.6 projection.
        if ($protocol === '1.6') {
            abort_unless(in_array($event['connectorStatus'], [
                'Available', 'Preparing', 'Charging', 'SuspendedEVSE',
                'SuspendedEV', 'Finishing', 'Reserved', 'Unavailable',
                'Faulted',
            ], true), 503, 'Invalid OCPP 1.6 status.');
            $code = $event['errorCode'] ?? null;
            abort_unless(is_string($code) && in_array($code, [
                'NoError', 'ConnectorLockFailure', 'EVCommunicationError',
                'GroundFailure', 'HighTemperature', 'InternalError',
                'LocalListConflict', 'OtherError', 'OverCurrentFailure',
                'OverVoltage', 'PowerMeterFailure', 'PowerSwitchFailure',
                'ReaderFailure', 'ResetFailure', 'UnderVoltage', 'WeakSignal',
            ], true) && (($event['connectorStatus'] === 'Faulted') === ($code !== 'NoError')),
                503, 'Invalid or inconsistent OCPP 1.6 fault receipt.');
        } else {
            abort_unless(in_array($event['connectorStatus'], [
                'Available', 'Occupied', 'Reserved', 'Unavailable', 'Faulted',
            ], true), 503, 'Invalid OCPP 2.0.1 status.');
        }
    }

    public function ingest(object $binding, array $snapshot, ?callable $onAlertCreated = null, ?callable $onAlertRecovered = null): int
    {
        $generation = self::generation($binding);
        abort_unless(hash_equals($generation, $snapshot['source_generation'] ?? ''), 409, 'Source mapping changed; inspect again.');
        $created = 0;
        // Validate the complete batch before accepting any new observation
        // or invoking an alert callback, including the manual inspection path.
        foreach ($snapshot['events'] as $event) {
            abort_unless(is_array($event) && is_int($event['id'] ?? null) &&
                $event['id'] > 0 && $event['id'] <= 2147483647, 503, 'Malformed source status receipt.');
            self::requireProtocolReceipt($binding->protocol, $event);
        }
        foreach ($snapshot['events'] as $event) {
            $time = SourceStatusTime::parse($event['timestamp'] ?? null)->format('Y-m-d H:i:s.u');
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
        // Older retained observations predate the strict source worker.
        // Recheck their actual binding and protocol-specific clearance in
        // both the candidate selection and the final recovery lookup.
        $clearance = static function ($q, string $column) use ($binding) {
            $q->where($column, 'NoError');
            if ($binding->protocol === '2.0.1') {
                $q->orWhereNull($column);
            }
        };
        $alerts = DB::table('charging_fault_alerts as a')
            ->join('charging_status_observations as o', 'o.id', '=', 'a.observation_id')
            ->where('o.binding_id', $binding->id)->where('o.source_generation', $generation)
            ->whereNull('a.recovery_observation_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('charging_status_observations as r')
                ->whereColumn('r.binding_id', 'o.binding_id')
                ->whereColumn('r.source_generation', 'o.source_generation')
                ->whereColumn('r.evse_number', 'o.evse_number')
                ->whereColumn('r.connector_number', 'o.connector_number')
                ->whereColumn('r.source_event_id', '>', 'o.source_event_id')
                ->whereColumn('r.occurred_at', '>', 'o.occurred_at')
                ->where('r.status', 'Available')
                ->where(fn ($codes) => $clearance($codes, 'r.error_code')))
            ->orderBy('a.id')->limit(250)->select('a.id as alert_id', 'o.*')->get();
        foreach ($alerts as $alert) {
            $recovery = DB::table('charging_status_observations')
                ->where('binding_id', $binding->id)->where('source_generation', $generation)
                ->where('evse_number', $alert->evse_number)->where('connector_number', $alert->connector_number)
                ->where('source_event_id', '>', $alert->source_event_id)->where('occurred_at', '>', $alert->occurred_at)
                ->where('status', 'Available')->where(fn ($q) => $clearance($q, 'error_code'))
                ->orderBy('source_event_id')->first();
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
