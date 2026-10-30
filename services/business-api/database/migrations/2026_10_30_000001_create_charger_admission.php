<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charger_admission_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('charger_id')->unique()->constrained('vendor_chargers')->restrictOnDelete();
            $t->string('identity', 100)->unique();
            $t->string('protocol', 8);
            $t->string('credential_digest', 64)->nullable();
            $t->unsignedInteger('credential_version')->default(0);
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->boolean('enabled')->default(false);
            $t->string('certificate_sha256', 64)->nullable();
            $t->timestamps();
        });
        Schema::create('charger_admission_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('profile_id')->constrained('charger_admission_profiles')->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action', 40);
            $t->unsignedInteger('credential_version');
            $t->timestamp('created_at');
        });
        Schema::create('charging_operational_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('command_id')->unique()->constrained('charger_commands')->restrictOnDelete();
            $t->string('state', 30)->default('open');
            $t->text('operator_note');
            $t->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && (DB::table('charger_admission_profiles')->exists() || DB::table('charger_admission_events')->exists() || DB::table('charging_operational_cases')->exists())) {
            throw new RuntimeException('Retain charger credential/recovery evidence; coordinate rollback.');
        }
        Schema::dropIfExists('charging_operational_cases');
        Schema::dropIfExists('charger_admission_events');
        Schema::dropIfExists('charger_admission_profiles');
    }
};
