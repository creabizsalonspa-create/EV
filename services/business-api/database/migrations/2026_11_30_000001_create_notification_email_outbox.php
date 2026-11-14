<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_email_preferences', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();
            $t->boolean('enabled')->default(false);
            $t->unsignedBigInteger('revision')->default(0);
            $t->unsignedBigInteger('consent_epoch')->nullable();
            $t->string('consent_role', 16)->nullable();
            $t->char('recipient_hash', 64)->nullable();
            $t->char('configuration_hash', 64)->nullable();
            $t->unsignedBigInteger('after_notification_id')->default(0);
            $t->timestamp('consented_at')->nullable();
            $t->timestamp('last_attempted_at')->nullable();
            $t->string('disabled_reason', 32)->nullable();
            $t->timestamps();
        });
        Schema::create('notification_email_outbox', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('after_notification_id');
            $t->unsignedBigInteger('through_notification_id');
            $t->unsignedBigInteger('consent_revision');
            $t->char('recipient_hash', 64);
            $t->char('configuration_hash', 64);
            $t->string('state', 24)->default('queued');
            $t->string('failure_code', 48)->nullable();
            $t->string('evidence', 32);
            $t->timestamp('claimed_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'through_notification_id', 'consent_revision'], 'notification_email_once');
            $t->index(['state', 'created_at']);
            $t->index(['user_id', 'created_at']);
        });
        if (in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            DB::statement("CREATE UNIQUE INDEX notification_email_pending_owner ON notification_email_outbox (user_id) WHERE state = 'queued'");
        }
    }

    public function down(): void
    {
        if (DB::table('notification_email_outbox')->exists() || DB::table('notification_email_preferences')->where('enabled', true)->exists()) {
            throw new RuntimeException('Preserve email consent and one-shot handoff receipts.');
        }
        Schema::dropIfExists('notification_email_outbox');
        Schema::dropIfExists('notification_email_preferences');
    }
};
