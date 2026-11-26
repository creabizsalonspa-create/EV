<?php

namespace App\Console\Commands;

use App\Services\Discovery\LicensedSourceQuality;
use Illuminate\Console\Command;

/** Local operator inspection only; no user or station coordinates are recorded. */
final class InspectLicensedSourceQuality extends Command
{
    protected $signature = 'discovery:licensed-quality';

    protected $description = 'Read bounded best-effort licensed discovery quality aggregates without provider requests';

    public function handle(LicensedSourceQuality $quality): int
    {
        $this->line(json_encode($quality->report(), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
