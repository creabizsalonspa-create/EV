<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_erasure_preparations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('privacy_request_id')->constrained('account_privacy_requests');
            $table->foreignId('review_revision')->constrained('account_privacy_request_reviews');
            $table->unsignedBigInteger('owner_auth_epoch');
            $table->foreignId('reviewer_id')->constrained('users');
            $table->text('reason_encrypted');
            $table->timestampTz('created_at', 6);
            $table->unique(['privacy_request_id', 'review_revision', 'owner_auth_epoch'], 'erasure_preparation_scope_unique');
        });
        Schema::create('privacy_erasure_preparation_approvals', function (Blueprint $table) {
            $table->foreignId('preparation_id')->primary()->constrained('privacy_erasure_preparations');
            $table->foreignId('reviewer_id')->constrained('users');
            $table->text('reason_encrypted');
            $table->timestampTz('created_at', 6);
        });
    }

    public function down(): void
    {
        if (DB::table('privacy_erasure_preparations')->exists()) {
            throw new RuntimeException('Preserve confidential erasure preparation decisions.');
        }
        Schema::dropIfExists('privacy_erasure_preparation_approvals');
        Schema::dropIfExists('privacy_erasure_preparations');
    }
};
