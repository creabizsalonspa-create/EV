<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        // Repair development installs which applied the pre-review lineage
        // checkpoint. A SQLite schema rebuild had expanded this partial unique
        // index to include stopped history. Rows and source receipts stay intact.
        DB::transaction(function () {
            DB::statement('DROP INDEX IF EXISTS charging_active_evse');
            DB::statement("CREATE UNIQUE INDEX charging_active_evse ON charging_sessions (charging_evse_id) WHERE charging_evse_id IS NOT NULL AND status IN ('pending_start','charging','stopping')");
        });
    }

    public function down(): void
    {
        // Preserve the original charging-capacity invariant on downgrade.
    }
};
