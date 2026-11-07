<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CLI role provisioning has no authenticated HTTP actor. Preserve
        // truthful provenance rather than pretending the target user was the
        // operator who made the privilege change.
        Schema::create('operator_role_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_user_id')->constrained('users');
            $table->string('previous_role', 16);
            $table->string('assigned_role', 16);
            $table->string('source', 32);
            $table->string('ticket_reference', 100)->nullable();
            $table->timestamp('created_at');
            $table->index(['target_user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_role_changes');
    }
};
