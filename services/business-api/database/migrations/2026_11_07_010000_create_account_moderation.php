<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_status', 16)->default('active')->index();
            $table->timestamp('suspended_at')->nullable();
        });

        Schema::create('admin_account_moderations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_user_id')->constrained('users');
            $table->foreignId('actor_user_id')->constrained('users');
            $table->string('from_status', 16);
            $table->string('to_status', 16);
            $table->text('reason_encrypted');
            $table->timestamp('created_at');
            $table->index(['target_user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_account_moderations');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['account_status', 'suspended_at']));
    }
};
