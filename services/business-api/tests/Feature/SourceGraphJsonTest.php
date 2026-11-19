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

    #[TestWith(['2.500000', 'kWh', 2500])]
    #[TestWith(['2500.0', 'Wh', 2500])]
    #[TestWith(['0.001', 'kWh', 1])]
    #[TestWith(['9000000000.000', 'kWh', 9000000000000])]
    public function test_energy_jsonb_uses_original_wire_lexemes_not_binary_float_formatting(string $wire, string $unit, int $expected): void
    {
        $body = '{"data":{"MeterValues":[{"id":12,"sampledValue":[{"measurand":"Energy.Active.Import.Register","value":'.$wire.',"unitOfMeasure":{"unit":"'.$unit.'"}}]}]}}';
        $sample = SourceGraphJson::decode($body)['data']['MeterValues'][0]['sampledValue'][0];
        $this->assertSame($wire, $sample['value']);
        $this->assertSame($expected, SourceConsumer::wh($sample['value'], $unit));
    }

    #[TestWith([false])]
    #[TestWith([0.0])]
    #[TestWith(['0'])]
    #[TestWith([null])]
    public function test_non_integer_multiplier_cannot_be_coerced_into_zero(mixed $multiplier): void
    {
        $this->expectException(RuntimeException::class);
        SourceConsumer::wh('2500', 'Wh', $multiplier);
    }

    #[TestWith(['3.5001', 'kWh'])]
    #[TestWith(['2500.0000000000001', 'Wh'])]
    #[TestWith(['1e3', 'Wh'])]
    public function test_jsonb_decimal_precision_cannot_be_rounded_or_formatted_into_valid_energy(string $wire, string $unit): void
    {
        $body = '{"data":{"MeterValues":[{"id":12,"sampledValue":[{"measurand":"Energy.Active.Import.Register","value":'.$wire.'}]}]}}';
        $value = SourceGraphJson::decode($body)['data']['MeterValues'][0]['sampledValue'][0]['value'];
        $this->assertSame($wire, $value);
        $this->expectException(RuntimeException::class);
        SourceConsumer::wh($value, $unit);
    }
}
