<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocpi_credential_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('provider_id')->unique()->constrained('ocpi_providers')->restrictOnDelete();
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('generation');
            $t->string('state', 24);
            $t->string('configuration_hash', 64);
            $t->string('bootstrap_hash', 64);
            $t->string('local_token_digest', 64)->nullable()->unique();
            $t->text('local_token_ciphertext')->nullable();
            $t->text('remote_token_ciphertext')->nullable();
            $t->timestamp('exchanged_at')->nullable();
            $t->timestamps();
        });
        Schema::create('ocpi_credential_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('provider_id')->constrained('ocpi_providers')->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('generation');
            $t->string('action', 16);
            $t->string('idempotency_key', 100);
            $t->string('payload_hash', 64);
            $t->text('reason');
            $t->text('source_payload_ciphertext')->nullable();
            $t->string('source_payload_hash', 64)->nullable();
            $t->string('state', 24);
            $t->timestamp('attempted_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['provider_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && (DB::table('ocpi_credential_operations')->exists() || DB::table('ocpi_credential_links')->exists())) {
            throw new RuntimeException('Preserve OCPI credential exchange evidence before an audited downgrade.');
        }
        Schema::dropIfExists('ocpi_credential_operations');
        Schema::dropIfExists('ocpi_credential_links');
    }
};
