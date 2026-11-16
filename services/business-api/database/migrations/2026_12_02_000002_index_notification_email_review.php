<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_email_outbox', function (Blueprint $table) {
            $table->index(['state', 'claimed_at'], 'notification_email_claim_review');
        });
    }

    public function down(): void
    {
        Schema::table('notification_email_outbox', function (Blueprint $table) {
            $table->dropIndex('notification_email_claim_review');
        });
    }
};
