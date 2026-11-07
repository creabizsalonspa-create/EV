<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_hosted_checkouts', function (Blueprint $t) {
            $t->id();
            $t->uuid('reference')->unique();
            $t->foreignId('statement_id')->constrained('corporate_statements');
            $t->foreignId('payer_id')->constrained('users');
            $t->string('provider', 20);
            $t->string('provider_configuration', 64);
            $t->string('line_fingerprint', 64);
            $t->unsignedBigInteger('amount_minor');
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->string('status', 24);
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
        });
        DB::unprepared("CREATE UNIQUE INDEX corporate_hosted_active ON corporate_hosted_checkouts(statement_id) WHERE status NOT IN ('captured','cancelled','expired')");
        Schema::create('corporate_hosted_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('checkout_id')->constrained('corporate_hosted_checkouts');
            $t->foreignId('invoice_id')->constrained('finance_invoices');
            $t->foreignId('statement_line_id')->constrained('corporate_statement_lines');
            $t->unsignedBigInteger('amount_minor');
            $t->timestamp('created_at');
            $t->unique(['checkout_id', 'invoice_id']);
        });
        Schema::create('corporate_hosted_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('checkout_id')->constrained('corporate_hosted_checkouts');
            $t->string('kind', 20);
            $t->string('status', 24);
            $t->string('lease', 64)->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->timestamp('retry_until');
            $t->timestamp('created_at');
            $t->timestamp('updated_at');
            $t->unique(['checkout_id', 'kind']);
        });
        Schema::create('corporate_hosted_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('checkout_id')->constrained('corporate_hosted_checkouts');
            $t->string('kind', 50);
            $t->string('provider_event_id', 64)->nullable()->unique();
            $t->string('fingerprint', 64)->nullable();
            $t->timestamp('created_at');
        });
        $terms = ['reference', 'statement_id', 'payer_id', 'provider', 'provider_configuration', 'line_fingerprint', 'amount_minor', 'currency', 'minor_units', 'request_key', 'fingerprint', 'return_url', 'expires_at', 'retry_until', 'created_at'];
        if (DB::getDriverName() === 'sqlite') {
            $changed = implode(' OR ', array_map(fn ($c) => "NEW.$c IS NOT OLD.$c", $terms));
            $changed .= ' OR (OLD.provider_session IS NOT NULL AND NEW.provider_session IS NOT OLD.provider_session) OR (OLD.provider_intent IS NOT NULL AND NEW.provider_intent IS NOT OLD.provider_intent)';
            DB::unprepared("CREATE TRIGGER corporate_hosted_terms BEFORE UPDATE ON corporate_hosted_checkouts WHEN $changed BEGIN SELECT RAISE(ABORT,'Corporate hosted terms are immutable'); END");
            DB::unprepared("CREATE TRIGGER corporate_hosted_no_delete BEFORE DELETE ON corporate_hosted_checkouts BEGIN SELECT RAISE(ABORT,'Preserve corporate hosted identity'); END");
            $opChanged = implode(' OR ', array_map(fn ($c) => "NEW.$c IS NOT OLD.$c", ['checkout_id', 'kind', 'retry_until', 'created_at']));
            DB::unprepared("CREATE TRIGGER corporate_hosted_operation_terms BEFORE UPDATE ON corporate_hosted_operations WHEN $opChanged BEGIN SELECT RAISE(ABORT,'Corporate operation identity is immutable'); END");
            DB::unprepared("CREATE TRIGGER corporate_hosted_operation_no_delete BEFORE DELETE ON corporate_hosted_operations BEGIN SELECT RAISE(ABORT,'Preserve corporate operation identity'); END");
            foreach (['corporate_hosted_lines', 'corporate_hosted_events'] as $table) {
                foreach (['UPDATE', 'DELETE'] as $event) {
                    DB::unprepared('CREATE TRIGGER '.$table.'_no_'.strtolower($event)." BEFORE $event ON $table BEGIN SELECT RAISE(ABORT,'Corporate collection evidence is immutable'); END");
                }
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            $a = implode(',', array_map(fn ($c) => "NEW.$c", $terms));
            $b = implode(',', array_map(fn ($c) => "OLD.$c", $terms));
            DB::unprepared("CREATE OR REPLACE FUNCTION corporate_hosted_terms_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' OR ROW($a) IS DISTINCT FROM ROW($b) OR (OLD.provider_session IS NOT NULL AND NEW.provider_session IS DISTINCT FROM OLD.provider_session) OR (OLD.provider_intent IS NOT NULL AND NEW.provider_intent IS DISTINCT FROM OLD.provider_intent) THEN RAISE EXCEPTION 'Preserve corporate hosted identity'; END IF; RETURN NEW; END \$\$");
            DB::unprepared('CREATE TRIGGER corporate_hosted_terms BEFORE UPDATE OR DELETE ON corporate_hosted_checkouts FOR EACH ROW EXECUTE FUNCTION corporate_hosted_terms_guard()');
            DB::unprepared("CREATE OR REPLACE FUNCTION corporate_hosted_operation_guard() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' OR ROW(NEW.checkout_id,NEW.kind,NEW.retry_until,NEW.created_at) IS DISTINCT FROM ROW(OLD.checkout_id,OLD.kind,OLD.retry_until,OLD.created_at) THEN RAISE EXCEPTION 'Preserve corporate operation identity'; END IF; RETURN NEW; END \$\$");
            DB::unprepared('CREATE TRIGGER corporate_hosted_operation_terms BEFORE UPDATE OR DELETE ON corporate_hosted_operations FOR EACH ROW EXECUTE FUNCTION corporate_hosted_operation_guard()');
            foreach (['corporate_hosted_lines', 'corporate_hosted_events'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON $table FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()");
            }
        } else {
            throw new RuntimeException('Corporate hosted collections require SQLite or PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (DB::table('corporate_hosted_checkouts')->exists()) {
            throw new RuntimeException('Preserve corporate provider history before downgrade.');
        }
        foreach (['corporate_hosted_events', 'corporate_hosted_operations', 'corporate_hosted_lines', 'corporate_hosted_checkouts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
