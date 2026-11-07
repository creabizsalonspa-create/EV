<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('commercial_payout_attempts')->select('provider_reference')->whereNotNull('provider_reference')->groupBy('provider_reference')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Reconcile duplicate payout source identities before enforcing uniqueness.');
        }
        Schema::table('commercial_payout_attempts', fn (Blueprint $t) => $t->unique('provider_reference', 'commercial_payout_attempts_provider_unique'));
    }

    public function down(): void
    {
        // Preflight every table before changing any DDL or uniqueness guard.
        if (! app()->environment('testing')) {
            foreach (['commercial_payout_webhooks', 'commercial_payout_events', 'commercial_payout_attempts', 'commercial_payouts', 'commercial_payout_accounts'] as $table) {
                if (DB::table($table)->exists()) {
                    throw new RuntimeException('Preserve payout history and its source identity constraint before downgrade.');
                }
            }
        }
        Schema::table('commercial_payout_attempts', fn (Blueprint $t) => $t->dropUnique('commercial_payout_attempts_provider_unique'));
    }
};
