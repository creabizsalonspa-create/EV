<?php

namespace App\Console\Commands;

use App\Services\Discovery\LicensedCoverageHistory;
use Illuminate\Console\Command;

final class InspectLicensedCoverageHistory extends Command
{
    protected $signature = 'discovery:licensed-coverage-history {--capture : Store one validated hourly benchmark sample}';

    protected $description = 'Inspect or capture bounded public benchmark coverage history without provider I/O';

    public function handle(LicensedCoverageHistory $history): int
    {
        if ($this->option('capture')) {
            $result = $history->capture();
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return in_array($result['state'], ['captured', 'already_captured', 'no_observations'], true)
                ? self::SUCCESS : self::FAILURE;
        }

        $report = $history->report();
        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return $report['enabled'] ? self::SUCCESS : self::FAILURE;
    }
}
