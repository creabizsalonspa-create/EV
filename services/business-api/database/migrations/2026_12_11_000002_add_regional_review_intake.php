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
            $table->boolean('review_intake_enabled')->default(true);
            $table->unsignedInteger('review_intake_revision')->default(0);
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('regions')->where('review_intake_revision', '>', 0)->exists()) {
            throw new RuntimeException('Preserve configured regional review-intake gates before rollback.');
        }
        Schema::table('regions', function (Blueprint $table) {
            $table->dropColumn(['review_intake_enabled', 'review_intake_revision']);
        });
    }
};
