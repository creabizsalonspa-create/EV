<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_unpaid_credits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->unique()->constrained('finance_invoices');
            $t->foreignId('journal_id')->unique()->constrained('finance_journals');
            $t->foreignId('credit_record_id')->unique()->constrained('ocpi_records');
            $t->foreignId('actor_id')->constrained('users');
            $t->uuid('request_key');
            $t->char('payload_hash', 64);
            $t->char('credit_source_hash', 64);
            $t->string('credit_note_number')->unique();
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            foreach (['amount_minor', 'tax_minor', 'commission_minor', 'vendor_minor'] as $field) {
                $t->unsignedBigInteger($field);
            }
            $t->text('reason');
            $t->string('evidence')->default('SIMULATED_NONCASH');
            $t->timestamp('created_at');
            $t->unique(['actor_id', 'request_key']);
        });
        Schema::create('finance_unpaid_credit_cancellations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('finance_invoices');
            $t->foreignId('actor_id')->constrained('users');
            $t->uuid('request_key');
            $t->timestamp('created_at');
            $t->unique(['actor_id', 'request_key']);
        });
        foreach (['finance_unpaid_credits', 'finance_unpaid_credit_cancellations'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($event)." BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'Credit history is immutable'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()");
            }
        }
    }

    public function down(): void
    {
        $disposable = app()->environment('testing') && (DB::connection()->getDatabaseName() === ':memory:' || preg_match('/_(review|test|testing)$/D', DB::connection()->getDatabaseName()));
        foreach (['finance_unpaid_credit_cancellations', 'finance_unpaid_credits'] as $table) {
            if (! $disposable && DB::table($table)->exists()) {
                throw new RuntimeException('Preserve immutable unpaid credit history before downgrade.');
            }
        }
        Schema::dropIfExists('finance_unpaid_credit_cancellations');
        Schema::dropIfExists('finance_unpaid_credits');
    }
};
