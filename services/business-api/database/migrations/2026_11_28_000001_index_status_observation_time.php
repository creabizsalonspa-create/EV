<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charging_status_observations', function (Blueprint $table) {
            $table->index(['source_generation', 'occurred_at'], 'charging_observation_generation_time');
        });
    }

    public function down(): void
    {
        Schema::table('charging_status_observations', function (Blueprint $table) {
            $table->dropIndex('charging_observation_generation_time');
        });
    }
};
