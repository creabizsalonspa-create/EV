<?php

namespace Tests\Unit;

use App\Services\Charging\DirectRegistrationHistory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DirectRegistrationHistoryTest extends TestCase
{
    public static function protocols(): array
    {
        return [['1.6'], ['2.0.1']];
    }

    #[DataProvider('protocols')]
    public function test_rotation_preserves_the_original_registration_fingerprint(string $protocol): void
    {
        $offline = ['id' => 42, 'tenantId' => 1, 'ocppConnectionName' => 'DIRECT-4',
            'protocol' => 'ocpp'.$protocol, 'isOnline' => false,
            'chargePointVendor' => 'Synthetic', 'chargePointModel' => 'Fixture'];
        $row = (object) ['id' => 1, 'charger_id' => 4, 'binding_id' => 3, 'profile_id' => 2,
            'source_station_id' => 42, 'source_generation' => str_repeat('a', 64),
            'source_receipt_hash' => hash('sha256', json_encode($offline, JSON_THROW_ON_ERROR)),
            'verified_at' => '2026-11-28 00:00:00.000000', 'payload_hash' => hash('sha256', $protocol)];
        $authority = [1, str_repeat('b', 64)];
        $history = (object) ['epoch' => 1, 'authority_fingerprint' => $authority[1],
            'registration_fingerprint' => DirectRegistrationHistory::fingerprint($row, $authority)];

        $this->assertSame(hash('sha256', json_encode([
            $row->id, $row->charger_id, $row->binding_id, $row->profile_id,
            $row->source_station_id, $row->source_generation, $row->source_receipt_hash,
            $row->verified_at, $row->payload_hash, $authority,
        ], JSON_THROW_ON_ERROR)), $history->registration_fingerprint);
        $this->assertTrue(DirectRegistrationHistory::matches($row, $history, 1));
        $this->assertTrue(DirectRegistrationHistory::matches($row, $history, 2));
        $this->assertFalse(DirectRegistrationHistory::matches($row, $history, 0));

        foreach (get_object_vars($row) as $key => $value) {
            $changed = clone $row;
            $changed->$key = is_int($value) ? $value + 1 : $value.'x';
            $this->assertFalse(DirectRegistrationHistory::matches($changed, $history, 2), $key);
        }

        $changed = clone $row;
        $changed->source_receipt_hash = hash('sha256', json_encode(array_reverse($offline, true), JSON_THROW_ON_ERROR));
        $this->assertFalse(DirectRegistrationHistory::matches($changed, $history, 2));
        $offline['id'] = 43;
        $changed->source_station_id = 43;
        $changed->source_receipt_hash = hash('sha256', json_encode($offline, JSON_THROW_ON_ERROR));
        $this->assertFalse(DirectRegistrationHistory::matches($changed, $history, 2));

        foreach (['epoch' => 0, 'authority_fingerprint' => str_repeat('c', 64),
            'registration_fingerprint' => str_repeat('d', 64)] as $key => $value) {
            $bad = clone $history;
            $bad->$key = $value;
            $this->assertFalse(DirectRegistrationHistory::matches($row, $bad, 2), $key);
        }
        $bad = clone $history;
        $bad->epoch = '1';
        $this->assertFalse(DirectRegistrationHistory::matches($row, $bad, 2));

        $next = [2, str_repeat('c', 64)];
        $new = (object) ['epoch' => 2, 'authority_fingerprint' => $next[1],
            'registration_fingerprint' => DirectRegistrationHistory::fingerprint($row, $next)];
        $this->assertNotSame($history->registration_fingerprint, $new->registration_fingerprint);
        $this->assertTrue(DirectRegistrationHistory::matches($row, $new, 2));
        $this->assertTrue(DirectRegistrationHistory::matches($row, $new, 3));
    }
}
