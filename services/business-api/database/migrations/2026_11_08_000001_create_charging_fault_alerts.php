<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_status_observations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('binding_id')->constrained('csms_bindings')->restrictOnDelete();
            $t->char('source_generation', 64);
            $t->unsignedBigInteger('source_event_id');
            $t->char('fingerprint', 64);
            $t->unsignedInteger('evse_number');
            $t->unsignedInteger('connector_number');
            $t->string('status', 40);
            $t->string('error_code', 80)->nullable();
            $t->timestamp('occurred_at', 6);
            $t->timestamp('created_at');
            $t->unique(['source_generation', 'source_event_id'], 'charging_observation_source_unique');
            $t->index(['source_generation', 'evse_number', 'connector_number'], 'charging_observation_component');
        });
        Schema::create('charging_fault_alerts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('observation_id')->unique()->constrained('charging_status_observations')->restrictOnDelete();
            $t->foreignId('recovery_observation_id')->nullable()->constrained('charging_status_observations')->restrictOnDelete();
            $t->foreignId('acknowledged_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('acknowledged_at')->nullable();
            $t->text('operator_note')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('charging_status_observations')->exists()) {
            throw new RuntimeException('Preserve source observations before rollback.');
        }
        Schema::dropIfExists('charging_fault_alerts');
        Schema::dropIfExists('charging_status_observations');
    }
};
