<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_checkouts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('finance_invoices');
            $t->foreignId('user_id')->constrained();
            $t->string('provider');
            $t->string('provider_reference')->unique();
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->string('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->string('status');
            $t->string('failure_code')->nullable();
            $t->timestamp('authorization_expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('gateway_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('checkout_id')->constrained('gateway_checkouts');
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->string('kind');
            $t->unsignedBigInteger('amount_minor');
            $t->string('provider_reference')->unique();
            $t->foreignId('payment_id')->nullable()->constrained('finance_payments');
            $t->foreignId('refund_id')->nullable()->constrained('finance_refunds');
            $t->timestamp('created_at');
        });
        Schema::create('gateway_webhook_events', function (Blueprint $t) {
            $t->id();
            $t->string('provider_event_id')->unique();
            $t->string('fingerprint', 64);
            $t->foreignId('checkout_id')->constrained('gateway_checkouts');
            $t->string('type');
            $t->timestamp('created_at');
        });
        Schema::create('gateway_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('finance_invoices');
            $t->foreignId('user_id')->constrained();
            $t->string('kind');
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->unsignedBigInteger('amount_minor');
            $t->string('reason', 500);
            $t->string('status');
            $t->string('resolution', 500)->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users');
            $t->foreignId('refund_id')->nullable()->constrained('finance_refunds');
            $t->timestamps();
        });
        foreach (['gateway_operations', 'gateway_webhook_events'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($event)." BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'Gateway evidence is immutable'); END");
                }
            } else {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()");
            }
        }
    }

    public function down(): void
    {
        foreach (['gateway_cases', 'gateway_webhook_events', 'gateway_operations', 'gateway_checkouts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
