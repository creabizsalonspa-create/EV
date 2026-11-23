<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only aggregate, source-only observations: no locations, drivers,
        // remote credentials, raw payloads, tariffs or billing authority.
        Schema::create('ocpi_catalog_quality_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('ocpi_providers')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('observed_hour', 6);
            $table->dateTime('sampled_at', 6);
            $table->string('state', 24);
            $table->unsignedInteger('max_age_seconds')->nullable();
            $table->unsignedInteger('published_count')->nullable();
            $table->unsignedInteger('within_policy_count')->nullable();
            $table->unsignedInteger('stale_count')->nullable();
            $table->unsignedInteger('unknown_age_count')->nullable();
            $table->unsignedInteger('future_clock_count')->nullable();
            $table->unique(['provider_id', 'owner_id', 'observed_hour'], 'ocpi_quality_tenant_hour_unique');
        });
    }

    public function down(): void
    {
        // Preserve stored evidence outside the isolated test environment.
        // PostgreSQL concurrency tests migrate:fresh repeatedly; those
        // disposable test schemas must remain resettable without weakening
        // the data-preserving rollback guard in normal deployments.
        if (! app()->environment('testing') && Schema::hasTable('ocpi_catalog_quality_history')
            && DB::table('ocpi_catalog_quality_history')->exists()) {
            throw new RuntimeException('OCPI quality history requires a data-preserving rollback.');
        }

        Schema::dropIfExists('ocpi_catalog_quality_history');
    }
};
