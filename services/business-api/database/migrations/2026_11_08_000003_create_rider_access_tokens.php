<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('label', 80);
            $t->string('token_type', 20);
            $t->text('token_ciphertext');
            $t->char('token_digest', 64)->unique();
            $t->string('suffix', 4);
            $t->string('idempotency_key', 100);
            $t->char('payload_hash', 64);
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->unsignedBigInteger('source_authorization_id')->nullable();
            $t->uuid('source_registration_nonce')->unique();
            $t->timestamp('provisioned_at')->nullable();
            $t->string('provisioning_state', 30)->default('unregistered');
            $t->uuid('provision_lease')->nullable();
            $t->timestamp('provision_lease_until')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'idempotency_key'], 'rider_token_request_unique');
        });
        Schema::create('rider_charging_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('token_id')->constrained('rider_access_tokens')->restrictOnDelete();
            $t->foreignId('company_id')->constrained('vendor_companies')->restrictOnDelete();
            $t->foreignId('station_id')->constrained()->restrictOnDelete();
            $t->string('state', 20)->default('requested');
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('review_note')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['token_id', 'station_id'], 'rider_station_grant_unique');
        });
        Schema::create('rider_authorization_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('token_id')->nullable()->constrained('rider_access_tokens')->restrictOnDelete();
            $t->foreignId('grant_id')->nullable()->constrained('rider_charging_grants')->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('action', 60);
            $t->text('note')->nullable();
            $t->timestamp('created_at');
        });
        Schema::table('csms_session_links', function (Blueprint $t) {
            $t->foreignId('start_command_id')->nullable()->change();
            $t->foreignId('rider_token_id')->nullable()->constrained('rider_access_tokens')->restrictOnDelete();
            $t->foreignId('rider_grant_id')->nullable()->constrained('rider_charging_grants')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX rider_active_source_session ON csms_session_links (rider_token_id) WHERE rider_token_id IS NOT NULL AND ended_at IS NULL AND closed_without_dispatch_at IS NULL');
    }

    public function down(): void
    {
        if (DB::table('csms_session_links')->whereNull('start_command_id')->exists()) {
            throw new RuntimeException('Preserve source-initiated rider sessions before rollback.');
        }
        if (! app()->environment('testing') && DB::table('rider_access_tokens')->exists()) {
            throw new RuntimeException('Preserve rider authorization history before rollback.');
        }
        DB::statement('DROP INDEX rider_active_source_session');
        Schema::table('csms_session_links', function (Blueprint $t) {
            $t->dropForeign(['rider_token_id']);
            $t->dropForeign(['rider_grant_id']);
            $t->dropColumn(['rider_token_id', 'rider_grant_id']);
            $t->foreignId('start_command_id')->nullable(false)->change();
        });
        Schema::dropIfExists('rider_authorization_events');
        Schema::dropIfExists('rider_charging_grants');
        Schema::dropIfExists('rider_access_tokens');
    }
};
