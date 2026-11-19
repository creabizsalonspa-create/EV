<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Read-only approval triage must avoid full-table scans of historical
        // requests before its bounded cross-source pagination.
        Schema::table('finance_refund_requests', function (Blueprint $table) {
            $table->index(['status', 'created_at', 'id'], 'fin_ref_req_status_created_idx');
        });
        Schema::table('commercial_settlements', function (Blueprint $table) {
            $table->index(['state', 'created_at', 'id'], 'comm_settle_state_created_idx');
        });
        Schema::table('commercial_adjustments', function (Blueprint $table) {
            $table->index(['created_at', 'id'], 'comm_adjust_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_adjustments', function (Blueprint $table) {
            $table->dropIndex('comm_adjust_created_idx');
        });
        Schema::table('commercial_settlements', function (Blueprint $table) {
            $table->dropIndex('comm_settle_state_created_idx');
        });
        Schema::table('finance_refund_requests', function (Blueprint $table) {
            $table->dropIndex('fin_ref_req_status_created_idx');
        });
    }
};
