<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $t) {
            $t->boolean('battery_alerts_enabled')->default(true);
            $t->unsignedTinyInteger('battery_alert_threshold')->default(20);
            $t->unsignedInteger('battery_preferences_version')->default(1);
        });
        Schema::table('vehicle_battery_reports', function (Blueprint $t) {
            $t->foreignId('notification_id')->nullable()->change();
            $t->boolean('alerts_enabled')->default(true);
            $t->unsignedTinyInteger('threshold_percent')->default(20);
            $t->unsignedInteger('preferences_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_battery_reports', fn (Blueprint $t) => $t->dropColumn(['alerts_enabled', 'threshold_percent', 'preferences_version']));
        Schema::table('vehicles', fn (Blueprint $t) => $t->dropColumn(['battery_alerts_enabled', 'battery_alert_threshold', 'battery_preferences_version']));
        // Preserve nullable notification links for historical observations without alerts.
    }
};
