<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_location_favorites', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('record_id')->constrained('ocpi_records')->cascadeOnDelete();
            $t->timestamp('created_at');
            $t->primary(['user_id', 'record_id']);
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('partner_location_favorites')->exists()) {
            throw new RuntimeException('Preserve private partner location preferences before downgrade.');
        }
        Schema::dropIfExists('partner_location_favorites');
    }
};
