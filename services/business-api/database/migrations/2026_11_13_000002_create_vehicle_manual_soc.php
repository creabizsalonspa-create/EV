<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_manual_soc', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->primary()->constrained('vehicles')->cascadeOnDelete();
            $table->unsignedTinyInteger('battery_percent');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('reported_at');
            $table->timestamps();
        });

        Schema::create('vehicle_manual_soc_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->uuid('operation_key');
            $table->unsignedTinyInteger('battery_percent');
            $table->unsignedInteger('revision');
            $table->timestamp('reported_at');
            $table->timestamps();
            $table->unique(['vehicle_id', 'operation_key']);
            $table->unique(['vehicle_id', 'revision']);
            $table->index(['vehicle_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_manual_soc_reports');
        Schema::dropIfExists('vehicle_manual_soc');
    }
};
