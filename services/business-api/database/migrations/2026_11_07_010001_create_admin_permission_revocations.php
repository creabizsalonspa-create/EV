<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_permission_revocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained('vendor_company_staff');
            $table->foreignId('actor_user_id')->constrained('users');
            $table->text('reason_encrypted');
            $table->unsignedInteger('replaced_version');
            $table->timestamp('created_at');
            $table->index(['membership_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_permission_revocations');
    }
};
