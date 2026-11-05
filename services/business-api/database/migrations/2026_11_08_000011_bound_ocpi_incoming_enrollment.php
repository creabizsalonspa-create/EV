<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocpi_credential_links', fn (Blueprint $t) => $t->timestamp('bootstrap_expires_at')->nullable());
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('ocpi_credential_links')->whereNotNull('bootstrap_expires_at')->exists()) {
            throw new RuntimeException('Preserve incoming enrollment expiry before an audited downgrade.');
        }
        Schema::table('ocpi_credential_links', fn (Blueprint $t) => $t->dropColumn('bootstrap_expires_at'));
    }
};
