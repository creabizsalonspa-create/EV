<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocpi_roaming_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('provider_id')->constrained('ocpi_providers')->restrictOnDelete();
            $t->foreignId('location_record_id')->constrained('ocpi_records')->restrictOnDelete();
            $t->foreignId('tariff_record_id')->constrained('ocpi_records')->restrictOnDelete();
            $t->uuid('request_key');
            $t->char('intent_hash', 64);
            $t->char('offer_hash', 64);
            $t->string('contract_reference', 80);
            $t->foreignId('provider_owner_id')->constrained('users')->restrictOnDelete();
            $t->char('counterparty_hash', 64);
            $t->unsignedBigInteger('source_record_floor')->default(0);
            $t->unsignedBigInteger('provider_revision');
            $t->unsignedInteger('credential_generation');
            $t->string('authorization_reference', 36)->unique();
            $t->text('target_ciphertext');
            $t->text('token_ciphertext');
            $t->char('token_digest', 64)->unique();
            $t->string('token_state', 32);
            $t->string('state', 32);
            $t->char('active_target_key', 64)->nullable()->unique();
            $t->timestamp('consented_at', 6);
            $t->timestamp('expires_at', 6);
            $t->timestamp('authorized_at', 6)->nullable();
            $t->string('authorization_request_key', 100)->nullable();
            $t->char('authorization_request_hash', 64)->nullable();
            $t->timestamp('token_revoked_at')->nullable();
            $t->foreignId('source_session_record_id')->nullable()->unique()->constrained('ocpi_records')->restrictOnDelete();
            $t->foreignId('source_cdr_record_id')->nullable()->unique()->constrained('ocpi_records')->restrictOnDelete();
            $t->char('source_session_hash', 64)->nullable();
            $t->char('source_cdr_hash', 64)->nullable();
            $t->unsignedBigInteger('energy_wh')->default(0);
            $t->string('source_status', 24)->nullable();
            $t->string('reconciliation_state', 32)->default('awaiting_source');
            $t->timestamp('source_observed_at', 6)->nullable();
            $t->timestamps(6);
            $t->unique(['user_id', 'request_key']);
            $t->index(['provider_id', 'created_at']);
        });
        Schema::create('ocpi_roaming_commands', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('ocpi_roaming_orders')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key');
            $t->char('intent_hash', 64);
            $t->string('action', 24);
            $t->uuid('callback_key')->unique();
            $t->text('payload_ciphertext');
            $t->string('state', 32);
            $t->string('transport_result', 24)->nullable();
            $t->timestamp('attempted_at', 6)->nullable();
            $t->string('callback_result', 32)->nullable();
            $t->char('callback_hash', 64)->nullable();
            $t->timestamp('callback_at', 6)->nullable();
            $t->timestamps(6);
            $t->unique(['actor_id', 'request_key']);
            $t->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('ocpi_roaming_orders')->exists()) {
            throw new RuntimeException('Preserve roaming authority and command receipts with a forward migration.');
        }
        Schema::dropIfExists('ocpi_roaming_commands');
        Schema::dropIfExists('ocpi_roaming_orders');
    }
};
