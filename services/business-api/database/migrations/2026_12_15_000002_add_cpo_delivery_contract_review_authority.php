<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocpi_cpo_publication_operations', function (Blueprint $table) {
            // Only the one-way original review fingerprint; no legal
            // document bytes, references, private tokens or endpoints.
            $table->char('contract_review_authority', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('ocpi_cpo_publication_operations')
            ->whereNotNull('contract_review_authority')->exists()) {
            throw new RuntimeException('Contract-bound CPO delivery receipts require a data-preserving rollback.');
        }
        Schema::table('ocpi_cpo_publication_operations', function (Blueprint $table) {
            $table->dropColumn('contract_review_authority');
        });
    }
};
