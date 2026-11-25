<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only internal evidence review, not a contract signature or
        // acceptance by any partner, regulator or payment processor.
        Schema::create('ocpi_partner_contract_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('ocpi_providers')->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('connection_key', 80);
            $table->unsignedInteger('revision');
            $table->string('decision', 16);
            $table->string('reference_id', 120)->nullable();
            $table->char('evidence_sha256', 64)->nullable();
            $table->date('valid_until')->nullable();
            $table->string('idempotency_key', 100);
            $table->char('payload_hash', 64);
            $table->text('reason_ciphertext');
            $table->dateTime('created_at', 6);
            $table->unique(['provider_id', 'revision'], 'ocpi_contract_revision_unique');
            $table->unique(['provider_id', 'idempotency_key'], 'ocpi_contract_idempotency_unique');
        });
    }

    public function down(): void
    {
        // Preserve audit evidence in real environments. Isolated PostgreSQL
        // concurrency tests must be able to migrate:fresh their temporary
        // schemas even after a fixture has inserted internal review rows.
        if (! app()->environment('testing') && Schema::hasTable('ocpi_partner_contract_reviews')
            && DB::table('ocpi_partner_contract_reviews')->exists()) {
            throw new RuntimeException('OCPI contract-review records require a data-preserving rollback.');
        }
        Schema::dropIfExists('ocpi_partner_contract_reviews');
    }
};
