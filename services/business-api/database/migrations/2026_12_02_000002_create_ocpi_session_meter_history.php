<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocpi_session_meter_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('record_id')->constrained('ocpi_records')->cascadeOnDelete();
            $t->foreignId('provider_owner_id')->constrained('users')->restrictOnDelete();
            $t->char('content_hash', 64);
            $t->char('lineage_hash', 64);
            $t->string('status', 16);
            $t->string('reported_energy_kwh', 32)->nullable();
            $t->unsignedBigInteger('energy_wh')->nullable();
            $t->boolean('meter_regressed')->default(false);
            $t->boolean('precision_requires_review')->default(false);
            $t->boolean('source_clock_ahead')->default(false);
            $t->timestamp('source_updated_at', 6);
            $t->timestamp('received_at', 6);
            $t->unique(['record_id', 'content_hash'], 'ocpi_meter_version_identity');
            $t->index(['record_id', 'lineage_hash', 'id'], 'ocpi_meter_lineage_history');
            $t->index(['record_id', 'lineage_hash', 'energy_wh'], 'ocpi_meter_lineage_highwater');
            $t->index(['record_id', 'lineage_hash', 'meter_regressed', 'precision_requires_review'], 'ocpi_meter_lineage_review');
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('ocpi_session_meter_history')->exists()) {
            throw new RuntimeException('Accepted meter evidence requires an explicit retention/rollback procedure.');
        }
        Schema::dropIfExists('ocpi_session_meter_history');
    }
};
