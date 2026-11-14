<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_availability_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('station_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason_en', 300);
            $table->string('reason_ar', 300);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['station_id', 'idempotency_key']);
            $table->index(['station_id', 'cancelled_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('station_availability_blocks')->exists()) {
            throw new RuntimeException('Preserve availability block and booking history before rollback.');
        }
        Schema::dropIfExists('station_availability_blocks');
    }
};
