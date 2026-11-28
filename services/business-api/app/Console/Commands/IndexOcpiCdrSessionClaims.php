<?php

namespace App\Console\Commands;

use App\Services\Ocpi\CdrSessionClaims;
use Illuminate\Console\Command;
use Throwable;

final class IndexOcpiCdrSessionClaims extends Command
{
    protected $signature = 'ocpi:index-cdr-claims {provider} {--after=0} {--limit=50}';

    protected $description = 'Index one page of verified local OCPI CDR session claims';

    public function handle(CdrSessionClaims $claims): int
    {
        $provider = filter_var($this->argument('provider'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $after = filter_var($this->option('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => CdrSessionClaims::BACKFILL_LIMIT]]);
        if ($provider === false || $after === false || $limit === false) {
            $this->error('Specify a valid provider, cursor and page limit (1–100).');

            return self::INVALID;
        }
        try {
            $report = $claims->backfill($provider, $after, $limit);
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));

            return $report['requires_review'] === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->error('Local CDR indexing unavailable.');

            return self::FAILURE;
        }
    }
}
