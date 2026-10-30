<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_operational_case_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('case_id')->constrained('charging_operational_cases')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 40);
            $t->text('note');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('charging_operational_case_events')->exists()) {
            throw new RuntimeException('Retain recovery evidence.');
        }
        Schema::dropIfExists('charging_operational_case_events');
    }
};
