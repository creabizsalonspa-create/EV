<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charger_configuration_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('binding_id')->constrained('csms_bindings')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('idempotency_key', 100);
            $t->string('payload_hash', 64);
            $t->string('source_identifier', 100);
            $t->string('protocol', 10);
            $t->string('status', 32);
            $t->json('request_payload')->nullable();
            $t->text('reason');
            $t->text('snapshot_ciphertext')->nullable();
            $t->string('source_payload_hash', 64)->nullable();
            $t->unsignedBigInteger('source_request_id')->nullable();
            $t->unsignedBigInteger('source_reply_id')->nullable();
            $t->timestamp('attempted_at', 6)->nullable();
            $t->timestamp('observed_at', 6)->nullable();
            $t->timestamps(6);
            $t->unique(['actor_id', 'idempotency_key']);
            $t->index(['binding_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('charger_configuration_reads')->exists()) {
            throw new RuntimeException('Source read receipts require a forward migration.');
        }
        Schema::dropIfExists('charger_configuration_reads');
    }
};
