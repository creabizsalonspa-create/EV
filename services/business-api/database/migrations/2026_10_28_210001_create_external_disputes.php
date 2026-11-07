<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_disputes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('checkout_id')->constrained('gateway_checkouts');
            $t->foreignId('case_id')->unique()->constrained('gateway_cases');
            $t->string('provider_reference', 100)->unique();
            $t->string('charge_reference', 100);
            $t->string('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->string('status', 40);
            $t->string('reason', 100);
            $t->timestamp('evidence_due_at')->nullable();
            $t->boolean('settlement_hold')->default(true);
            $t->timestamp('provider_observed_at');
            $t->timestamps();
        });
        Schema::create('external_dispute_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dispute_id')->constrained('external_disputes');
            $t->string('event_reference', 160)->unique();
            $t->string('fingerprint', 64);
            $t->string('type', 100);
            $t->string('status', 40);
            $t->timestamp('created_at');
        });
        Schema::create('external_dispute_evidence', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dispute_id')->constrained('external_disputes');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->text('encrypted_payload');
            $t->boolean('submit');
            $t->string('status', 32);
            $t->timestamp('retry_until');
            $t->timestamp('lease_until')->nullable();
            $t->timestamp('created_at');
            $t->timestamp('updated_at');
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER external_dispute_terms_immutable BEFORE UPDATE OF checkout_id, case_id, provider_reference, charge_reference, currency, amount_minor, created_at ON external_disputes BEGIN SELECT RAISE(ABORT, 'Dispute economics are immutable'); END");
            DB::unprepared("CREATE TRIGGER external_dispute_no_delete BEFORE DELETE ON external_disputes BEGIN SELECT RAISE(ABORT, 'Dispute economics are immutable'); END");
            DB::unprepared("CREATE TRIGGER external_evidence_terms_immutable BEFORE UPDATE OF dispute_id, actor_id, request_key, fingerprint, encrypted_payload, submit, retry_until, created_at ON external_dispute_evidence BEGIN SELECT RAISE(ABORT, 'Dispute evidence terms are immutable'); END");
            DB::unprepared("CREATE TRIGGER external_evidence_no_delete BEFORE DELETE ON external_dispute_evidence BEGIN SELECT RAISE(ABORT, 'Dispute evidence is immutable'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION reject_external_dispute_terms() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Dispute economics are immutable'; END IF; IF ROW(NEW.checkout_id, NEW.case_id, NEW.provider_reference, NEW.charge_reference, NEW.currency, NEW.amount_minor, NEW.created_at) IS DISTINCT FROM ROW(OLD.checkout_id, OLD.case_id, OLD.provider_reference, OLD.charge_reference, OLD.currency, OLD.amount_minor, OLD.created_at) THEN RAISE EXCEPTION 'Dispute economics are immutable'; END IF; RETURN NEW; END $$");
            DB::unprepared('CREATE TRIGGER external_dispute_terms_immutable BEFORE UPDATE OR DELETE ON external_disputes FOR EACH ROW EXECUTE FUNCTION reject_external_dispute_terms()');
            DB::unprepared("CREATE OR REPLACE FUNCTION reject_external_evidence_terms() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Dispute evidence is immutable'; END IF; IF ROW(NEW.dispute_id, NEW.actor_id, NEW.request_key, NEW.fingerprint, NEW.encrypted_payload, NEW.submit, NEW.retry_until, NEW.created_at) IS DISTINCT FROM ROW(OLD.dispute_id, OLD.actor_id, OLD.request_key, OLD.fingerprint, OLD.encrypted_payload, OLD.submit, OLD.retry_until, OLD.created_at) THEN RAISE EXCEPTION 'Dispute evidence terms are immutable'; END IF; RETURN NEW; END $$");
            DB::unprepared('CREATE TRIGGER external_evidence_terms_immutable BEFORE UPDATE OR DELETE ON external_dispute_evidence FOR EACH ROW EXECUTE FUNCTION reject_external_evidence_terms()');
        } else {
            throw new RuntimeException('Provider disputes require SQLite or PostgreSQL.');
        }
        foreach (['external_dispute_events'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($event)." BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'Provider dispute evidence is immutable'); END");
                }
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()");
            }
        }
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('external_disputes')->exists()) {
            throw new RuntimeException('Preserve provider dispute audit records before downgrade.');
        }
        foreach (['external_dispute_evidence', 'external_dispute_events', 'external_disputes'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS reject_external_evidence_terms()');
            DB::unprepared('DROP FUNCTION IF EXISTS reject_external_dispute_terms()');
        }
    }
};
