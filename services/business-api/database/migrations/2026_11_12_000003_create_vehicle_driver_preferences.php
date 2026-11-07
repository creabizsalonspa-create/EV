<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deliberately separate from battery/SOC preferences and charging authority.
        Schema::create('vehicle_driver_preferences', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->primary()->constrained('vehicles')->cascadeOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedSmallInteger('preferred_power_kw')->nullable();
            $table->string('charging_goal', 24)->default('balanced');
            $table->boolean('accessible_site_required')->default(false);
            $table->boolean('avoid_paid_parking')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_driver_preferences');
    }
};
