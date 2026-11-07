<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_notifications', function (Blueprint $t) {
            $t->foreignId('support_ticket_id')->nullable()->change();
            $t->json('metadata')->nullable();
        });
        Schema::create('vehicle_battery_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vehicle_id')->constrained();
            $t->uuid('operation_key');
            $t->unsignedTinyInteger('battery_percent');
            $t->foreignId('notification_id')->constrained('inbox_notifications');
            $t->timestamps();
            $t->unique(['vehicle_id', 'operation_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_battery_reports');
        // Preserve existing notifications; nullable support references remain valid.
        Schema::table('inbox_notifications', fn (Blueprint $t) => $t->dropColumn('metadata'));
    }
};
