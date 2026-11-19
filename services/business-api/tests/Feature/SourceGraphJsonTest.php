<?php

namespace Tests\Feature;

use App\Services\Charging\SourceConsumer;
use App\Services\Charging\SourceGraphJson;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SourceGraphJsonTest extends TestCase
{
    public function test_only_declared_numeric_columns_preserve_wire_decimal_precision(): void
    {
        $wire = json_encode(['data' => ['Transactions' => [['id' => 10, 'meterStart' => '__BASE__', 'totalKwh' => '__TOTAL__', 'isActive' => true,
            'note' => 'quoted " meterStart:1.5 and 2.0']], 'MeterValues' => [['sampledValue' => [['value' => 3.5]]]]]], JSON_THROW_ON_ERROR);
        $wire = str_replace(['"__BASE__"', '"__TOTAL__"'], ['1.250000', '123456789.0000001'], $wire);
        $data = SourceGraphJson::decode($wire)['data'];
        $this->assertSame('1.250000', $data['Transactions'][0]['meterStart']);
        $this->assertSame('123456789.0000001', $data['Transactions'][0]['totalKwh']);
        $this->assertSame(10, $data['Transactions'][0]['id']);
        $this->assertTrue($data['Transactions'][0]['isActive']);
        $this->assertSame(3.5, $data['MeterValues'][0]['sampledValue'][0]['value']);
        $this->assertSame(json_decode($wire, true)['data']['Transactions'][0]['note'], $data['Transactions'][0]['note']);
        $this->assertSame(1250, SourceConsumer::wh($data['Transactions'][0]['meterStart'], 'kWh'));
        $this->expectException(RuntimeException::class);
        SourceConsumer::wh($data['Transactions'][0]['totalKwh'], 'kWh');
    }

    public function test_fractional_identifiers_booleans_and_encoded_strings_are_not_promoted_to_authority(): void
    {
        $data = SourceGraphJson::decode('{"data":{"Transactions":[{"id":1.5,"remoteStartId":2.0,"meterStart":true,"totalKwh":"2.500"}],"other":"{\\"meterStart\\":1.25}"}}')['data'];
        $this->assertSame(1.5, $data['Transactions'][0]['id']);
        $this->assertSame(2.0, $data['Transactions'][0]['remoteStartId']);
        $this->assertTrue($data['Transactions'][0]['meterStart']);
        $this->assertSame('2.500', $data['Transactions'][0]['totalKwh']);
        $this->assertSame('{"meterStart":1.25}', $data['other']);
    }

    #[TestWith(['1e3'])]
    #[TestWith(['-1.5'])]
    #[TestWith(['1.0001'])]
    #[TestWith(['9000000000000.001'])]
    public function test_wire_precision_and_unit_bounds_are_not_rounded_into_billable_energy(string $lexeme): void
    {
        $data = SourceGraphJson::decode('{"data":{"Transactions":[{"meterStart":'.$lexeme.'}]}}');
        $this->expectException(RuntimeException::class);
        SourceConsumer::wh($data['data']['Transactions'][0]['meterStart'], 'kWh');
    }

    #[TestWith(['{"data":01}'])]
    #[TestWith(['{"data":1.2.3}'])]
    #[TestWith(['{"data":"bad\\q"}'])]
    #[TestWith(['{"data":NaN}'])]
    #[TestWith(['null'])]
    public function test_malformed_wire_json_fails_closed(string $wire): void
    {
        $this->expectException(RuntimeException::class);
        SourceGraphJson::decode($wire);
    }

    public function test_source_body_has_a_bounded_parse_budget(): void
    {
        $this->expectException(RuntimeException::class);
        SourceGraphJson::decode(str_repeat(' ', 2097153));
    }
}
