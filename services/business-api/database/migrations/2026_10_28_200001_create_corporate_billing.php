<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_agreements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fleet_id')->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->string('reference')->unique();
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            $t->unsignedBigInteger('credit_limit_minor');
            $t->unsignedSmallInteger('payment_terms_days');
            $t->unsignedTinyInteger('billing_day');
            $t->timestamp('effective_from');
            $t->timestamp('expires_at');
            $t->string('legal_name', 200);
            $t->string('terms', 2000);
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->timestamp('created_at');
        });
        Schema::create('corporate_agreement_acceptances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('agreement_id')->unique()->constrained('corporate_agreements');
            $t->foreignId('accepted_by')->constrained('users');
            $t->timestamp('created_at');
        });
        Schema::create('corporate_claims', function (Blueprint $t) {
            $t->id();
            $t->foreignId('agreement_id')->constrained('corporate_agreements');
            $t->foreignId('invoice_id')->unique()->constrained('finance_invoices');
            $t->foreignId('driver_id')->constrained('users');
            $t->foreignId('cost_center_id')->constrained();
            $t->string('cost_center_code');
            $t->string('cost_center_name');
            $t->unsignedBigInteger('amount_minor');
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->timestamp('created_at');
        });
        Schema::create('corporate_claim_decisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('claim_id')->unique()->constrained('corporate_claims');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('decision', 12);
            $t->string('reason', 500);
            $t->timestamp('created_at');
        });
        Schema::create('corporate_statements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('agreement_id')->constrained('corporate_agreements');
            $t->foreignId('fleet_id')->constrained();
            $t->foreignId('issued_by')->constrained('users');
            $t->foreignId('payer_id')->constrained('users');
            $t->string('number')->unique();
            $t->string('period', 7);
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            $t->unsignedBigInteger('subtotal_minor');
            $t->unsignedBigInteger('tax_minor');
            $t->unsignedBigInteger('total_minor');
            $t->timestamp('due_at');
            $t->text('agreement_snapshot');
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->timestamp('created_at');
        });
        Schema::create('corporate_statement_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('statement_id')->constrained('corporate_statements');
            $t->foreignId('claim_id')->unique()->constrained('corporate_claims');
            $t->foreignId('invoice_id')->unique()->constrained('finance_invoices');
            $t->string('invoice_number');
            $t->foreignId('driver_id')->constrained('users');
            $t->foreignId('cost_center_id')->constrained();
            $t->string('cost_center_code');
            $t->string('cost_center_name');
            $t->unsignedBigInteger('subtotal_minor');
            $t->unsignedBigInteger('tax_minor');
            $t->unsignedBigInteger('total_minor');
            $t->timestamp('created_at');
        });
        Schema::create('corporate_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('statement_id')->unique()->constrained('corporate_statements');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('number')->unique();
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->unsignedBigInteger('amount_minor');
            $t->string('evidence');
            $t->timestamp('created_at');
        });
        Schema::create('corporate_receipt_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('receipt_id')->constrained('corporate_receipts');
            $t->foreignId('payment_id')->unique()->constrained('finance_payments');
            $t->foreignId('invoice_id')->unique()->constrained('finance_invoices');
            $t->timestamp('created_at');
        });
        foreach ($this->tables() as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($event)." BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'Corporate financial evidence is immutable'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()");
            } else {
                throw new RuntimeException('Corporate billing requires SQLite or PostgreSQL.');
            }
        }
    }

    private function tables(): array
    {
        return ['corporate_agreements', 'corporate_agreement_acceptances', 'corporate_claims', 'corporate_claim_decisions', 'corporate_statements', 'corporate_statement_lines', 'corporate_receipts', 'corporate_receipt_payments'];
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables()) as $table) {
            if (! app()->environment('testing') && DB::table($table)->exists()) {
                throw new RuntimeException('Refusing to discard corporate financial history.');
            }
        }
        foreach (array_reverse($this->tables()) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
