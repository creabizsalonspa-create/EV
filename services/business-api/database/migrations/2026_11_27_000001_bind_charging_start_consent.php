<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing unattempted intents have no verifiable credential epoch.
        // Keep them null and blocked, rather than silently granting new consent.
        foreach (['charger_commands', 'ocpi_roaming_orders'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->unsignedBigInteger('consent_auth_epoch')->nullable());
        }
    }

    public function down(): void
    {
        if (! app()->environment('testing') && (DB::table('charger_commands')->whereNotNull('consent_auth_epoch')->exists() || DB::table('ocpi_roaming_orders')->whereNotNull('consent_auth_epoch')->exists())) {
            throw new RuntimeException('Preserve charging consent history before downgrading this schema.');
        }
        foreach (['charger_commands', 'ocpi_roaming_orders'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('consent_auth_epoch'));
        }
    }
};
