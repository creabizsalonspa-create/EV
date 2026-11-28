<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regional_rollout_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('country', 2);
            $table->foreign('country')->references('country')->on('regions');
            $table->unsignedBigInteger('configuration_revision');
            $table->string('domain', 24);
            $table->string('decision', 12);
            $table->string('evidence_reference', 100);
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('recorded_at', 6);
            $table->index(['country', 'configuration_revision', 'domain', 'id'], 'regional_rollout_review_lookup');
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('regional_rollout_reviews')->exists()) {
            throw new RuntimeException('Preserve regional rollout review evidence before rollback.');
        }
        Schema::dropIfExists('regional_rollout_reviews');
    }
};
