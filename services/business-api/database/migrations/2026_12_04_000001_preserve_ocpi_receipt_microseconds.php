<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // timestamp(0) rounds .5+ seconds into a future instant. The
            // canonical model already writes UTC with six fractional digits.
            // Existing stored instants remain unchanged; lost historical
            // fractions cannot be reconstructed or invented by this migration.
            DB::statement('ALTER TABLE ocpi_records ALTER COLUMN received_at TYPE timestamp(6) without time zone');
        }
        // SQLite stores these timestamps as text and already retains .u.
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $disposable = app()->environment('testing')
            && str_ends_with(DB::connection()->getDatabaseName(), '_review');
        if (! $disposable && DB::table('ocpi_records')
            ->whereRaw("received_at <> date_trunc('second', received_at)")->exists()) {
            throw new RuntimeException('Preserve OCPI receipt instants before reducing timestamp precision.');
        }
        DB::statement('ALTER TABLE ocpi_records ALTER COLUMN received_at TYPE timestamp(0) without time zone');
    }
};
