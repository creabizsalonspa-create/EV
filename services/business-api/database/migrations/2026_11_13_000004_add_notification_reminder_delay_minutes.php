<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            // The original preference payload remains valid. Missing values
            // preserve the 15-minute default for existing accounts/clients.
            $table->unsignedSmallInteger('fault_reminder_delay_minutes')->default(15);
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn('fault_reminder_delay_minutes');
        });
    }
};
