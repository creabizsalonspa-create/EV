<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_privacy_request_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('privacy_request_id')->constrained('account_privacy_requests');
            $table->foreignId('reviewer_id')->constrained('users');
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->text('note_encrypted');
            $table->timestamp('created_at');
            $table->index(['privacy_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_privacy_request_reviews');
    }
};
