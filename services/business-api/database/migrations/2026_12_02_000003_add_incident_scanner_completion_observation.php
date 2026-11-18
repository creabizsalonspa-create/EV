<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operational_incident_scan_locks', function (Blueprint $table) {
            $table->timestampTz('last_completed_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('operational_incident_scan_locks', function (Blueprint $table) {
            $table->dropColumn('last_completed_at');
        });
    }
};
