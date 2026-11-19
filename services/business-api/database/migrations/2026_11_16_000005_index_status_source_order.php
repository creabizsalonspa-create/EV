<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charging_status_observations', function (Blueprint $table) {
            $table->index(
                ['source_generation', 'evse_number', 'connector_number', 'source_event_id'],
                'charging_observation_event_freshness'
            );
        });
    }

    public function down(): void
    {
        Schema::table('charging_status_observations', fn (Blueprint $table) => $table->dropIndex('charging_observation_event_freshness'));
    }
};
