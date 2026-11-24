<?php

namespace App\Console\Commands;

use App\Services\Discovery\LicensedCoverageAudit;
use Illuminate\Console\Command;

final class InspectLicensedCoverage extends Command
{
    protected $signature = 'discovery:licensed-coverage';

    protected $description = 'Inspect configured public benchmark coverage samples from cache only';

    public function handle(LicensedCoverageAudit $audit): int
    {
        $report = $audit->report();
        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return $report['enabled'] ? self::SUCCESS : self::FAILURE;
    }
}
