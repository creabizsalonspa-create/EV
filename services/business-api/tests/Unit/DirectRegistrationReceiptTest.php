<?php

namespace Tests\Unit;

use App\Services\Charging\DirectRegistrationReceipt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DirectRegistrationReceiptTest extends TestCase
{
    public static function protocols(): array
    {
        return [['1.6'], ['2.0.1']];
    }

    #[DataProvider('protocols')]
    public function test_only_exact_typed_offline_registration_fields_match_in_any_key_order(string $protocol): void
    {
        $fields = ['id' => 42, 'tenantId' => 1, 'ocppConnectionName' => 'DIRECT-4',
            'protocol' => 'ocpp'.$protocol, 'isOnline' => false,
            'chargePointVendor' => 'Synthetic', 'chargePointModel' => 'Fixture'];
        $digest = fn (array $row) => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
        $matches = fn (string $hash) => DirectRegistrationReceipt::matches(
            $hash, 42, 'DIRECT-4', $protocol, 'Synthetic', 'Fixture');
        $this->assertTrue($matches($digest($fields)));
        $this->assertTrue($matches($digest(array_reverse($fields, true))));
        $sorted = $fields;
        ksort($sorted);
        $this->assertTrue($matches($digest($sorted)));
        $shifted = $fields;
        unset($shifted['id']);
        $shifted['id'] = 42;
        $this->assertTrue($matches($digest($shifted)));
        foreach (['id' => 43, 'tenantId' => 2, 'ocppConnectionName' => 'OTHER',
            'protocol' => 'ocpp0', 'isOnline' => true, 'chargePointVendor' => 'Other',
            'chargePointModel' => 'Other'] as $key => $value) {
            $this->assertFalse($matches($digest(array_replace($fields, [$key => $value]))));
        }
        foreach (['id' => '42', 'tenantId' => '1', 'isOnline' => 0] as $key => $value) {
            $this->assertFalse($matches($digest(array_replace($fields, [$key => $value]))));
        }
        $missing = $fields;
        unset($missing['id']);
        $this->assertFalse($matches($digest($missing)));
        $this->assertFalse($matches($digest($fields + ['firmwareVersion' => 'x'])));
        $this->assertFalse($matches(str_repeat('a', 64)));
        $this->assertFalse($matches('invalid'));
        $this->assertFalse(DirectRegistrationReceipt::matches(
            $digest($fields), 0, 'DIRECT-4', $protocol, 'Synthetic', 'Fixture'));
        $this->assertFalse(DirectRegistrationReceipt::matches(
            $digest($fields), 42, 'DIRECT-4', '3.0', 'Synthetic', 'Fixture'));
    }
}
