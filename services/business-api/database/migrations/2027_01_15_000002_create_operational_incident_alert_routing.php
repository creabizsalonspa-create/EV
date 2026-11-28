<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_incident_alert_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->boolean('enabled');
            $table->unsignedBigInteger('consent_epoch');
            $table->timestampTz('updated_at', 6);
        });
        Schema::create('operational_incident_alert_receipts', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('condition_key', 40);
            $table->unsignedInteger('transition_revision');
            $table->timestampTz('created_at', 6);
            $table->primary(['user_id', 'condition_key', 'transition_revision'], 'incident_alert_receipt_pk');
        });
    }

    public function down(): void
    {
        if (DB::table('operational_incident_alert_receipts')->exists()
            || DB::table('operational_incident_alert_preferences')->where('enabled', true)->exists()) {
            throw new RuntimeException('Preserve incident alert consent and deduplication receipts.');
        }
        Schema::dropIfExists('operational_incident_alert_receipts');
        Schema::dropIfExists('operational_incident_alert_preferences');
    }
};
