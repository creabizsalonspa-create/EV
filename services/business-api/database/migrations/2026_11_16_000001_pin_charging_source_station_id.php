<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('csms_session_links', function (Blueprint $table) {
            // CitrineOS owns this id in its separate database. Never create a
            // cross-database FK or infer the original identity after migration.
            $table->unsignedBigInteger('source_station_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('csms_session_links', fn (Blueprint $table) => $table->dropColumn('source_station_id'));
    }
};
