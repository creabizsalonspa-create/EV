<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_campaign_cancelled_intents', function (Blueprint $table) {
            $table->char('request_key', 64)->primary();
            $table->foreignId('vendor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER campaign_cancelled_intent_'.strtolower($operation)." BEFORE {$operation} ON commercial_campaign_cancelled_intents BEGIN SELECT RAISE(ABORT, 'Cancelled campaign intents are immutable'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION reject_campaign_cancelled_intent_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Cancelled campaign intents are immutable'; END $$; CREATE TRIGGER campaign_cancelled_intent_immutable BEFORE UPDATE OR DELETE ON commercial_campaign_cancelled_intents FOR EACH ROW EXECUTE FUNCTION reject_campaign_cancelled_intent_mutation()");
        }
    }

    public function down(): void
    {
        if (DB::table('commercial_campaign_cancelled_intents')->exists()) {
            throw new RuntimeException('Preserve cancelled campaign intent receipts; delayed creation must remain rejected.');
        }
        Schema::dropIfExists('commercial_campaign_cancelled_intents');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS reject_campaign_cancelled_intent_mutation()');
        }
    }
};
