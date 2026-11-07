<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_gateway_checkouts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscription_id')->constrained('commercial_subscriptions');
            $t->foreignId('invoice_id')->constrained('commercial_subscription_invoices');
            $t->foreignId('user_id')->constrained();
            $t->string('provider', 24);
            $t->string('provider_reference', 140)->unique();
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->string('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->string('status', 24);
            $t->string('failure_code')->nullable();
            $t->timestamp('authorization_expires_at')->nullable();
            $t->timestamps();
        });
        Schema::create('subscription_gateway_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('checkout_id')->constrained('subscription_gateway_checkouts');
            $t->string('kind', 24);
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->string('provider_operation_reference', 160)->unique();
            $t->unsignedBigInteger('amount_minor');
            $t->foreignId('receipt_id')->nullable()->constrained('commercial_subscription_receipts');
            $t->timestamp('created_at');
        });
        Schema::create('subscription_gateway_webhooks', function (Blueprint $t) {
            $t->id();
            $t->string('provider_event_id', 64)->unique();
            $t->string('fingerprint', 64);
            $t->foreignId('checkout_id')->constrained('subscription_gateway_checkouts');
            $t->string('kind', 80);
            $t->timestamp('created_at');
        });
        foreach (['subscription_gateway_operations', 'subscription_gateway_webhooks'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($event)." BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'Subscription gateway evidence is immutable'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()");
            } else {
                throw new RuntimeException('Subscription gateway requires SQLite or PostgreSQL.');
            }
        }
    }

    public function down(): void
    {
        foreach (['subscription_gateway_webhooks', 'subscription_gateway_operations', 'subscription_gateway_checkouts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
