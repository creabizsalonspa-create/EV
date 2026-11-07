<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_fault_cursors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('binding_id')->constrained('csms_bindings')->restrictOnDelete();
            $t->string('source_generation', 64)->unique();
            $t->unsignedInteger('source_station_id')->nullable();
            $t->unsignedInteger('last_event_id')->default(0);
            $t->string('state', 24)->default('not_observed');
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('charging_fault_cursors')->exists()) {
            throw new RuntimeException('Preserve durable monitoring cursors before an audited downgrade.');
        }
        Schema::dropIfExists('charging_fault_cursors');
    }
};
