<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charging_fault_admin_escalations', function (Blueprint $table) {
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_source', 32)->nullable();
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') &&
            DB::table('charging_fault_admin_escalations')->exists()) {
            throw new RuntimeException('Preserve source/acknowledgment closure history.');
        }
        Schema::table('charging_fault_admin_escalations', function (Blueprint $table) {
            $table->dropColumn(['resolved_at', 'resolution_source']);
        });
    }
};
