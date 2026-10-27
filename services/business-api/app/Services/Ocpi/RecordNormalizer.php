<?php

namespace App\Services\Ocpi;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

class RecordNormalizer
{
    public function normalize(string $module, array $record): array
    {
        $common = ['country_code' => 'required|string|regex:/^[A-Z]{2}$/', 'party_id' => 'required|string|regex:/^[A-Z0-9]{3}$/', 'id' => 'required|string|max:64', 'last_updated' => 'required|date'];
        $rules = match ($module) {
            'locations' => ['publish' => 'required|boolean', 'name' => 'sometimes|string|max:255', 'address' => 'required|string|max:255', 'city' => 'required|string|max:255', 'country' => 'required|string|regex:/^[A-Z]{3}$/', 'coordinates' => 'required|array', 'coordinates.latitude' => 'required|numeric|between:-90,90', 'coordinates.longitude' => 'required|numeric|between:-180,180', 'evses' => 'sometimes|array|list|max:100', 'evses.*.uid' => 'required|string|max:64', 'evses.*.status' => 'required|in:AVAILABLE,BLOCKED,CHARGING,INOPERATIVE,OUTOFORDER,PLANNED,REMOVED,RESERVED,UNKNOWN', 'evses.*.connectors' => 'required|array|list|min:1|max:20', 'evses.*.connectors.*.id' => 'required|string|max:64', 'evses.*.connectors.*.standard' => 'required|string|max:64'],
            'tariffs' => ['currency' => 'required|string|regex:/^[A-Z]{3}$/', 'elements' => 'required|array|list|min:1|max:100', 'elements.*.price_components' => 'required|array|list|min:1|max:20', 'elements.*.price_components.*.type' => 'required|in:ENERGY,FLAT,PARKING_TIME,TIME', 'elements.*.price_components.*.price' => 'required|numeric|min:0|max:1000000', 'elements.*.price_components.*.step_size' => 'required|integer|min:1|max:1000000', 'elements.*.price_components.*.vat' => 'sometimes|numeric|between:0,100'],
            'sessions' => ['start_date_time' => 'required|date', 'end_date_time' => 'required_if:status,COMPLETED|nullable|date|after_or_equal:start_date_time', 'kwh' => 'required|numeric|min:0|max:1000000', 'currency' => 'required|string|regex:/^[A-Z]{3}$/', 'status' => 'required|in:ACTIVE,COMPLETED,INVALID,PENDING,RESERVATION', 'location_id' => 'required|string|max:64', 'evse_uid' => 'required|string|max:64', 'connector_id' => 'required|string|max:64', 'total_cost' => 'sometimes|array', 'total_cost.excl_vat' => 'sometimes|numeric|min:0|max:1000000000', 'total_cost.incl_vat' => 'sometimes|numeric|min:0|max:1000000000'],
            'cdrs' => ['start_date_time' => 'required|date', 'end_date_time' => 'required|date|after_or_equal:start_date_time', 'currency' => 'required|string|regex:/^[A-Z]{3}$/', 'total_energy' => 'required|numeric|min:0|max:1000000', 'total_time' => 'required|numeric|min:0|max:1000000', 'total_cost' => 'required|array', 'total_cost.excl_vat' => 'required|numeric|min:0|max:1000000000', 'total_cost.incl_vat' => 'sometimes|numeric|min:0|max:1000000000', 'cdr_location' => 'required|array', 'cdr_location.id' => 'required|string|max:64', 'cdr_location.evse_uid' => 'required|string|max:64', 'cdr_location.connector_id' => 'required|string|max:64'],
            default => throw new OcpiException('Unsupported OCPI record module.'),
        };
        $validator = Validator::make($record, $common + $rules);
        if ($validator->fails()) {
            throw new OcpiException('Invalid '.$module.' record fields: '.implode(', ', array_keys($validator->errors()->toArray())));
        }
        $updated = CarbonImmutable::parse($record['last_updated'])->utc();
        if ($updated->greaterThan(now()->addMinutes(5))) {
            throw new OcpiException('Operator record timestamp is in the future.');
        }
        $identity = ['country_code' => $record['country_code'], 'party_id' => $record['party_id'], 'id' => $record['id'], 'last_updated' => $updated->toISOString()];
        $n = match ($module) {
            'locations' => ['name' => $record['name'] ?? $record['id'], 'publish' => (bool) $record['publish'], 'address' => $record['address'], 'city' => $record['city'], 'country' => $record['country'], 'latitude' => (string) $record['coordinates']['latitude'], 'longitude' => (string) $record['coordinates']['longitude'], 'evses' => array_map(fn ($e) => ['uid' => $e['uid'], 'reported_status' => $e['status'], 'connectors' => array_map(fn ($c) => ['id' => $c['id'], 'standard' => $c['standard']], $e['connectors'])], $record['evses'] ?? []), 'availability' => 'unknown', 'remote_control' => false, 'reservable' => false],
            'tariffs' => ['currency' => $record['currency'], 'elements' => array_map(fn ($e) => ['price_components' => array_map(fn ($c) => ['type' => $c['type'], 'price' => (string) $c['price'], 'step_size' => (int) $c['step_size'], 'vat' => isset($c['vat']) ? (string) $c['vat'] : null], $e['price_components'])], $record['elements']), 'billing_eligible' => false],
            'sessions' => ['currency' => $record['currency'], 'status' => $record['status'], 'start_date_time' => CarbonImmutable::parse($record['start_date_time'])->utc()->toISOString(), 'end_date_time' => isset($record['end_date_time']) ? CarbonImmutable::parse($record['end_date_time'])->utc()->toISOString() : null, 'energy_kwh' => (string) $record['kwh'], 'location_id' => $record['location_id'], 'evse_uid' => $record['evse_uid'], 'connector_id' => $record['connector_id'], 'cost' => $this->cost($record['total_cost'] ?? []), 'billing_eligible' => false],
            'cdrs' => ['currency' => $record['currency'], 'start_date_time' => CarbonImmutable::parse($record['start_date_time'])->utc()->toISOString(), 'end_date_time' => CarbonImmutable::parse($record['end_date_time'])->utc()->toISOString(), 'energy_kwh' => (string) $record['total_energy'], 'time_hours' => (string) $record['total_time'], 'cost' => $this->cost($record['total_cost']), 'location_id' => $record['cdr_location']['id'], 'evse_uid' => $record['cdr_location']['evse_uid'], 'connector_id' => $record['cdr_location']['connector_id'], 'billing_eligible' => false],
        };

        return $identity + $n;
    }

    private function cost(array $cost): array
    {
        return array_filter(['excl_vat' => isset($cost['excl_vat']) ? (string) $cost['excl_vat'] : null, 'incl_vat' => isset($cost['incl_vat']) ? (string) $cost['incl_vat'] : null], fn ($v) => $v !== null);
    }

    public function hash(array $record): string
    {
        $canonical = function ($value) use (&$canonical) {
            if (! is_array($value)) {
                return $value;
            }if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', json_encode($canonical($record), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
