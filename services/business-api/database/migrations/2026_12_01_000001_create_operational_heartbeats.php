<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_heartbeats', function (Blueprint $table) {
            $table->string('component', 32)->primary();
            $table->char('scope_hash', 64);
            $table->timestampTz('observed_at', 6);
        });
    }

    public function down(): void
    {
        // Disposable process telemetry, never financial or customer history.
        Schema::dropIfExists('operational_heartbeats');
    }
};
