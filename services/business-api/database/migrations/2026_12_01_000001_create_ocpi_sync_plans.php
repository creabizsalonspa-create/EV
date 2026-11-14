<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocpi_sync_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('provider_id')->constrained('ocpi_providers')->restrictOnDelete();
            $t->string('module', 16);
            $t->boolean('enabled')->default(false);
            $t->unsignedBigInteger('revision')->default(0);
            $t->unsignedInteger('interval_minutes')->default(60);
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('actor_role', 16)->nullable();
            $t->unsignedBigInteger('actor_epoch')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->unsignedBigInteger('provider_revision')->nullable();
            $t->char('configuration_hash', 64)->nullable();
            $t->uuid('active_run')->nullable();
            $t->timestamp('claimed_at')->nullable();
            $t->timestamp('next_run_at')->nullable();
            $t->string('stopped_reason', 48)->nullable();
            $t->timestamps();
            $t->unique(['provider_id', 'module']);
            $t->index(['enabled', 'next_run_at']);
        });
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement('CREATE UNIQUE INDEX ocpi_sync_provider_active ON ocpi_sync_plans (provider_id) WHERE active_run IS NOT NULL');
        }
        Schema::create('ocpi_sync_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('plan_id')->constrained('ocpi_sync_plans')->restrictOnDelete();
            $t->unsignedBigInteger('plan_revision');
            $t->string('state', 24);
            $t->string('result_code', 48)->nullable();
            $t->json('counts')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('completed_at')->nullable();
            $t->index(['plan_id', 'started_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('ocpi_sync_runs')->exists() || DB::table('ocpi_sync_plans')->where('enabled', true)->exists()) {
            throw new RuntimeException('Preserve scheduled synchronization consent and receipts.');
        }
        Schema::dropIfExists('ocpi_sync_runs');
        Schema::dropIfExists('ocpi_sync_plans');
    }
};
