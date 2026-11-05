<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charger_firmware_operations', function (Blueprint $t) {
            $t->char('source_payload_digest', 64)->nullable();
            $t->text('source_payload_ciphertext')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('charger_firmware_operations')->whereNotNull('source_payload_digest')->exists()) {
            throw new RuntimeException('Preserve firmware source provenance before rollback.');
        }Schema::table('charger_firmware_operations', fn (Blueprint $t) => $t->dropColumn(['source_payload_digest', 'source_payload_ciphertext']));
    }
};
