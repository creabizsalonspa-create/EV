<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocpi_roaming_orders', function (Blueprint $t) {
            $t->string('intent_kind', 16)->default('start');
            $t->uuid('reservation_id')->nullable()->unique();
            $t->char('reservation_target_key', 64)->nullable()->index();
            $t->timestamp('reservation_expires_at', 6)->nullable();
            $t->timestamp('reservation_closed_at', 6)->nullable();
            $t->char('reservation_cancel_hash', 64)->nullable();
            $t->timestamp('reservation_cancelled_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('ocpi_roaming_orders')->whereNotNull('reservation_id')->exists()) {
            throw new RuntimeException('Preserve reservation authority and source evidence.');
        }
        Schema::table('ocpi_roaming_orders', function (Blueprint $t) {
            $t->dropUnique(['reservation_id']);
            $t->dropIndex(['reservation_target_key']);
            $t->dropColumn(['intent_kind', 'reservation_id', 'reservation_target_key', 'reservation_expires_at', 'reservation_closed_at', 'reservation_cancel_hash', 'reservation_cancelled_at']);
        });
    }
};
