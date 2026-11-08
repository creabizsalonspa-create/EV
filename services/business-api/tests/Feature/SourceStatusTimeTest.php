<?php

namespace Tests\Feature;

use App\Services\Charging\SourceStatusTime;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SourceStatusTimeTest extends TestCase
{
    public static function validTimes(): array
    {
        return [
            ['2020-01-01T00:00:00Z', '2020-01-01 00:00:00.000000'],
            ['2020-01-01T04:00:00+04:00', '2020-01-01 00:00:00.000000'],
            ['2020-01-01T23:59:59.123456-03:30', '2020-01-02 03:29:59.123456'],
            ['2024-02-29T00:00:00.1Z', '2024-02-29 00:00:00.100000'],
        ];
    }

    #[DataProvider('validTimes')]
    public function test_valid_source_instants_preserve_precision_and_explicit_offset(string $input, string $expected): void
    {
        $this->assertSame($expected, SourceStatusTime::parse($input)->format('Y-m-d H:i:s.u'));
        $this->assertSame('UTC', SourceStatusTime::parse($input)->timezoneName);
    }
}
