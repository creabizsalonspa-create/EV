<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('phone_e164')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
        });
        Schema::create('account_phone_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 100);
            $table->char('phone_hash', 64);
            $table->text('phone_ciphertext')->nullable();
            $table->string('provider_sid', 34)->nullable();
            $table->unsignedBigInteger('auth_epoch');
            $table->string('status');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('account_phone_challenges')->exists() || DB::table('users')->whereNotNull('phone_e164')->exists()) {
            throw new RuntimeException('Export and explicitly remove phone-verification records before rollback.');
        }
        Schema::dropIfExists('account_phone_challenges');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['phone_e164', 'phone_verified_at']));
    }
};
