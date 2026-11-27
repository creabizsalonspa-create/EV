<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regions', function (Blueprint $table) {
            $table->unsignedBigInteger('configuration_revision')->default(1);
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('regions')->where('configuration_revision', '>', 1)->exists()) {
            throw new RuntimeException('Preserve regional configuration revisions before rollback.');
        }
        Schema::table('regions', function (Blueprint $table) {
            $table->dropColumn('configuration_revision');
        });
    }
};
