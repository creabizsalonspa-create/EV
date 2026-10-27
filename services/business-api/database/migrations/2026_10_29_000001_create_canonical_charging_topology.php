<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('csms_bindings', function (Blueprint $t) {
            $t->dropUnique(['station_id']);
            $t->index('station_id');
            $t->foreignId('vendor_charger_id')->nullable()->unique()->constrained('vendor_chargers')->restrictOnDelete();
        });
        Schema::create('charging_evses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('charger_id')->constrained('vendor_chargers')->restrictOnDelete();
            $t->unsignedInteger('number');
            $t->string('mode');
            $t->foreignId('binding_id')->nullable()->constrained('csms_bindings')->restrictOnDelete();
            $t->unsignedInteger('source_evse_number')->default(0);
            $t->timestamps();
            $t->unique(['charger_id', 'number']);
        });
        DB::statement('CREATE UNIQUE INDEX charging_source_evse_unique ON charging_evses (binding_id, source_evse_number) WHERE binding_id IS NOT NULL AND source_evse_number > 0');
        Schema::create('charging_connector_mappings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('station_id')->constrained()->restrictOnDelete();
            $t->foreignId('evse_id')->constrained('charging_evses')->restrictOnDelete();
            $t->foreignId('connector_id')->unique()->constrained('vendor_connectors')->restrictOnDelete();
            $t->unsignedInteger('source_connector_number')->nullable();
            $t->string('source_target_key', 64)->nullable()->unique();
            $t->timestamps();
        });
        Schema::table('reservations', function (Blueprint $t) {
            $t->foreignId('connector_mapping_id')->nullable()->constrained('charging_connector_mappings')->restrictOnDelete();
            $t->foreignId('charging_evse_id')->nullable()->constrained('charging_evses')->restrictOnDelete();
        });
        DB::statement('DROP INDEX reservations_active_slot');
        DB::statement("CREATE UNIQUE INDEX reservations_active_legacy_slot ON reservations (station_id, starts_at) WHERE status = 'confirmed' AND connector_mapping_id IS NULL");
        DB::statement("CREATE UNIQUE INDEX reservations_active_evse_slot ON reservations (charging_evse_id, starts_at) WHERE status = 'confirmed' AND connector_mapping_id IS NOT NULL");
        Schema::table('charging_sessions', fn (Blueprint $t) => $t->foreignId('charging_evse_id')->nullable()->constrained('charging_evses')->restrictOnDelete());
        DB::statement("CREATE UNIQUE INDEX charging_active_evse ON charging_sessions (charging_evse_id) WHERE charging_evse_id IS NOT NULL AND status IN ('pending_start','charging','stopping')");
        Schema::table('csms_session_links', fn (Blueprint $t) => $t->foreignId('connector_mapping_id')->nullable()->constrained('charging_connector_mappings')->restrictOnDelete());
    }

    public function down(): void
    {
        // Parallel bookings cannot be collapsed to the old single-station key.
        if (DB::table('charging_connector_mappings')->exists() || DB::table('csms_bindings')->select('station_id')->groupBy('station_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Canonical topology has history; use a reviewed data-preserving rollback.');
        }
        Schema::table('csms_session_links', fn (Blueprint $t) => $t->dropConstrainedForeignId('connector_mapping_id'));
        DB::statement('DROP INDEX charging_active_evse');
        Schema::table('charging_sessions', fn (Blueprint $t) => $t->dropConstrainedForeignId('charging_evse_id'));
        DB::statement('DROP INDEX reservations_active_evse_slot');
        DB::statement('DROP INDEX reservations_active_legacy_slot');
        Schema::table('reservations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('connector_mapping_id');
            $t->dropConstrainedForeignId('charging_evse_id');
        });
        DB::statement("CREATE UNIQUE INDEX reservations_active_slot ON reservations (station_id, starts_at) WHERE status = 'confirmed'");
        Schema::dropIfExists('charging_connector_mappings');
        Schema::dropIfExists('charging_evses');
        Schema::table('csms_bindings', function (Blueprint $t) {
            $t->dropConstrainedForeignId('vendor_charger_id');
            $t->dropIndex(['station_id']);
            $t->unique('station_id');
        });
    }
};
