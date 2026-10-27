<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $t) {
            $t->string('country', 2)->primary();
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            $t->string('timezone');
            $t->unsignedInteger('tax_bps')->nullable();
            $t->boolean('commerce_enabled')->default(false);
            $t->timestamps();
        });
        DB::table('regions')->insert(['country' => 'AE', 'currency' => 'AED', 'minor_units' => 2, 'timezone' => 'Asia/Dubai', 'tax_bps' => null, 'commerce_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('fleets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->constrained('users');
            $t->string('name');
            $t->string('country', 2);
            $t->timestamps();
        });
        Schema::create('fleet_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fleet_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('role')->default('driver');
            $t->string('status')->default('invited');
            $t->unique(['fleet_id', 'user_id']);
            $t->timestamps();
        });
        Schema::create('cost_centers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fleet_id')->constrained();
            $t->string('code');
            $t->string('name');
            $t->unique(['fleet_id', 'code']);
            $t->timestamps();
        });
        Schema::create('fleet_vehicles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fleet_id')->constrained();
            $t->foreignId('vehicle_id')->unique()->constrained();
            $t->foreignId('cost_center_id')->constrained();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['fleet_vehicles', 'cost_centers', 'fleet_members', 'fleets', 'regions'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
