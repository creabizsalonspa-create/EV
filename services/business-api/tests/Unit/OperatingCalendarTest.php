<?php

namespace Tests\Unit;

use App\Services\Charging\OperatingCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class OperatingCalendarTest extends TestCase
{
    private function policy(string $timezone = 'Asia/Dubai'): array
    {
        return ['timezone' => $timezone, 'weekly_hours' => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], ['open' => '08:00', 'close' => '18:00']), 'closed_dates' => []];
    }

    private function permits(array $policy, string $from, string $to): bool
    {
        return (new OperatingCalendar)->permits($policy, CarbonImmutable::parse($from, 'UTC'), CarbonImmutable::parse($to, 'UTC'));
    }

    public function test_station_timezone_half_open_edges_and_entire_booking_window(): void
    {
        $p = $this->policy();
        $this->assertTrue($this->permits($p, '2026-11-16 04:00', '2026-11-16 04:30'));
        $this->assertTrue($this->permits($p, '2026-11-16 13:30', '2026-11-16 14:00'));
        $this->assertFalse($this->permits($p, '2026-11-16 03:30', '2026-11-16 04:00'));
        $this->assertFalse($this->permits($p, '2026-11-16 13:45', '2026-11-16 14:15'));
        $this->assertTrue($this->permits($p, '2026-11-16 13:59:59.999999', '2026-11-16 14:00:00.000000'));
        $this->assertFalse($this->permits($p, '2026-11-16 14:00:00.000000', '2026-11-16 14:00:00.000001'));
    }

    public function test_overnight_spill_and_both_local_calendar_holidays(): void
    {
        $p = $this->policy();
        $p['weekly_hours'] = array_fill_keys(array_keys($p['weekly_hours']), ['open' => null, 'close' => null]);
        $p['weekly_hours']['mon'] = ['open' => '22:00', 'close' => '02:00'];
        $this->assertTrue($this->permits($p, '2026-11-16 21:30', '2026-11-16 22:00'));
        $this->assertFalse($this->permits($p, '2026-11-16 22:00', '2026-11-16 22:30'));
        $p['closed_dates'] = ['2026-11-17'];
        $this->assertFalse($this->permits($p, '2026-11-16 19:45', '2026-11-16 20:15'));
        $p['closed_dates'] = ['2026-11-16'];
        $this->assertFalse($this->permits($p, '2026-11-16 21:00', '2026-11-16 21:30'));
    }

    public function test_twenty_four_hour_day_does_not_spill_into_a_closed_next_day(): void
    {
        $p = $this->policy('UTC');
        $p['weekly_hours']['mon'] = ['open' => '07:00', 'close' => '07:00'];
        $p['weekly_hours']['tue'] = ['open' => null, 'close' => null];
        $this->assertTrue($this->permits($p, '2026-11-16 00:00', '2026-11-16 00:30'));
        $this->assertTrue($this->permits($p, '2026-11-16 23:30', '2026-11-17 00:00'));
        $this->assertFalse($this->permits($p, '2026-11-16 23:45', '2026-11-17 00:15'));
    }

    public function test_repeated_and_skipped_dst_minutes_follow_actual_utc_intervals(): void
    {
        $p = $this->policy('America/New_York');
        $p['weekly_hours']['sun'] = ['open' => '01:00', 'close' => '02:00'];
        $this->assertTrue($this->permits($p, '2026-11-01 05:30', '2026-11-01 06:00'));
        $this->assertTrue($this->permits($p, '2026-11-01 06:30', '2026-11-01 07:00'));
        $this->assertFalse($this->permits($p, '2026-11-01 06:45', '2026-11-01 07:15'));
        $p['weekly_hours']['sun'] = ['open' => '01:00', 'close' => '03:00'];
        $this->assertTrue($this->permits($p, '2027-03-14 06:30', '2027-03-14 07:00'));
        $this->assertFalse($this->permits($p, '2027-03-14 07:00', '2027-03-14 07:30'));
    }

    public function test_malformed_policies_and_unbounded_intervals_fail_closed(): void
    {
        $p = $this->policy();
        foreach (['Mars/Phobos', '', '+04:00'] as $zone) {
            $bad = array_replace($p, ['timezone' => $zone]);
            $this->assertFalse($this->permits($bad, '2026-11-16 04:00', '2026-11-16 04:30'));
        }
        $bad = $p;
        $bad['weekly_hours']['mon']['open'] = '24:00';
        $this->assertFalse($this->permits($bad, '2026-11-16 04:00', '2026-11-16 04:30'));
        $bad = $p;
        $bad['closed_dates'] = ['2026-02-30'];
        $this->assertFalse($this->permits($bad, '2026-11-16 04:00', '2026-11-16 04:30'));
        $this->assertFalse($this->permits($p, '2026-11-16 04:00', '2026-11-16 04:00'));
        $this->assertFalse($this->permits($p, '2026-11-16 04:00', '2026-11-18 04:00'));
    }
}
