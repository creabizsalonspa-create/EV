<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('csms_session_links', function (Blueprint $table) {
            // Tracks skipped voltage/diagnostic rows as well as energy samples.
            // Existing rows are intentionally null: no historical source
            // timestamp can be inferred safely from the signed event clock.
            $table->timestamp('source_meter_cursor_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        // Pinned source provenance is immutable. A rollback that drops this
        // column/index would erase the evidence required for safe replay.
        if (DB::table('csms_session_links')->whereNotNull('source_meter_cursor_at')->exists()) {
            throw new RuntimeException('Preserve source cursor chronology before rollback.');
        }
        Schema::table('csms_session_links', fn (Blueprint $table) => $table->dropColumn('source_meter_cursor_at'));
    }
};
