<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_favorites', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('station_id')->constrained()->cascadeOnDelete();
            $t->timestamp('created_at');
            $t->primary(['user_id', 'station_id']);
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('station_favorites')->exists()) {
            throw new RuntimeException('Preserve customer station preferences before downgrade.');
        }
        Schema::dropIfExists('station_favorites');
    }
};
