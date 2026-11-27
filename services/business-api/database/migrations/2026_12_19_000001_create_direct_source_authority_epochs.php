<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_source_authority_epochs', function (Blueprint $table) {
            $table->unsignedBigInteger('epoch')->primary();
            $table->char('authority_fingerprint', 64);
            $table->string('ticket_reference', 100);
            $table->dateTime('provisioned_at', 6);
        });
        Schema::create('direct_source_authority_state', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('epoch')->nullable();
            $table->foreign('epoch')->references('epoch')->on('direct_source_authority_epochs')->restrictOnDelete();
        });
        DB::table('direct_source_authority_state')->insert(['id' => 1, 'epoch' => null]);
        Schema::create('direct_source_registration_epochs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained('charger_source_registrations')->restrictOnDelete();
            $table->unsignedBigInteger('epoch');
            $table->foreign('epoch')->references('epoch')->on('direct_source_authority_epochs')->restrictOnDelete();
            $table->char('registration_fingerprint', 64);
            $table->string('ticket_reference', 100);
            $table->dateTime('reviewed_at', 6);
            $table->unique(['registration_id', 'epoch'], 'direct_source_registration_epoch_unique');
        });
        Schema::table('direct_connector_review_receipts', function (Blueprint $table) {
            $table->foreign(['registration_id', 'authority_epoch'], 'direct_review_registration_epoch_fk')
                ->references(['registration_id', 'epoch'])->on('direct_source_registration_epochs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('direct_source_authority_epochs')->exists()) {
            throw new RuntimeException('Direct source authority history must be retained.');
        }
        Schema::table('direct_connector_review_receipts', function (Blueprint $table) {
            $table->dropForeign('direct_review_registration_epoch_fk');
        });
        Schema::dropIfExists('direct_source_registration_epochs');
        Schema::dropIfExists('direct_source_authority_state');
        Schema::dropIfExists('direct_source_authority_epochs');
    }
};
