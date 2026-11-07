<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_customer_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained();
            $t->uuid('reference')->unique();
            $t->text('provider_customer')->nullable();
            $t->unsignedBigInteger('default_method_id')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
        });
        Schema::create('payment_enrollments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained('payment_customer_accounts');
            $t->foreignId('user_id')->constrained();
            $t->uuid('reference')->unique();
            $t->string('request_key', 64)->unique();
            $t->string('consent_version', 40);
            $t->timestamp('consented_at');
            $t->timestamp('consent_expires_at');
            $t->timestamp('expires_at');
            $t->string('status', 24);
            $t->text('provider_session')->nullable();
            $t->string('session_hash', 64)->nullable()->unique();
            $t->string('lease', 64)->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->timestamps();
        });
        Schema::create('payment_mandates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained('payment_customer_accounts');
            $t->foreignId('user_id')->constrained();
            $t->foreignId('enrollment_id')->unique()->constrained('payment_enrollments');
            $t->text('provider_method');
            $t->text('provider_setup');
            $t->string('method_hash', 64);
            $t->string('brand', 30);
            $t->string('last_four', 4);
            $t->unsignedSmallInteger('exp_month');
            $t->unsignedSmallInteger('exp_year');
            $t->string('consent_version', 40);
            $t->timestamp('consented_at');
            $t->timestamp('consent_expires_at');
            $t->string('status', 24);
            $t->unsignedInteger('version')->default(1);
            $t->string('detach_status', 24)->default('not_requested');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'method_hash']);
        });
        Schema::table('payment_customer_accounts', fn (Blueprint $t) => $t->foreign('default_method_id')->references('id')->on('payment_mandates'));
        Schema::create('subscription_collection_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscription_id')->unique()->constrained('commercial_subscriptions');
            $t->foreignId('user_id')->constrained();
            $t->foreignId('mandate_id')->nullable()->constrained('payment_mandates');
            $t->boolean('enabled')->default(false);
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
        });
        Schema::table('subscription_gateway_checkouts', function (Blueprint $t) {
            $t->foreignId('mandate_id')->nullable()->constrained('payment_mandates');
            $t->unsignedInteger('mandate_version')->nullable();
            $t->unsignedInteger('policy_version')->nullable();
        });
        Schema::create('subscription_collections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->unique()->constrained('commercial_subscription_invoices');
            $t->foreignId('subscription_id')->constrained('commercial_subscriptions');
            $t->foreignId('user_id')->constrained();
            $t->foreignId('checkout_id')->nullable()->constrained('subscription_gateway_checkouts');
            $t->unsignedInteger('attempts')->default(0);
            $t->string('state', 32);
            $t->string('failure_code', 64)->nullable();
            $t->timestamp('next_attempt_at')->nullable();
            $t->string('lease', 64)->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->timestamps();
        });
        Schema::create('recurring_payment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('subject', 60);
            $t->string('kind', 60);
            $t->string('provider_event', 100)->nullable()->unique();
            $t->string('fingerprint', 64)->nullable();
            $t->timestamp('created_at');
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared('CREATE TRIGGER recurring_events_no_'.strtolower($event)." BEFORE {$event} ON recurring_payment_events BEGIN SELECT RAISE(ABORT,'Recurring payment history is immutable'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER recurring_events_immutable BEFORE UPDATE OR DELETE ON recurring_payment_events FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()');
        }
    }

    public function down(): void
    {
        if (! app()->environment('testing') && (DB::table('recurring_payment_events')->exists() || DB::table('payment_mandates')->exists())) {
            throw new RuntimeException('Preserve recurring payment consent and audit history before downgrade.');
        }
        Schema::dropIfExists('recurring_payment_events');
        Schema::dropIfExists('subscription_collections');
        Schema::table('subscription_gateway_checkouts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('mandate_id');
            $t->dropColumn(['mandate_version', 'policy_version']);
        });
        Schema::dropIfExists('subscription_collection_policies');
        Schema::table('payment_customer_accounts', fn (Blueprint $t) => $t->dropForeign(['default_method_id']));
        Schema::dropIfExists('payment_mandates');
        Schema::dropIfExists('payment_enrollments');
        Schema::dropIfExists('payment_customer_accounts');
    }
};
