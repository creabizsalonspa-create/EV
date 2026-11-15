<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charger_firmware_releases', function (Blueprint $t) {
            $t->json('compatibility_protocols')->nullable();
            $t->json('compatibility_from_versions')->nullable();
            $t->char('compatibility_manifest_sha256', 64)->nullable();
            $t->text('compatibility_signature')->nullable();
        });
        Schema::table('charger_firmware_operations', function (Blueprint $t) {
            $t->timestamp('pre_dispatch_blocked_at')->nullable();
            $t->string('pre_dispatch_blocked_code', 48)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('charger_firmware_releases')->whereNotNull('compatibility_manifest_sha256')->exists() || DB::table('charger_firmware_operations')->whereNotNull('pre_dispatch_blocked_at')->exists()) {
            throw new RuntimeException('Preserve approved signed compatibility evidence.');
        }
        Schema::table('charger_firmware_operations', fn (Blueprint $t) => $t->dropColumn(['pre_dispatch_blocked_at', 'pre_dispatch_blocked_code']));
        Schema::table('charger_firmware_releases', fn (Blueprint $t) => $t->dropColumn(['compatibility_protocols', 'compatibility_from_versions', 'compatibility_manifest_sha256', 'compatibility_signature']));
    }
};
