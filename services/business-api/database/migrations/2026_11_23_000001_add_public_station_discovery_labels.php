<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('station_information', function (Blueprint $table) {
            // Explicit publication only: never copy private account/KYC/site records.
            $table->string('operator_name', 100)->nullable();
            $table->string('address_en', 300)->nullable();
            $table->string('address_ar', 300)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('station_information')->whereNotNull('operator_name')->orWhereNotNull('address_en')->orWhereNotNull('address_ar')->exists()) {
            throw new RuntimeException('Export and explicitly remove public discovery labels before rollback.');
        }
        Schema::table('station_information', fn (Blueprint $table) => $table->dropColumn(['operator_name', 'address_en', 'address_ar']));
    }
};
