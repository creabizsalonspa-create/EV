<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charger_diagnostic_logs', function (Blueprint $t) {
            $t->string('consent_role', 20)->nullable();
            $t->unsignedBigInteger('consent_auth_epoch')->nullable();
            $t->char('consent_password_hash', 64)->nullable();
            $t->char('consent_mfa_hash', 64)->nullable();
            $t->unsignedBigInteger('consent_profile_id')->nullable();
            $t->unsignedInteger('consent_profile_version')->nullable();
            $t->char('consent_profile_hash', 64)->nullable();
            $t->unsignedBigInteger('consent_registration_id')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('charger_diagnostic_logs')->whereNotNull('consent_role')->exists()) {
            throw new RuntimeException('Retain diagnostic capability authority; populated downgrade would erase revocation evidence.');
        }
        Schema::table('charger_diagnostic_logs', fn (Blueprint $t) => $t->dropColumn(['consent_role', 'consent_auth_epoch', 'consent_password_hash', 'consent_mfa_hash', 'consent_profile_id', 'consent_profile_version', 'consent_profile_hash', 'consent_registration_id']));
    }
};
