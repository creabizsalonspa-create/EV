<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reward_id')->constrained('loyalty_rewards');
            $table->foreignId('created_by')->constrained('users');
            $table->boolean('listed')->default(false);
            $table->char('code_hash', 64)->unique();
            $table->char('publication_key', 64)->unique();
            $table->char('fingerprint', 64);
            $table->unsignedInteger('claim_limit');
            $table->unsignedInteger('per_user_limit');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('loyalty_coupon_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('loyalty_coupons');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('redemption_id')->unique()->constrained('loyalty_redemptions');
            $table->char('request_key', 64)->unique();
            $table->timestamp('created_at');
            $table->index(['coupon_id', 'user_id']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        if (DB::table('loyalty_coupons')->exists()) {
            throw new RuntimeException('Preserve coupon commitments before rollback.');
        }
        Schema::dropIfExists('loyalty_coupon_claims');
        Schema::dropIfExists('loyalty_coupons');
    }
};
