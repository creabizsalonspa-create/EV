<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $immutable = ['finance_tariff_versions', 'finance_session_quotes', 'finance_invoices', 'finance_journals', 'finance_entries', 'finance_payments', 'finance_refunds'];

    public function up(): void
    {
        Schema::create('finance_tariff_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('station_id')->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedInteger('rate_minor_per_kwh');
            $t->unsignedInteger('tax_bps');
            $t->unsignedInteger('commission_bps');
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            $t->timestamp('effective_from');
            $t->timestamp('created_at');
        });
        Schema::create('finance_session_quotes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('charging_session_id')->unique()->constrained();
            $t->foreignId('tariff_version_id')->nullable()->constrained('finance_tariff_versions');
            $t->foreignId('user_id')->constrained();
            $t->foreignId('vendor_id')->constrained('users');
            $t->string('country', 2);
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            $t->unsignedInteger('rate_minor_per_kwh');
            $t->unsignedInteger('tax_bps');
            $t->unsignedInteger('commission_bps');
            $t->string('evidence')->default('SIMULATED');
            $t->timestamp('created_at');
        });
        Schema::create('finance_invoices', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('charging_session_id')->unique()->constrained();
            $t->foreignId('quote_id')->unique()->constrained('finance_session_quotes');
            $t->foreignId('user_id')->constrained();
            $t->foreignId('vendor_id')->constrained('users');
            $t->string('country', 2);
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            $t->unsignedBigInteger('energy_wh');
            $t->unsignedBigInteger('subtotal_minor');
            $t->unsignedBigInteger('tax_minor');
            $t->unsignedBigInteger('commission_minor');
            $t->unsignedBigInteger('vendor_minor');
            $t->unsignedBigInteger('total_minor');
            $t->string('evidence')->default('SIMULATED');
            $t->timestamp('created_at');
        });
        Schema::create('finance_journals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('finance_invoices');
            $t->string('kind');
            $t->string('idempotency_key', 180)->unique();
            $t->string('fingerprint', 64);
            $t->string('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->timestamp('created_at');
        });
        Schema::create('finance_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('journal_id')->constrained('finance_journals');
            $t->string('account', 120);
            $t->unsignedBigInteger('debit_minor')->default(0);
            $t->unsignedBigInteger('credit_minor')->default(0);
            $t->timestamp('created_at');
        });
        Schema::create('finance_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('finance_invoices');
            $t->foreignId('journal_id')->unique()->constrained('finance_journals');
            $t->foreignId('actor_id')->constrained('users');
            $t->unsignedBigInteger('amount_minor');
            $t->string('evidence')->default('SIMULATED');
            $t->timestamp('created_at');
        });
        Schema::create('finance_refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('finance_invoices');
            $t->foreignId('journal_id')->unique()->constrained('finance_journals');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('credit_note_number')->unique();
            $t->unsignedBigInteger('amount_minor');
            $t->unsignedBigInteger('tax_minor');
            $t->unsignedBigInteger('commission_minor');
            $t->unsignedBigInteger('vendor_minor');
            $t->string('reason', 500);
            $t->string('evidence')->default('SIMULATED');
            $t->timestamp('created_at');
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach ($this->immutable as $table) {
                foreach (['UPDATE', 'DELETE'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($event)." BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'Finance records are immutable'); END");
                }
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            // migrate:fresh drops tables, not PostgreSQL functions. Reinstall
            // the same guard when rebuilding a disposable or restored schema.
            DB::unprepared("CREATE OR REPLACE FUNCTION finance_reject_mutation() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Finance records are immutable'; END; \$\$");
            foreach ($this->immutable as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()");
            }
        } else {
            throw new RuntimeException('Immutable finance storage currently requires SQLite or PostgreSQL.');
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->immutable) as $table) {
            Schema::dropIfExists($table);
        }if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS finance_reject_mutation()');
        }
    }
};
