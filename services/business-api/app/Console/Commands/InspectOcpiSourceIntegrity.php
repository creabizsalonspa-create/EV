<?php

namespace App\Console\Commands;

use App\Services\Ocpi\PartnerClient;
use App\Services\Ocpi\StoredSourceIntegrity;
use Illuminate\Console\Command;
use Throwable;

/** Privileged operator shell only; no HTTP route, external I/O or writes. */
final class InspectOcpiSourceIntegrity extends Command
{
    protected $signature = 'ocpi:inspect-source-integrity {provider} {module} {--after=0} {--limit=50}';

    protected $description = 'Inspect one bounded stored OCPI source page without network, repair or conformance claims';

    public function handle(StoredSourceIntegrity $integrity): int
    {
        $provider = $this->integer($this->argument('provider'), 1, PHP_INT_MAX);
        $after = $this->integer($this->option('after'), 0, PHP_INT_MAX);
        $limit = $this->integer($this->option('limit'), 1, StoredSourceIntegrity::MAX_PAGE);
        $module = $this->argument('module');
        if ($provider === null || $after === null || $limit === null
            || ! in_array($module, PartnerClient::MODULES, true)) {
            $this->error('Specify a valid provider, supported module and bounded cursor (limit 1-100).');

            return self::INVALID;
        }
        try {
            $report = $integrity->inspect($provider, $module, $after, $limit);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));

            return $report['status'] === 'page_consistent' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Stored source inspection unavailable; review authorized local evidence.');

            return self::FAILURE;
        }
    }

    private function integer(mixed $value, int $min, int $max): ?int
    {
        if (! is_int($value) && (! is_string($value) || ! preg_match('/^(?:0|[1-9][0-9]{0,18})$/D', $value))) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);

        return $parsed === false ? null : $parsed;
    }
}
