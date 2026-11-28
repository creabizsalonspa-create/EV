<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_recovery_intents', function (Blueprint $table) {
            $table->timestamp('last_local_scan_at', 6)->nullable();
            $table->timestamp('last_provider_scan_at', 6)->nullable();
            $table->index(['status', 'last_provider_scan_at', 'id'], 'payment_recovery_provider_scan');
        });
    }

    public function down(): void
    {
        Schema::table('payment_recovery_intents', function (Blueprint $table) {
            $table->dropIndex('payment_recovery_provider_scan');
            $table->dropColumn(['last_local_scan_at', 'last_provider_scan_at']);
        });
    }
};
