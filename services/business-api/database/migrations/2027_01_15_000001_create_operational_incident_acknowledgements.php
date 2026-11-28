<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_incident_acknowledgements', function (Blueprint $table) {
            $table->string('condition_key', 40);
            $table->unsignedInteger('transition_revision');
            $table->timestampTz('acknowledged_at', 6);
            $table->primary(['condition_key', 'transition_revision'], 'operational_incident_review_pk');
        });
    }

    public function down(): void
    {
        if (DB::table('operational_incident_acknowledgements')->exists()) {
            throw new RuntimeException('Preserve recorded operational incident reviews.');
        }
        Schema::dropIfExists('operational_incident_acknowledgements');
    }
};
