<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocpi_providers', function (Blueprint $table) {
            $table->unsignedBigInteger('authorization_revision')->default(0);
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('ocpi_providers')->where('authorization_revision', '>', 0)->exists()) {
            throw new RuntimeException('Preserve OCPI authorization generations and revoke operator access before an audited downgrade.');
        }
        Schema::table('ocpi_providers', fn (Blueprint $table) => $table->dropColumn('authorization_revision'));
    }
};
