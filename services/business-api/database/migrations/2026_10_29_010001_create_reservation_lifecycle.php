<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', fn (Blueprint $t) => $t->timestamp('checked_in_at')->nullable());
        Schema::create('reservation_lifecycle_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reservation_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('event_type', 40);
            $t->json('evidence');
            $t->timestamp('created_at');
            $t->unique(['reservation_id', 'event_type']);
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('reservation_lifecycle_events')->exists()) {
            throw new RuntimeException('Retain reservation evidence and use a reviewed rollback.');
        }
        Schema::dropIfExists('reservation_lifecycle_events');
        Schema::table('reservations', fn (Blueprint $t) => $t->dropColumn('checked_in_at'));
    }
};
