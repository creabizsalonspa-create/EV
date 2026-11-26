<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Never infer a previous counterparty from today's configuration.
        // Legacy reviews retain their history but need explicit re-review.
        Schema::table('ocpi_partner_contract_reviews', function (Blueprint $table) {
            $table->char('counterparty_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing')
            && DB::table('ocpi_partner_contract_reviews')->whereNotNull('counterparty_hash')->exists()) {
            throw new RuntimeException('OCPI counterparty evidence requires a data-preserving rollback.');
        }
        Schema::table('ocpi_partner_contract_reviews', function (Blueprint $table) {
            $table->dropColumn('counterparty_hash');
        });
    }
};
