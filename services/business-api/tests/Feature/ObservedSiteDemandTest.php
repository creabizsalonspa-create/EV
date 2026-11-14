<?php

namespace Tests\Feature;

use App\Services\Charging\ObservedSiteDemand;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ObservedSiteDemandTest extends TestCase
{
    public function test_concurrent_distinct_evses_sum_only_during_overlap_and_adjacent_intervals_do_not_overlap(): void
    {
        $report = app(ObservedSiteDemand::class)->project([[0, 10, 1, 100], [5, 15, 2, 200], [15, 20, 2, 400]]);
        $this->assertSame(400, $report['maximum_observed_site_interval_average_w']);
        $this->assertSame(20, $report['demand_observed_seconds']);
        $this->assertSame(0, $report['demand_ambiguous_evse_seconds']);
        $overlap = app(ObservedSiteDemand::class)->project([[0, 10, 1, 100], [5, 15, 2, 200]]);
        $this->assertSame(300, $overlap['maximum_observed_site_interval_average_w']);
    }

    public function test_conflicting_same_evse_sessions_are_excluded_while_other_evses_remain_observed(): void
    {
        $report = app(ObservedSiteDemand::class)->project([[0, 10, 1, 100], [0, 10, 1, 200], [0, 10, 2, 50]]);
        $this->assertSame(50, $report['maximum_observed_site_interval_average_w']);
        $this->assertSame(10, $report['demand_ambiguous_evse_seconds']);
        $this->assertSame(10, $report['demand_observed_seconds']);
        $missing = app(ObservedSiteDemand::class)->project([[0, 10, 1, 100], [0, 10, 1, 100]]);
        $this->assertNull($missing['maximum_observed_site_interval_average_w']);
        $this->assertSame(0, $missing['demand_observed_seconds']);
        $this->assertNull(app(ObservedSiteDemand::class)->project([])['maximum_observed_site_interval_average_w']);
        $this->assertSame(0, app(ObservedSiteDemand::class)->project([[0, 10, 1, 0]])['maximum_observed_site_interval_average_w']);
    }

    public function test_concurrent_sum_rejects_inexact_numbers_instead_of_overflowing(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Report exceeds exact numeric bounds.');
        app(ObservedSiteDemand::class)->project([[0, 10, 1, 9007199254740991], [0, 10, 2, 1]]);
    }
}
