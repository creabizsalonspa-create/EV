<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // Native ADD preserves the active-slot partial indexes. Rebuilding
            // this table through Blueprint loses SQLite index WHERE clauses.
            DB::statement('ALTER TABLE reservations ADD COLUMN vehicle_id INTEGER REFERENCES vehicles(id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE reservations ADD COLUMN vehicle_connector_type VARCHAR(24)');

            return;
        }
        Schema::table('reservations', function (Blueprint $t) {
            $t->foreignId('vehicle_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('vehicle_connector_type', 24)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('reservations')->whereNotNull('vehicle_id')->exists()) {
            throw new RuntimeException('Retain vehicle-linked booking history; roll back application code without removing these columns.');
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE reservations DROP COLUMN vehicle_connector_type');
            DB::statement('ALTER TABLE reservations DROP COLUMN vehicle_id');

            return;
        }
        Schema::table('reservations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('vehicle_id');
            $t->dropColumn('vehicle_connector_type');
        });
    }
};
