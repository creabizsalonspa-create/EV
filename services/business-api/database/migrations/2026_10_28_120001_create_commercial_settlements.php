<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_settlement_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_id')->constrained('users');
            $t->string('currency', 3);
            $t->unsignedInteger('hold_days');
            $t->unsignedInteger('interval_days');
            $t->unsignedBigInteger('minimum_minor');
            $t->timestamp('next_due_at');
            $t->boolean('enabled')->default(false);
            $t->foreignId('configured_by')->constrained('users');
            $t->timestamps();
            $t->unique(['vendor_id', 'currency']);
        });
        Schema::create('commercial_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_id')->constrained('users');
            $t->string('currency', 3);
            $t->bigInteger('amount_minor');
            $t->string('reason', 500);
            $t->string('request_key', 64)->unique();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamp('created_at');
        });
        Schema::create('commercial_adjustment_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('adjustment_id')->unique()->constrained('commercial_adjustments');
            $t->foreignId('approved_by')->constrained('users');
            $t->timestamp('created_at');
        });
        Schema::create('commercial_adjustment_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('adjustment_id')->constrained('commercial_adjustments');
            $t->string('account', 120);
            $t->unsignedBigInteger('debit_minor');
            $t->unsignedBigInteger('credit_minor');
            $t->timestamp('created_at');
        });
        Schema::create('commercial_settlements', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('vendor_id')->constrained('users');
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            $t->unsignedBigInteger('amount_minor');
            $t->string('state');
            $t->string('request_key', 64)->unique();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('executed_at')->nullable();
            $t->string('evidence')->default('SIMULATED');
            $t->timestamps();
        });
        Schema::create('commercial_settlement_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('settlement_id')->constrained('commercial_settlements');
            $t->foreignId('invoice_id')->nullable()->constrained('finance_invoices');
            $t->foreignId('adjustment_id')->nullable()->constrained('commercial_adjustments');
            $t->bigInteger('amount_minor');
            $t->timestamp('created_at');
        });
        Schema::create('commercial_settlement_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('settlement_id')->constrained('commercial_settlements');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('action');
            $t->timestamp('created_at');
        });
        $unchanged = 'NEW.vendor_id = OLD.vendor_id AND NEW.currency = OLD.currency AND NEW.minor_units = OLD.minor_units AND NEW.amount_minor = OLD.amount_minor AND NEW.number = OLD.number AND NEW.request_key = OLD.request_key AND NEW.created_by = OLD.created_by AND NEW.created_at = OLD.created_at AND NEW.evidence = OLD.evidence';
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER commercial_settlements_terms BEFORE UPDATE ON commercial_settlements WHEN NOT ({$unchanged}) BEGIN SELECT RAISE(ABORT, 'Settlement terms are immutable'); END");
            DB::unprepared("CREATE TRIGGER commercial_settlements_no_delete BEFORE DELETE ON commercial_settlements BEGIN SELECT RAISE(ABORT, 'Settlement records are immutable'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION commercial_settlement_terms() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NOT ({$unchanged}) THEN RAISE EXCEPTION 'Settlement terms are immutable'; END IF; RETURN NEW; END; \$\$");
            DB::unprepared('CREATE TRIGGER commercial_settlements_terms BEFORE UPDATE ON commercial_settlements FOR EACH ROW EXECUTE FUNCTION commercial_settlement_terms()');
            DB::unprepared('CREATE TRIGGER commercial_settlements_no_delete BEFORE DELETE ON commercial_settlements FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()');
        }
        foreach (['commercial_adjustments', 'commercial_adjustment_approvals', 'commercial_adjustment_entries', 'commercial_settlement_lines', 'commercial_settlement_events'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($event)." BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'Settlement records are immutable'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()");
            }
        }
    }

    public function down(): void
    {
        foreach (['commercial_settlement_events', 'commercial_settlement_lines', 'commercial_settlements', 'commercial_adjustment_entries', 'commercial_adjustment_approvals', 'commercial_adjustments', 'commercial_settlement_schedules'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS commercial_settlement_terms()');
        }
    }
};
