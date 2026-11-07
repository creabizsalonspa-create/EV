<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_credit_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscription_id')->constrained('commercial_subscriptions');
            $t->foreignId('invoice_id')->constrained('commercial_subscription_invoices');
            $t->foreignId('receipt_id')->constrained('commercial_subscription_receipts');
            $t->foreignId('checkout_id')->nullable()->constrained('subscription_gateway_checkouts');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('request_key', 64)->unique();
            $t->string('recovery_identity', 64)->nullable()->unique();
            $t->string('fingerprint', 64);
            $t->unsignedBigInteger('amount_minor');
            $t->string('currency', 3);
            $t->string('reason', 500);
            $t->timestamp('created_at');
        });
        Schema::create('subscription_credits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('request_id')->unique()->constrained('subscription_credit_requests');
            $t->string('number')->unique();
            $t->foreignId('subscription_id')->constrained('commercial_subscriptions');
            $t->foreignId('invoice_id')->constrained('commercial_subscription_invoices');
            $t->foreignId('receipt_id')->constrained('commercial_subscription_receipts');
            $t->foreignId('actor_id')->constrained('users');
            $t->unsignedBigInteger('amount_minor');
            $t->unsignedBigInteger('subtotal_minor');
            $t->unsignedBigInteger('tax_minor');
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            $t->string('provider_reference', 160)->unique();
            $t->string('evidence', 40);
            $t->string('reason', 500);
            $t->timestamp('created_at');
        });
        Schema::create('subscription_credit_webhooks', function (Blueprint $t) {
            $t->id();
            $t->string('provider_event_id', 64)->unique();
            $t->string('fingerprint', 64);
            $t->foreignId('credit_id')->constrained('subscription_credits');
            $t->timestamp('created_at');
        });
        foreach (['subscription_credit_requests', 'subscription_credits', 'subscription_credit_webhooks'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($event)." BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'Subscription credit evidence is immutable'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()");
            } else {
                throw new RuntimeException('Subscription credits require SQLite or PostgreSQL.');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_credit_webhooks');
        Schema::dropIfExists('subscription_credits');
        Schema::dropIfExists('subscription_credit_requests');
    }
};
