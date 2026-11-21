<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charger_source_registrations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('charger_id')->unique()->constrained('vendor_chargers')->restrictOnDelete();
            $t->foreignId('binding_id')->unique()->constrained('csms_bindings')->restrictOnDelete();
            $t->foreignId('profile_id')->unique()->constrained('charger_admission_profiles')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('idempotency_key', 100);
            $t->string('payload_hash', 64);
            $t->string('source_generation', 64);
            $t->string('state', 40)->default('prepared');
            $t->unsignedInteger('source_station_id')->nullable();
            $t->string('source_receipt_hash', 64)->nullable();
            $t->timestamp('attempted_at', 6)->nullable();
            $t->timestamp('verified_at', 6)->nullable();
            $t->timestamps(6);
            $t->unique(['actor_id', 'idempotency_key']);
            $t->unique(['source_generation', 'source_station_id'], 'charger_source_registration_identity');
        });
    }

    public function down(): void
    {
        if (DB::table('charger_source_registrations')->exists()) {
            throw new RuntimeException('Retain upstream registration provenance; populated downgrade requires an explicit private recovery review.');
        }
        Schema::dropIfExists('charger_source_registrations');
    }
};
