<?php

namespace App\Console\Commands;

use App\Services\Charging\CsmsPilotProbe;
use Illuminate\Console\Command;
use Throwable;

/** Operator shell only: no HTTP route or production charger commands. */
final class InspectCsmsPilotSource extends Command
{
    protected $signature = 'csms:pilot-probe {binding}';

    protected $description = 'Inspect one current simulated OCPP source safely; never certify real hardware or production readiness';

    public function handle(CsmsPilotProbe $probe): int
    {
        $value = (string) $this->argument('binding');
        if (! preg_match('/^[1-9][0-9]{0,17}$/D', $value) ||
            filter_var($value, FILTER_VALIDATE_INT) === false) {
            $this->error('Specify a positive canonical binding ID.');

            return self::INVALID;
        }
        try {
            $report = $probe->inspect((int) $value);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));

            return $report['software_source_observed'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            // Do not print upstream payloads, authenticated transport URLs,
            // request headers, source exception bodies or any access tokens.
            $this->error('Source pilot probe blocked; inspect authorized local diagnostic evidence.');

            return self::FAILURE;
        }
    }
}
