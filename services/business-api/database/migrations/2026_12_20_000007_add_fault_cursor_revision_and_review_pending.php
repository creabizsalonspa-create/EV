<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charging_fault_cursors', function (Blueprint $t) {
            $t->unsignedBigInteger('revision')->default(0);
            $t->boolean('review_pending')->default(false);
        });
        DB::table('charging_fault_cursors')->where('state', 'requires_review')->update(['review_pending' => true]);
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('charging_fault_cursors')->exists()) {
            throw new RuntimeException('Preserve durable cursor review state before an audited downgrade.');
        }
        Schema::table('charging_fault_cursors', function (Blueprint $t) {
            $t->dropColumn(['revision', 'review_pending']);
        });
    }
};
