<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('customer');
            $t->string('locale')->default('en');
        });
        Schema::create('stations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_id')->constrained('users');
            $t->string('name');
            $t->string('country', 2);
            $t->string('currency', 3);
            $t->string('timezone');
            $t->decimal('latitude', 10, 7);
            $t->decimal('longitude', 10, 7);
            $t->string('source')->default('discovery');
            $t->string('connector_type');
            $t->unsignedInteger('power_kw');
            $t->timestamps();
        });
        Schema::create('reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('station_id')->constrained();
            $t->dateTime('starts_at');
            $t->string('status')->default('confirmed');
            $t->string('idempotency_key', 100);
            $t->timestamps();
            $t->unique(['user_id', 'idempotency_key']);
        });
        DB::statement("CREATE UNIQUE INDEX reservations_active_slot ON reservations (station_id, starts_at) WHERE status = 'confirmed'");
        Schema::create('vehicles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('name');
            $t->string('connector_type');
            $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('action');
            $t->string('subject');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'vehicles', 'reservations', 'stations'] as $name) {
            Schema::dropIfExists($name);
        } Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'locale']));
    }
};
