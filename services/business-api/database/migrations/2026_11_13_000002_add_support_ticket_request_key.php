<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('idempotency_key', 32)->nullable();
            $table->unique(['user_id', 'idempotency_key'], 'support_ticket_replay_uq');
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropUnique('support_ticket_replay_uq');
            $table->dropColumn('idempotency_key');
        });
    }
};
