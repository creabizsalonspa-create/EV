<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reservation_id')->unique()->constrained();
            $t->string('status')->default('charging');
            $t->unsignedBigInteger('energy_wh')->default(0);
            $t->unsignedInteger('rate_minor_per_kwh');
            $t->string('currency', 3);
            $t->unsignedBigInteger('total_minor')->nullable();
            $t->timestamp('stopped_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charging_sessions');
    }
};
