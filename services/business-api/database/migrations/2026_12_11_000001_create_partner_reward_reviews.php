<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_reward_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('retail_partners');
            $table->foreignId('partner_version_id')->constrained('retail_partner_versions');
            $table->unsignedInteger('partner_revision');
            $table->foreignId('reward_id')->constrained('loyalty_rewards');
            $table->foreignId('proposed_by')->constrained('users');
            $table->timestamp('created_at');
            $table->index(['partner_id', 'id']);
        });
        Schema::create('partner_reward_review_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('partner_reward_reviews');
            $table->foreignId('actor_id')->constrained('users');
            $table->unsignedInteger('revision');
            $table->string('decision', 12);
            $table->string('reason', 500);
            $table->char('request_key', 64)->unique();
            $table->char('fingerprint', 64);
            $table->timestamp('created_at');
            $table->unique(['review_id', 'revision']);
        });

        foreach (['partner_reward_reviews', 'partner_reward_review_events'] as $table) {
            match (DB::getDriverName()) {
                'pgsql' => DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()"),
                'sqlite' => $this->protectSqlite($table),
                default => throw new RuntimeException('Reward governance requires SQLite or PostgreSQL.'),
            };
        }
    }

    private function protectSqlite(string $table): void
    {
        foreach (['UPDATE', 'DELETE'] as $operation) {
            DB::unprepared("CREATE TRIGGER {$table}_no_{$operation} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Partner reward governance history is immutable'); END");
        }
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('partner_reward_reviews')->exists()) {
            throw new RuntimeException('Preserve partner reward governance records before rollback.');
        }
        Schema::dropIfExists('partner_reward_review_events');
        Schema::dropIfExists('partner_reward_reviews');
    }
};
