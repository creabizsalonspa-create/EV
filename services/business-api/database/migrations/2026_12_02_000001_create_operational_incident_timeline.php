<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_incident_scan_locks', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('operational_incident_scan_locks')->insert(['id' => 1]);

        Schema::create('operational_incident_states', function (Blueprint $table) {
            $table->string('condition_key', 40)->primary();
            $table->string('state', 12);
            $table->unsignedInteger('transitions');
            $table->timestampTz('opened_at', 6);
            $table->timestampTz('resolved_at', 6)->nullable();
            $table->timestampTz('updated_at', 6);
        });

        Schema::create('operational_incident_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('condition_key', 40)->index();
            $table->string('transition', 12);
            $table->timestampTz('observed_at', 6);
        });
    }

    public function down(): void
    {
        // Only synthetic/process-derived operational observations live here.
        // No source, payment, reservation, queue or audit records are touched.
        Schema::dropIfExists('operational_incident_events');
        Schema::dropIfExists('operational_incident_states');
        Schema::dropIfExists('operational_incident_scan_locks');
    }
};
