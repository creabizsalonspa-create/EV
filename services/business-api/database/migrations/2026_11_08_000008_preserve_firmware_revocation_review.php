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
            $t->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('revocation_note')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('charger_firmware_releases')->whereNotNull('revocation_note')->exists()) {
            throw new RuntimeException('Preserve firmware revocation notes before rollback.');
        }Schema::table('charger_firmware_releases', function (Blueprint $t) {
            $t->dropForeign(['revoked_by']);
            $t->dropColumn(['revoked_by', 'revocation_note']);
        });
    }
};
