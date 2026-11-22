<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charger_availability_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('binding_id')->constrained('csms_bindings')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('request_hash', 64);
            $table->string('source_generation', 64);
            $table->unsignedBigInteger('connector_id')->nullable();
            $table->string('requested_state', 16);
            $table->json('source_payload');
            $table->text('reason');
            $table->string('status', 32);
            $table->unsignedInteger('source_station_id')->nullable();
            $table->unsignedInteger('source_watermark')->nullable();
            $table->dateTime('attempted_at', 6)->nullable();
            $table->unsignedInteger('source_request_id')->nullable();
            $table->unsignedInteger('source_reply_id')->nullable();
            $table->dateTime('observed_at', 6)->nullable();
            $table->timestamps();
            $table->unique(['actor_id', 'idempotency_key']);
            $table->index(['binding_id', 'id']);
        });
    }

    public function down(): void
    {
        if (DB::table('charger_availability_operations')->exists()) {
            throw new RuntimeException('Availability command history requires a data-preserving rollback.');
        }

        Schema::dropIfExists('charger_availability_operations');
    }
};
