<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_reward_cancelled_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            // A cancellation may fence a stale or nonexistent offer ID.
            // It reserves no reward and intentionally has no review FK.
            $table->unsignedBigInteger('review_id');
            $table->char('request_key', 64)->unique();
            $table->timestamp('created_at');
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared("CREATE TRIGGER partner_reward_cancelled_intents_no_{$event} BEFORE {$event} ON partner_reward_cancelled_intents BEGIN SELECT RAISE(ABORT, 'Partner reward cancellation evidence is immutable'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER partner_reward_cancelled_intents_immutable BEFORE UPDATE OR DELETE ON partner_reward_cancelled_intents FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()');
        } else {
            throw new RuntimeException('Partner reward cancellation evidence requires SQLite or PostgreSQL.');
        }
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('partner_reward_cancelled_intents')->exists()) {
            throw new RuntimeException('Preserve partner reward cancellation evidence before rollback.');
        }
        Schema::dropIfExists('partner_reward_cancelled_intents');
    }
};
