<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_connector_review_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charger_id')->constrained('vendor_chargers')->restrictOnDelete();
            $table->foreignId('connector_id')->constrained('vendor_connectors')->restrictOnDelete();
            $table->foreignId('registration_id')->constrained('charger_source_registrations')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('source_evse_number');
            $table->unsignedInteger('source_connector_number');
            $table->unsignedBigInteger('source_connector_database_id');
            $table->unsignedBigInteger('source_event_id');
            $table->unsignedBigInteger('authority_epoch');
            $table->char('registration_fingerprint', 64);
            $table->dateTime('source_observed_at', 6);
            $table->dateTime('reviewed_at', 6);
            $table->unique(['connector_id', 'authority_epoch'], 'direct_connector_review_epoch_unique');
            $table->unique(['charger_id', 'source_evse_number', 'source_connector_number', 'authority_epoch'], 'direct_connector_review_target_unique');
            $table->unique(['registration_id', 'source_connector_database_id', 'authority_epoch'], 'direct_connector_review_source_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('direct_connector_review_receipts')->exists()) {
            throw new RuntimeException('Direct connector review evidence must be retained.');
        }
        Schema::dropIfExists('direct_connector_review_receipts');
    }
};
