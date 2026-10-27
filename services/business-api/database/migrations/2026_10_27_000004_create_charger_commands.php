<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csms_bindings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('station_id')->unique()->constrained();
            $t->string('identifier')->unique();
            $t->string('protocol');
            $t->timestamps();
        });
        Schema::create('charger_commands', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->constrained('users');
            $t->foreignId('station_id')->constrained();
            $t->string('idempotency_key', 100);
            $t->string('payload_hash', 64);
            $t->string('action');
            $t->json('payload');
            $t->string('status')->default('pending');
            $t->json('confirmation')->nullable();
            $t->string('failure_code')->nullable();
            $t->timestamps();
            $t->unique(['actor_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charger_commands');
        Schema::dropIfExists('csms_bindings');
    }
};
