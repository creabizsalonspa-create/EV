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
    }

    public function down(): void
    {
        if (DB::table('direct_source_authority_epochs')->exists()) {
            throw new RuntimeException('Direct source authority history must be retained.');
        }
        Schema::dropIfExists('direct_source_authority_state');
        Schema::dropIfExists('direct_source_authority_epochs');
    }
};
