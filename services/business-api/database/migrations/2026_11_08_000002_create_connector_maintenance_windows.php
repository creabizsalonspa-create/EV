<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_maintenance_windows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connector_id')->constrained('vendor_connectors')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('idempotency_key', 100);
            $t->char('payload_hash', 64);
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->text('reason');
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->unique(['connector_id', 'idempotency_key'], 'maintenance_request_unique');
            $t->index(['connector_id', 'starts_at', 'ends_at'], 'maintenance_connector_period');
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('connector_maintenance_windows')->exists()) {
            throw new RuntimeException('Preserve maintenance history before rollback.');
        }
        Schema::dropIfExists('connector_maintenance_windows');
    }
};
