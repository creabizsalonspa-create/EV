<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charger_commands', fn (Blueprint $table) => $table->json('staff_stop_authority')->nullable());
    }

    public function down(): void
    {
        if (DB::table('charger_commands')->whereNotNull('staff_stop_authority')->exists()) {
            throw new RuntimeException('Retain the authority evidence of delegated charger commands.');
        }
        Schema::table('charger_commands', fn (Blueprint $table) => $table->dropColumn('staff_stop_authority'));
    }
};
