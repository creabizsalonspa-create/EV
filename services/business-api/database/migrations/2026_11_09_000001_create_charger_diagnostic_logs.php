<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charger_diagnostic_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('binding_id')->constrained('csms_bindings')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('repair_order_id')->nullable()->constrained('charging_repair_orders')->restrictOnDelete();
            $t->uuid('request_key');
            $t->char('intent_hash', 64);
            $t->char('source_generation', 64);
            $t->string('protocol', 10);
            $t->text('reason_ciphertext');
            $t->text('payload_ciphertext')->nullable();
            $t->char('upload_token_digest', 64)->nullable()->unique();
            $t->string('dispatch_state', 32);
            $t->timestamp('attempted_at', 6)->nullable();
            $t->timestamp('expires_at', 6);
            $t->timestamp('cancelled_at')->nullable();
            $t->unsignedInteger('source_request_id')->nullable();
            $t->unsignedInteger('source_reply_id')->nullable();
            $t->char('source_reply_digest', 64)->nullable();
            $t->unsignedInteger('source_status_id')->nullable();
            $t->char('source_status_digest', 64)->nullable();
            $t->string('device_status', 32)->nullable();
            $t->timestamp('accepted_at', 6)->nullable();
            $t->timestamp('source_status_at', 6)->nullable();
            $t->timestamp('file_received_at', 6)->nullable();
            $t->timestamp('file_expires_at', 6)->nullable();
            $t->unsignedInteger('file_size')->nullable();
            $t->char('file_sha256', 64)->nullable();
            $t->longText('file_ciphertext')->nullable();
            $t->timestamp('content_removed_at')->nullable();
            $t->timestamps(6);
            $t->unique(['actor_id', 'request_key']);
            $t->index(['binding_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('charger_diagnostic_logs')->exists()) {
            throw new RuntimeException('Retain diagnostic request receipts with a forward migration.');
        }
        Schema::dropIfExists('charger_diagnostic_logs');
    }
};
