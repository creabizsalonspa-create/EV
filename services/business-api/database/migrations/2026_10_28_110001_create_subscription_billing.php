<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $immutable = ['commercial_plan_versions', 'commercial_subscription_invoices', 'commercial_subscription_receipts', 'commercial_subscription_events', 'commercial_subscription_ledger'];

    public function up(): void
    {
        Schema::create('commercial_plan_versions', function (Blueprint $t) {
            $t->id();
            $t->string('code', 64);
            $t->unsignedInteger('version');
            $t->string('name', 120);
            $t->string('audience', 16);
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            $t->unsignedBigInteger('price_minor');
            $t->unsignedInteger('tax_bps');
            $t->string('interval', 16);
            $t->json('entitlements');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamp('created_at');
            $t->unique(['code', 'version']);
        });
        Schema::create('commercial_plan_availability', function (Blueprint $t) {
            $t->foreignId('plan_id')->primary()->constrained('commercial_plan_versions');
            $t->boolean('available')->default(true);
        });
        Schema::create('commercial_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('plan_id')->constrained('commercial_plan_versions');
            $t->foreignId('next_plan_id')->nullable()->constrained('commercial_plan_versions');
            $t->string('status', 20);
            $t->timestamp('period_start')->nullable();
            $t->timestamp('period_end')->nullable();
            $t->unsignedInteger('cycle_number')->default(0);
            $t->boolean('cancel_at_period_end')->default(false);
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'period_end']);
        });
        Schema::create('commercial_subscription_invoices', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('subscription_id')->constrained('commercial_subscriptions');
            $t->foreignId('plan_id')->constrained('commercial_plan_versions');
            $t->unsignedInteger('cycle_number');
            $t->timestamp('period_start');
            $t->timestamp('period_end');
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            $t->unsignedBigInteger('subtotal_minor');
            $t->unsignedBigInteger('tax_minor');
            $t->unsignedBigInteger('total_minor');
            $t->json('entitlements');
            $t->string('evidence')->default('MOCK_PROVIDER');
            $t->timestamp('created_at');
            $t->unique(['subscription_id', 'cycle_number'], 'subscription_cycle_once');
        });
        Schema::create('commercial_subscription_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->unique()->constrained('commercial_subscription_invoices');
            $t->string('provider_reference')->unique();
            $t->unsignedBigInteger('amount_minor');
            $t->string('currency', 3);
            $t->string('evidence')->default('MOCK_PROVIDER');
            $t->timestamp('created_at');
        });
        Schema::create('commercial_subscription_ledger', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('commercial_subscription_invoices');
            $t->string('kind', 20);
            $t->string('account', 100);
            $t->unsignedBigInteger('debit_minor');
            $t->unsignedBigInteger('credit_minor');
            $t->timestamp('created_at');
            $t->unique(['invoice_id', 'kind', 'account'], 'subscription_ledger_once');
        });
        Schema::create('commercial_subscription_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscription_id')->constrained('commercial_subscriptions');
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('kind', 40);
            $t->string('request_key', 180)->nullable()->unique();
            $t->string('fingerprint', 64)->nullable();
            $t->json('data');
            $t->timestamp('created_at');
        });
        foreach ($this->immutable as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($event)." BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'Subscription financial records are immutable'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()");
            } else {
                throw new RuntimeException('Subscription financial storage requires SQLite or PostgreSQL.');
            }
        }
    }

    public function down(): void
    {
        foreach (['commercial_subscription_events', 'commercial_subscription_ledger', 'commercial_subscription_receipts', 'commercial_subscription_invoices', 'commercial_subscriptions', 'commercial_plan_availability', 'commercial_plan_versions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
