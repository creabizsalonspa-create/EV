<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An Admin emergency revoke is a durable hold; a company owner must
        // not be able to re-invite the same staff member until Admin releases it.
        Schema::table('vendor_company_staff', function (Blueprint $table) {
            $table->timestamp('admin_revoked_at')->nullable();
        });

        Schema::create('admin_permission_revocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained('vendor_company_staff');
            $table->foreignId('actor_user_id')->constrained('users');
            $table->text('reason_encrypted');
            $table->unsignedInteger('replaced_version');
            $table->string('action', 20)->default('revoked');
            $table->timestamp('created_at');
            $table->index(['membership_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_permission_revocations');
        Schema::table('vendor_company_staff', fn (Blueprint $table) => $table->dropColumn('admin_revoked_at'));
    }
};
