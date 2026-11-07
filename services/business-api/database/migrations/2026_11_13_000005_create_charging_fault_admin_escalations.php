<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_fault_admin_escalations', function (Blueprint $table) {
            $table->foreignId('alert_id')->primary()
                ->constrained('charging_fault_alerts')->restrictOnDelete();
            $table->foreignId('admin_user_id')
                ->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') &&
            DB::table('charging_fault_admin_escalations')->exists()) {
            throw new RuntimeException('Preserve in-app escalation evidence before rollback.');
        }
        Schema::dropIfExists('charging_fault_admin_escalations');
    }
};
