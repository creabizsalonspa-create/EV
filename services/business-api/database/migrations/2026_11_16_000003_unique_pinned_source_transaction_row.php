<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('csms_session_links', function (Blueprint $table) {
            $table->unique(['binding_id', 'source_transaction_database_id'], 'csms_link_binding_source_tx_unique');
        });
    }

    public function down(): void
    {
        Schema::table('csms_session_links', fn (Blueprint $table) => $table->dropUnique('csms_link_binding_source_tx_unique'));
    }
};
