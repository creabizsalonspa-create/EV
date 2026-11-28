<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocpi_records', function (Blueprint $table) {
            $table->string('cdr_session_claim_key', 64)->nullable();
            $table->string('cdr_session_claim_source_hash', 64)->nullable();
        });
        DB::statement("CREATE INDEX ocpi_cdr_session_claim_lookup ON ocpi_records (ocpi_provider_id, country_code, party_id, cdr_session_claim_key)
            WHERE module = 'cdrs'");
        DB::statement("CREATE INDEX ocpi_cdr_session_claim_pending ON ocpi_records (ocpi_provider_id, country_code, party_id)
            WHERE module = 'cdrs' AND (cdr_session_claim_key IS NULL OR cdr_session_claim_source_hash IS NULL OR cdr_session_claim_source_hash <> content_hash)");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX ocpi_cdr_session_claim_pending');
        DB::statement('DROP INDEX ocpi_cdr_session_claim_lookup');
        Schema::table('ocpi_records', function (Blueprint $table) {
            $table->dropColumn(['cdr_session_claim_key', 'cdr_session_claim_source_hash']);
        });
    }
};
