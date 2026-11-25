<?php

namespace App\Services\Discovery;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/** Filter partner-reported UTC clocks, without assigning availability authority. */
final class PartnerCatalogTime
{
    /** SQLite has no typed timestamp column. Normalize only valid stored UTC instants. */
    public static function canonical(mixed $value): ?string
    {
        if (! is_string($value)
            || preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T]([01]\d|2[0-3]):([0-5]\d):([0-5]\d)(?:\.(\d{1,6}))?Z?$/D', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return $parts[1].'-'.$parts[2].'-'.$parts[3].' '.$parts[4].':'.$parts[5].':'.$parts[6].'.'.str_pad($parts[7] ?? '', 6, '0');
    }

    public static function within(Builder $query, CarbonInterface $checkedAt, int $hours): void
    {
        if ($hours < 1 || $hours > 168) {
            throw new \InvalidArgumentException('Invalid bounded partner source age.');
        }
        $connection = $query->getModel()->getConnection();
        $bounds = [$checkedAt->copy()->utc()->subHours($hours)->format('Y-m-d H:i:s.u'),
            $checkedAt->copy()->utc()->format('Y-m-d H:i:s.u')];
        if ($connection->getDriverName() === 'pgsql') {
            // Preserve the native typed/indexed timestamp comparison.
            $query->whereBetween('ocpi_records.source_updated_at', $bounds);

            return;
        }
        if ($connection->getDriverName() !== 'sqlite') {
            throw new \LogicException('Unsupported partner source-age database.');
        }
        $pdo = $connection->getPdo();
        if (! method_exists($pdo, 'sqliteCreateFunction')
            || ! $pdo->sqliteCreateFunction('discovery_partner_source_clock', self::canonical(...), 1, \PDO::SQLITE_DETERMINISTIC)) {
            throw new \LogicException('Unable to register SQLite source clock validation.');
        }
        // A lexical prefilter on raw storage is not valid for historical
        // second-only or ISO-shaped instants. Canonicalize before pagination.
        $query->whereRaw('discovery_partner_source_clock(ocpi_records.source_updated_at) BETWEEN ? AND ?', $bounds);
    }
}
