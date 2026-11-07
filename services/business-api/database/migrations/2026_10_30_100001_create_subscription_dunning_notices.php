<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_dunning_notices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('commercial_subscription_invoices');
            $t->unsignedSmallInteger('stage');
            $t->timestamp('due_at');
            $t->timestamp('created_at');
            $t->unique(['invoice_id', 'stage']);
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared('CREATE TRIGGER subscription_dunning_no_'.strtolower($event)." BEFORE {$event} ON subscription_dunning_notices BEGIN SELECT RAISE(ABORT,'Dunning notices are immutable'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER subscription_dunning_immutable BEFORE UPDATE OR DELETE ON subscription_dunning_notices FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::table('subscription_dunning_notices')->exists() && ! app()->environment('testing')) {
            throw new RuntimeException('Preserve subscription dunning history before downgrade.');
        }
        Schema::dropIfExists('subscription_dunning_notices');
    }
};
