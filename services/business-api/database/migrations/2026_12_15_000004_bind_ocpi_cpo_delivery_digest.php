<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Do not invent historic wire evidence by hashing present-day bytes.
        Schema::table('ocpi_cpo_catalog', function (Blueprint $table) {
            $table->char('payload_digest', 64)->nullable();
        });
        Schema::table('ocpi_cpo_publication_operations', function (Blueprint $table) {
            $table->char('publication_digest', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && (DB::table('ocpi_cpo_catalog')->whereNotNull('payload_digest')->exists()
            || DB::table('ocpi_cpo_publication_operations')->whereNotNull('publication_digest')->exists())) {
            throw new RuntimeException('Preserve immutable publication and delivery evidence before rollback.');
        }
        Schema::table('ocpi_cpo_publication_operations', function (Blueprint $table) {
            $table->dropColumn('publication_digest');
        });
        Schema::table('ocpi_cpo_catalog', function (Blueprint $table) {
            $table->dropColumn('payload_digest');
        });
    }
};
