<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_privacy_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('privacy_request_id')->unique()->constrained('account_privacy_requests');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('prepared_by')->constrained('users');
            $table->string('request_key', 100);
            $table->longText('payload_encrypted')->nullable();
            $table->string('sha256', 64);
            $table->unsignedInteger('byte_length');
            $table->timestamp('expires_at')->index();
            $table->timestamp('purged_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('account_privacy_exports')->exists()) {
            throw new RuntimeException('Preserve privacy fulfillment receipts before rollback; use the bounded expiry purge for payloads.');
        }
        Schema::dropIfExists('account_privacy_exports');
    }
};
