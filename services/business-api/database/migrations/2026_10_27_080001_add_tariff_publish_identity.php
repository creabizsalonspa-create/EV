<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_tariff_versions', function (Blueprint $t) {
            $t->string('publishing_key', 64)->nullable()->unique();
            $t->string('publishing_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('finance_tariff_versions', function (Blueprint $t) {
            $t->dropUnique(['publishing_key']);
            $t->dropColumn(['publishing_key', 'publishing_fingerprint']);
        });
    }
};
