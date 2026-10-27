<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('csms_session_links', function (Blueprint $table) {
            $table->unsignedBigInteger('source_meter_cursor')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('csms_session_links', fn (Blueprint $table) => $table->dropColumn('source_meter_cursor'));
    }
};
