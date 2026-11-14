<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charger_certificates', function (Blueprint $t) {
            $t->text('issuer_chain')->nullable();
            $t->char('trust_anchor_fingerprint', 64)->nullable();
            $t->char('chain_sha256', 64)->nullable();
            $t->unsignedTinyInteger('chain_length')->default(0);
        });
    }

    public function down(): void
    {
        if (DB::table('charger_certificates')->exists()) {
            throw new RuntimeException('Preserve immutable certificate trust history; use a forward migration.');
        }
        Schema::table('charger_certificates', fn (Blueprint $t) => $t->dropColumn(['issuer_chain', 'trust_anchor_fingerprint', 'chain_sha256', 'chain_length']));
    }
};
