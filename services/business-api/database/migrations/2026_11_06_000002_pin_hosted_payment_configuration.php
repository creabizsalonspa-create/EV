<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hosted_payment_checkouts', fn (Blueprint $t) => $t->string('provider_configuration', 64)->nullable());
        // The mock provider has one deterministic non-secret configuration. Do
        // not invent provenance for an old Stripe row with unknown credentials.
        DB::table('hosted_payment_checkouts')->where('provider', 'mock')->update(['provider_configuration' => hash('sha256', 'creabiz-hosted:mock:v1')]);
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER hosted_configuration_immutable BEFORE UPDATE ON hosted_payment_checkouts WHEN NEW.provider_configuration IS NOT OLD.provider_configuration BEGIN SELECT RAISE(ABORT,'Preserve hosted provider configuration'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION hosted_configuration_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.provider_configuration IS DISTINCT FROM OLD.provider_configuration THEN RAISE EXCEPTION 'Preserve hosted provider configuration'; END IF; RETURN NEW; END \$\$");
            DB::unprepared('CREATE TRIGGER hosted_configuration_immutable BEFORE UPDATE ON hosted_payment_checkouts FOR EACH ROW EXECUTE FUNCTION hosted_configuration_guard()');
        }
    }

    public function down(): void
    {
        if (DB::table('hosted_payment_checkouts')->exists()) {
            throw new RuntimeException('Preserve hosted provider configuration identities before downgrade.');
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS hosted_configuration_immutable');
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS hosted_configuration_immutable ON hosted_payment_checkouts');
        }
        Schema::table('hosted_payment_checkouts', fn (Blueprint $t) => $t->dropColumn('provider_configuration'));
    }
};
