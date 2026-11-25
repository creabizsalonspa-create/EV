<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_reward_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('partner_reward_reviews');
            $table->foreignId('approval_event_id')->constrained('partner_reward_review_events');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('redemption_id')->unique()->constrained('loyalty_redemptions');
            $table->char('request_key', 64)->unique();
            $table->timestamp('created_at');
            $table->index(['user_id', 'id']);
        });

        match (DB::getDriverName()) {
            'pgsql' => DB::unprepared('CREATE TRIGGER partner_reward_claims_immutable BEFORE UPDATE OR DELETE ON partner_reward_claims FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()'),
            'sqlite' => $this->protectSqlite(),
            default => throw new RuntimeException('Partner reward claims require SQLite or PostgreSQL.'),
        };
    }

    private function protectSqlite(): void
    {
        foreach (['UPDATE', 'DELETE'] as $operation) {
            DB::unprepared("CREATE TRIGGER partner_reward_claims_no_{$operation} BEFORE {$operation} ON partner_reward_claims BEGIN SELECT RAISE(ABORT, 'Partner reward claims are immutable'); END");
        }
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('partner_reward_claims')->exists()) {
            throw new RuntimeException('Preserve partner reward claim evidence before rollback.');
        }
        Schema::dropIfExists('partner_reward_claims');
    }
};
