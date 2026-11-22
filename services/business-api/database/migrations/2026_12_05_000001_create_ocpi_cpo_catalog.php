<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocpi_cpo_catalog', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('ocpi_providers')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('module', 16);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('generation');
            $table->unsignedBigInteger('authorization_revision');
            $table->string('configuration_hash', 64);
            $table->string('source_hash', 64);
            $table->text('payload');
            $table->boolean('withdrawn');
            $table->string('published_at', 27);
            $table->unique(['provider_id', 'module', 'source_id']);
            $table->index(['provider_id', 'module', 'published_at', 'id'], 'cpo_catalog_window');
        });
        Schema::create('ocpi_cpo_publication_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id')->constrained('ocpi_cpo_catalog')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_key');
            $table->string('published_at', 27);
            $table->string('state', 20);
            $table->timestamps();
            $table->unique(['publication_id', 'published_at']);
            $table->unique('operation_key');
        });
    }

    public function down(): void
    {
        if (DB::table('ocpi_cpo_catalog')->exists()) {
            throw new RuntimeException('CPO publications have partner-visible history; use a data-preserving rollback.');
        }
        Schema::dropIfExists('ocpi_cpo_publication_operations');
        Schema::dropIfExists('ocpi_cpo_catalog');
    }
};
