<?php

namespace Tests\Feature;

use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\RecordNormalizer;
use Tests\TestCase;

class OcpiSourceTimestampTest extends TestCase
{
    private function fixture(string $module): array
    {
        $common = [
            'country_code' => 'AE',
            'party_id' => 'TST',
            'id' => 'fixture-'.$module,
            'last_updated' => '2025-03-10T09:00:00.123456',
        ];

        $fields = match ($module) {
            'locations' => [
                'publish' => true,
                'address' => 'Source test',
                'city' => 'Dubai',
                'country' => 'ARE',
                'coordinates' => ['latitude' => '25.1', 'longitude' => '55.1'],
            ],
            'tariffs' => [
                'currency' => 'AED',
                'elements' => [['price_components' => [['type' => 'ENERGY', 'price' => '0.50', 'step_size' => 1]]]],
            ],
            'sessions' => [
                'start_date_time' => '2025-03-10T08:00:00Z',
                'end_date_time' => '2025-03-10T08:30:00',
                'kwh' => '1.5',
                'currency' => 'AED',
                'status' => 'COMPLETED',
                'location_id' => 'L1',
                'evse_uid' => 'E1',
                'connector_id' => '1',
            ],
            'cdrs' => [
                'start_date_time' => '2025-03-10T08:00:00Z',
                'end_date_time' => '2025-03-10T08:30:00',
                'currency' => 'AED',
                'total_energy' => '1.5',
                'total_time' => '0.5',
                'total_cost' => ['excl_vat' => '1.25'],
                'cdr_location' => ['id' => 'L1', 'evse_uid' => 'E1', 'connector_id' => '1'],
            ],
        };

        return $common + $fields;
    }

    public function test_ocpi_source_timestamp_accepts_utc_with_or_without_z_in_all_modules(): void
    {
        $normalizer = app(RecordNormalizer::class);

        foreach (['locations', 'tariffs', 'sessions', 'cdrs'] as $module) {
            $normalized = $normalizer->normalize($module, $this->fixture($module));
            $this->assertSame('2025-03-10T09:00:00.123456Z', $normalized['last_updated']);
            $explicit = array_replace($this->fixture($module), ['last_updated' => '2025-03-10T09:00:00.123456Z']);
            $this->assertSame($normalized['last_updated'], $normalizer->normalize($module, $explicit)['last_updated']);
            if (in_array($module, ['sessions', 'cdrs'], true)) {
                $this->assertSame('2025-03-10T08:00:00.000000Z', $normalized['start_date_time']);
                $this->assertSame('2025-03-10T08:30:00.000000Z', $normalized['end_date_time']);
            }
        }
    }

    public function test_source_timestamp_rejects_timezone_free_relative_and_overprecise_evidence(): void
    {
        $normalizer = app(RecordNormalizer::class);

        foreach (['locations', 'tariffs', 'sessions', 'cdrs'] as $module) {
            foreach (['2025-03-10 11:00:00', 'yesterday', '2025-02-30T11:00:00Z', '2025-03-10T25:00:00Z', '2025-03-10T11:61:00Z', '2025-03-10T11:00:00.1234567Z', '2025-03-10T11:00:00+02:00', '2025-03-10T11:00:00+00:00'] as $invalid) {
                try {
                    $normalizer->normalize($module, array_replace($this->fixture($module), ['last_updated' => $invalid]));
                    $this->fail('Timezone-free or imprecise '.$module.' source timestamp was accepted.');
                } catch (OcpiException $error) {
                    $this->assertStringContainsString('last_updated', $error->getMessage());
                }
            }
        }
    }

    public function test_session_and_cdr_ranges_compare_explicit_and_implicit_utc_not_server_local_time(): void
    {
        $normalizer = app(RecordNormalizer::class);
        $originalTimeZone = date_default_timezone_get();
        date_default_timezone_set('Asia/Dubai');
        try {
            foreach (['sessions', 'cdrs'] as $module) {
                // A Z-free DateTime is already UTC, not UTC+04:00.
                $equal = array_replace($this->fixture($module), [
                    'start_date_time' => '2025-03-10T08:00:00',
                    'end_date_time' => '2025-03-10T08:00:00Z',
                ]);
                $ok = $normalizer->normalize($module, $equal);
                $this->assertSame($ok['start_date_time'], $ok['end_date_time']);

                foreach ([
                    ['start_date_time' => '2025-03-10T08:00:00', 'end_date_time' => '2025-03-10T07:59:59Z'],
                    ['start_date_time' => '2025-03-10T08:00:00Z', 'end_date_time' => '2025-03-10T07:59:59'],
                ] as $reversed) {
                    try {
                        $normalizer->normalize($module, array_replace($this->fixture($module), $reversed));
                        $this->fail('Reversed UTC '.$module.' source chronology was accepted.');
                    } catch (OcpiException $error) {
                        $this->assertStringContainsString('end_date_time', $error->getMessage());
                    }
                }
            }
        } finally {
            date_default_timezone_set($originalTimeZone);
        }
    }

    public function test_session_and_cdr_boundaries_reject_ambiguous_local_source_times(): void
    {
        $normalizer = app(RecordNormalizer::class);

        foreach (['sessions', 'cdrs'] as $module) {
            foreach (['start_date_time' => '2025-03-10 08:00:00', 'end_date_time' => '2025-03-10T08:30:00+04:00'] as $field => $invalid) {
                try {
                    $normalizer->normalize($module, array_replace($this->fixture($module), [$field => $invalid]));
                    $this->fail('Timezone-free '.$module.' '.$field.' was accepted.');
                } catch (OcpiException $error) {
                    $this->assertStringContainsString($field, $error->getMessage());
                }
            }
        }
    }
}
