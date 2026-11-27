<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['charger_reset_operations', 'charger_firmware_operations'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->char('source_generation', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['charger_reset_operations', 'charger_firmware_operations'] as $table) {
            if (DB::table($table)->whereNotNull('source_generation')->exists()) {
                throw new RuntimeException('Preserve maintenance source provenance before rollback.');
            }
        }
        foreach (['charger_reset_operations', 'charger_firmware_operations'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('source_generation'));
        }
    }
};
