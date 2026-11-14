<?php

namespace Tests\Support;

/** Fixed classifications and public source locations only; never emit log text. */
final class LocalDemoBackendFailure
{
    public static function classify(string $tail): array
    {
        $tail = substr($tail, -65536);
        $entries = preg_split('/(?=^\[[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9:]{8}\] [a-z]+\.(?:ERROR|CRITICAL|ALERT|EMERGENCY):)/m', $tail);
        $last = end($entries);
        $kind = match (true) {
            str_contains($last, 'database is locked'), str_contains($last, 'database table is locked') => 'sqlite_locked',
            str_contains($last, 'SQLSTATE[40P01]') => 'database_deadlock',
            str_contains($last, 'UNIQUE constraint failed'), str_contains($last, 'SQLSTATE[23505]') => 'unique_constraint',
            str_contains($last, 'FOREIGN KEY constraint failed'), str_contains($last, 'SQLSTATE[23503]') => 'foreign_key_constraint',
            str_contains($last, 'Call to undefined') => 'undefined_call',
            str_contains($last, 'TypeError') => 'type_error',
            str_contains($last, 'SQLSTATE[') => 'database_error',
            default => 'unavailable',
        };
        $file = null;
        $line = null;
        if (preg_match('~'.preg_quote(app_path().'/', '~').'([A-Za-z0-9_/]{1,180}\.php)\(([0-9]{1,5})\)~', $last, $m)
            && is_file(app_path($m[1])) && (int) $m[2] > 0 && (int) $m[2] < 10000) {
            $file = $m[1];
            $line = (int) $m[2];
        }

        return ['exception_kind' => $kind, 'application_file' => $file, 'application_line' => $line, 'correlation' => 'RECENT_UNATTRIBUTED_LOCAL_ERROR'];
    }
}
