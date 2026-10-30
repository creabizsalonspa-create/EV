<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_recovery_intents', function (Blueprint $t) {
            $t->id();
            $t->string('identity', 64)->unique();
            $t->string('scope', 24);
            $t->unsignedBigInteger('checkout_id');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('kind', 24);
            $t->string('fingerprint', 64);
            $t->string('provider', 24);
            $t->string('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->string('status', 24);
            $t->string('lease', 64)->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->timestamp('retry_until');
            $t->unsignedInteger('attempts')->default(0);
            $t->text('observation')->nullable();
            $t->timestamps();
            $t->index(['scope', 'checkout_id', 'status']);
        });
        Schema::create('payment_recovery_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('intent_id')->constrained('payment_recovery_intents');
            $t->string('status', 24);
            $t->timestamp('created_at');
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared('CREATE TRIGGER payment_recovery_events_no_'.strtolower($event)." BEFORE {$event} ON payment_recovery_events BEGIN SELECT RAISE(ABORT,'Payment recovery history is immutable'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER payment_recovery_events_immutable BEFORE UPDATE OR DELETE ON payment_recovery_events FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_recovery_events');
        Schema::dropIfExists('payment_recovery_intents');
    }
};
