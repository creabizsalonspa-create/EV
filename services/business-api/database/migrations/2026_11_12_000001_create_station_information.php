<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_information', function (Blueprint $t) {
            $t->foreignId('station_id')->primary()->constrained('stations')->cascadeOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->string('description_en', 500)->nullable();
            $t->string('description_ar', 500)->nullable();
            $t->string('access_en', 500)->nullable();
            $t->string('access_ar', 500)->nullable();
            // Explicitly expiring vendor-reported notices; never authoritative status.
            $t->string('notice_en', 300)->nullable();
            $t->string('notice_ar', 300)->nullable();
            $t->date('notice_until')->nullable();
            $t->json('weekly_hours');
            $t->json('closed_dates');
            $t->json('amenities');
            $t->json('accessibility');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // A rollback must not silently destroy vendor-published station information.
        if (Schema::hasTable('station_information') && DB::table('station_information')->exists()) {
            throw new RuntimeException('Export and explicitly remove station information before rollback.');
        }
        Schema::dropIfExists('station_information');
    }
};
