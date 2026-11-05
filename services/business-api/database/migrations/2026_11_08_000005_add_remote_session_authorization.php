<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('csms_session_links', function (Blueprint $t) {
            $t->char('remote_token_digest', 64)->nullable()->unique();
            $t->uuid('remote_registration_nonce')->nullable()->unique();
            $t->unsignedBigInteger('remote_authorization_id')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('csms_session_links')->whereNotNull('remote_token_digest')->exists()) {
            throw new RuntimeException('Preserve remote authorization history before rollback.');
        }Schema::table('csms_session_links', fn (Blueprint $t) => $t->dropColumn(['remote_token_digest', 'remote_registration_nonce', 'remote_authorization_id']));
    }
};
