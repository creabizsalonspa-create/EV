<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_model_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('make', 80);
            $table->string('model', 100);
            $table->string('variant', 100)->nullable();
            $table->unsignedSmallInteger('model_year')->nullable();
            $table->string('connector_type', 20);
            $table->string('source_url', 500);
            $table->char('identity_hash', 64)->unique();
            $table->boolean('published')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('curator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['published', 'make', 'model']);
        });
        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignId('vehicle_model_id')->nullable()
                ->constrained('vehicle_model_catalog')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', fn (Blueprint $table) => $table->dropConstrainedForeignId('vehicle_model_id'));
        Schema::dropIfExists('vehicle_model_catalog');
    }
};
