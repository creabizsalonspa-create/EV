<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosted_payment_checkouts', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->string('scope', 20);
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('subscription_id')->nullable();
            $t->unsignedBigInteger('canonical_checkout_id');
            $t->foreignId('user_id')->constrained('users');
            $t->string('checkout_reference');
            $t->string('provider', 20);
            $t->string('status', 24);
            $t->unsignedBigInteger('amount_minor');
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->string('provider_session')->nullable()->unique();
            $t->string('provider_intent')->nullable()->unique();
            $t->text('hosted_url')->nullable();
            $t->text('return_url');
            $t->string('lease', 64)->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('retry_until');
            $t->timestamp('created_at');
            $t->timestamp('updated_at');
            $t->index(['scope', 'invoice_id']);
            $t->unique(['scope', 'canonical_checkout_id']);
        });
        DB::unprepared("CREATE UNIQUE INDEX hosted_active_billable ON hosted_payment_checkouts(scope,invoice_id) WHERE status NOT IN ('captured','cancelled','expired')");
        Schema::create('hosted_payment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hosted_checkout_id')->constrained('hosted_payment_checkouts');
            $t->string('kind', 50);
            $t->timestamp('created_at');
        });
        $terms = ['reference', 'scope', 'invoice_id', 'subscription_id', 'canonical_checkout_id', 'checkout_reference', 'user_id', 'provider', 'amount_minor', 'currency', 'minor_units', 'request_key', 'fingerprint', 'return_url', 'created_at', 'expires_at', 'retry_until'];
        if (DB::getDriverName() === 'sqlite') {
            $changed = implode(' OR ', array_map(fn ($c) => "NEW.$c IS NOT OLD.$c", $terms));
            $changed .= ' OR (OLD.provider_session IS NOT NULL AND NEW.provider_session IS NOT OLD.provider_session) OR (OLD.provider_intent IS NOT NULL AND NEW.provider_intent IS NOT OLD.provider_intent)';
            DB::unprepared("CREATE TRIGGER hosted_payment_terms BEFORE UPDATE ON hosted_payment_checkouts WHEN $changed BEGIN SELECT RAISE(ABORT,'Hosted payment terms are immutable'); END");
            DB::unprepared("CREATE TRIGGER hosted_payment_no_delete BEFORE DELETE ON hosted_payment_checkouts BEGIN SELECT RAISE(ABORT,'Preserve hosted payment identity'); END");
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared('CREATE TRIGGER hosted_events_no_'.strtolower($event)." BEFORE $event ON hosted_payment_events BEGIN SELECT RAISE(ABORT,'Hosted audit is immutable'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            $a = implode(',', array_map(fn ($c) => "NEW.$c", $terms));
            $b = implode(',', array_map(fn ($c) => "OLD.$c", $terms));
            DB::unprepared("CREATE OR REPLACE FUNCTION hosted_payment_terms_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' OR ROW($a) IS DISTINCT FROM ROW($b) OR (OLD.provider_session IS NOT NULL AND NEW.provider_session IS DISTINCT FROM OLD.provider_session) OR (OLD.provider_intent IS NOT NULL AND NEW.provider_intent IS DISTINCT FROM OLD.provider_intent) THEN RAISE EXCEPTION 'Preserve hosted payment identity'; END IF; RETURN NEW; END \$\$");
            DB::unprepared('CREATE TRIGGER hosted_payment_terms BEFORE UPDATE OR DELETE ON hosted_payment_checkouts FOR EACH ROW EXECUTE FUNCTION hosted_payment_terms_guard()');
            DB::unprepared('CREATE TRIGGER hosted_events_immutable BEFORE UPDATE OR DELETE ON hosted_payment_events FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::table('hosted_payment_checkouts')->exists()) {
            throw new RuntimeException('Preserve hosted payment identities before downgrade.');
        }
        Schema::dropIfExists('hosted_payment_events');
        Schema::dropIfExists('hosted_payment_checkouts');
    }
};
