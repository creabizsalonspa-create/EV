<?php

use App\Services\Privacy\LegacyPrivacyIntakeImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive only: legacy intake/review data and encrypted notes survive.
        DB::transaction(function () {
            Schema::table('account_privacy_requests', function (Blueprint $table) {
                $table->string('idempotency_key', 100)->nullable();
                $table->timestamp('withdrawn_at')->nullable();
                $table->unsignedBigInteger('legacy_privacy_request_id')->nullable()->unique();
                $table->string('legacy_resolution', 24)->nullable();
                $table->unique(['user_id', 'idempotency_key'], 'privacy_actor_request_identity');
            });
            app(LegacyPrivacyIntakeImporter::class)->import();
        });
    }

    public function down(): void
    {
        $disposable = app()->environment('testing') && (
            (DB::getDriverName() === 'sqlite' && DB::connection()->getDatabaseName() === ':memory:') ||
            (DB::getDriverName() === 'pgsql' && str_ends_with(DB::connection()->getDatabaseName(), '_review')));
        if (! $disposable && DB::table('account_privacy_requests')->where(function ($query) {
            $query->whereNotNull('idempotency_key')->orWhereNotNull('legacy_privacy_request_id');
        })->exists()) {
            throw new RuntimeException('Privacy request identities require retention review before rollback.');
        }

        Schema::table('account_privacy_requests', function (Blueprint $table) {
            $table->dropUnique('privacy_actor_request_identity');
            $table->dropUnique(['legacy_privacy_request_id']);
            $table->dropColumn(['idempotency_key', 'withdrawn_at', 'legacy_privacy_request_id', 'legacy_resolution']);
        });
    }
};
