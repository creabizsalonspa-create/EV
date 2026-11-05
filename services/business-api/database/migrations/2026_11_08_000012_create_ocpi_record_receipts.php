<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocpi_record_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('provider_id')->constrained('ocpi_providers')->restrictOnDelete();
            $t->foreignId('record_id')->constrained('ocpi_records')->restrictOnDelete();
            $t->unsignedInteger('credential_generation');
            $t->string('method', 5);
            $t->string('content_hash', 64);
            $t->timestamp('created_at');
            $t->unique(['record_id', 'credential_generation', 'method', 'content_hash'], 'ocpi_record_receipt_identity');
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('ocpi_record_receipts')->exists()) {
            throw new RuntimeException('Authenticated source receipts require a forward migration.');
        }
        Schema::dropIfExists('ocpi_record_receipts');
    }
};
