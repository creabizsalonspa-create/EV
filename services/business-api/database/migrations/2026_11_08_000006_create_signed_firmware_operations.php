<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charger_firmware_releases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('vendor_companies')->restrictOnDelete();
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->string('catalog_key', 80);
            $t->string('version', 80);
            $t->string('manufacturer', 100);
            $t->string('model', 100);
            $t->char('sha256', 64);
            $t->unsignedInteger('size');
            $t->char('signer_fingerprint', 64);
            $t->text('signing_certificate');
            $t->text('signature');
            $t->text('approval_note');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'catalog_key']);
        });
        Schema::create('charger_firmware_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('binding_id')->constrained('csms_bindings')->restrictOnDelete();
            $t->foreignId('release_id')->constrained('charger_firmware_releases')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('idempotency_key', 100);
            $t->char('payload_hash', 64);
            $t->text('reason');
            $t->string('status', 24)->default('requested');
            $t->char('download_token_digest', 64)->unique();
            $t->text('download_token_ciphertext');
            $t->timestamp('download_expires_at');
            $t->timestamp('attempted_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('review_note')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['actor_id', 'idempotency_key']);
            $t->index(['binding_id', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('charger_firmware_releases')->exists() || DB::table('charger_firmware_operations')->exists()) {
            throw new RuntimeException('Preserve signed firmware history before rollback.');
        }Schema::dropIfExists('charger_firmware_operations');
        Schema::dropIfExists('charger_firmware_releases');
    }
};
