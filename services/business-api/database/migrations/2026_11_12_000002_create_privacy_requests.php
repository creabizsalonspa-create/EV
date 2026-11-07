<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // Retained review record must not introduce an account-erasure blocker.
            $t->string('request_type', 32);
            $t->longText('reason')->nullable(); // Eloquent encrypted at rest.
            $t->string('idempotency_key', 100);
            $t->string('status', 24)->default('submitted');
            $t->string('resolution', 24)->nullable();
            $t->longText('decision_note')->nullable(); // Eloquent encrypted at rest.
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('withdrawn_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'idempotency_key']);
            $t->index(['status', 'id']);
            $t->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        // Review records may be subject to statutory retention and cannot be silently destroyed.
        if (Schema::hasTable('privacy_requests') && DB::table('privacy_requests')->exists()) {
            throw new RuntimeException('Export and obtain retention approval before removing privacy requests.');
        }
        Schema::dropIfExists('privacy_requests');
    }
};
