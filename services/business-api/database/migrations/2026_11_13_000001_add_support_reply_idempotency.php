<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            // Nullable for already deployed clients. PostgreSQL and SQLite
            // allow multiple legacy NULLs while preventing replay duplicates.
            $table->string('idempotency_key', 32)->nullable();
            $table->unique(
                ['support_ticket_id', 'author_id', 'idempotency_key'],
                'ticket_message_replay_uq'
            );
        });
    }

    public function down(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->dropUnique('ticket_message_replay_uq');
            $table->dropColumn('idempotency_key');
        });
    }
};
