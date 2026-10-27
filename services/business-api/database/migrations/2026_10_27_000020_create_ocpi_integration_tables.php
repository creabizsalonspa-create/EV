<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocpi_providers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->constrained('users');
            $t->string('name', 150);
            $t->string('connection_key', 80)->unique();
            $t->boolean('authorized')->default(false);
            $t->string('evidence')->default('UNVERIFIED');
            $t->string('version')->nullable();
            $t->json('endpoints')->nullable();
            $t->timestamp('negotiated_at')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();
        });
        Schema::create('ocpi_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ocpi_provider_id')->constrained();
            $t->string('module', 20);
            $t->string('country_code', 2);
            $t->string('party_id', 3);
            $t->string('external_id', 64);
            $t->text('payload');
            $t->json('normalized');
            $t->string('content_hash', 64);
            $t->timestamp('source_updated_at', 6);
            $t->timestamp('received_at');
            $t->timestamps();
            $t->unique(['ocpi_provider_id', 'module', 'country_code', 'party_id', 'external_id'], 'ocpi_record_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocpi_records');
        Schema::dropIfExists('ocpi_providers');
    }
};
