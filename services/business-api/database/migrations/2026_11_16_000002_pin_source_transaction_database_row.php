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
            // External CitrineOS transaction row key, intentionally not a
            // cross-database FK. A reused protocol transactionId alone is
            // insufficient to establish immutable source row provenance.
            $table->unsignedBigInteger('source_transaction_database_id')->nullable();
        });
    }

    public function down(): void
    {
        // Pinned source provenance is immutable. A rollback that drops this
        // column/index would erase the evidence required for safe replay.
        if (DB::table('csms_session_links')->whereNotNull('source_transaction_database_id')->exists()) {
            throw new RuntimeException('Preserve pinned source transaction identity before rollback.');
        }
        Schema::table('csms_session_links', fn (Blueprint $table) => $table->dropColumn('source_transaction_database_id'));
    }
};
