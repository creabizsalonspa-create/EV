<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_policies', function (Blueprint $t) {
            $t->id();
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            $t->boolean('enabled');
            $t->unsignedInteger('points_numerator');
            $t->unsignedInteger('minor_denominator');
            $t->unsignedInteger('gold_threshold');
            $t->unsignedInteger('platinum_threshold');
            $t->unsignedInteger('gold_multiplier_bps');
            $t->unsignedInteger('platinum_multiplier_bps');
            $t->unsignedInteger('invoice_cap');
            $t->unsignedInteger('daily_cap');
            $t->unsignedInteger('expiry_days');
            $t->unsignedInteger('daily_redemption_limit');
            $t->timestamp('effective_from');
            $t->foreignId('created_by')->constrained('users');
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->timestamp('created_at');
            $t->index(['currency', 'effective_from']);
        });
        Schema::create('loyalty_awards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('invoice_id')->unique()->constrained('finance_invoices');
            $t->foreignId('policy_id')->constrained('loyalty_policies');
            $t->unsignedBigInteger('qualifying_minor');
            $t->unsignedInteger('points');
            $t->string('tier');
            $t->unsignedInteger('multiplier_bps');
            $t->timestamp('expires_at');
            $t->timestamp('created_at');
        });
        Schema::create('loyalty_rewards', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('name_ar');
            $t->text('description');
            $t->text('description_ar');
            $t->string('currency', 3);
            $t->unsignedInteger('points_cost');
            $t->unsignedInteger('stock');
            $t->unsignedInteger('per_user_limit');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->foreignId('created_by')->constrained('users');
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->timestamp('created_at');
        });
        Schema::create('loyalty_redemptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('reward_id')->constrained('loyalty_rewards');
            $t->unsignedInteger('points_cost');
            $t->string('status');
            $t->string('request_key', 64)->unique();
            $t->timestamp('created_at');
            $t->timestamp('updated_at');
        });
        Schema::create('loyalty_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('redemption_id')->constrained('loyalty_redemptions');
            $t->foreignId('award_id')->constrained('loyalty_awards');
            $t->unsignedInteger('points');
            $t->unique(['redemption_id', 'award_id']);
        });
        Schema::create('loyalty_ledger', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('award_id')->nullable()->constrained('loyalty_awards');
            $t->foreignId('redemption_id')->nullable()->constrained('loyalty_redemptions');
            $t->string('kind');
            $t->bigInteger('points');
            $t->foreignId('actor_id')->constrained('users');
            $t->timestamp('created_at');
            $t->index(['user_id', 'id']);
        });
        Schema::create('loyalty_redemption_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('redemption_id')->constrained('loyalty_redemptions');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('action');
            $t->string('reason');
            $t->timestamp('created_at');
        });
        foreach (['loyalty_policies', 'loyalty_awards', 'loyalty_rewards', 'loyalty_allocations', 'loyalty_ledger', 'loyalty_redemption_events'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $event) {
                    DB::unprepared('CREATE TRIGGER '.$table.'_no_'.strtolower($event)." BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT,'Loyalty records are immutable'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()");
            }
        }
        $terms = 'NEW.user_id = OLD.user_id AND NEW.reward_id = OLD.reward_id AND NEW.points_cost = OLD.points_cost AND NEW.request_key = OLD.request_key AND NEW.created_at = OLD.created_at';
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER loyalty_redemptions_terms BEFORE UPDATE ON loyalty_redemptions WHEN NOT ({$terms}) BEGIN SELECT RAISE(ABORT,'Redemption terms are immutable'); END");
            DB::unprepared("CREATE TRIGGER loyalty_redemptions_no_delete BEFORE DELETE ON loyalty_redemptions BEGIN SELECT RAISE(ABORT,'Redemptions cannot be deleted'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION loyalty_redemptions_terms() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NOT ({$terms}) THEN RAISE EXCEPTION 'Redemption terms are immutable'; END IF; RETURN NEW; END; \$\$");
            DB::unprepared('CREATE TRIGGER loyalty_redemptions_terms BEFORE UPDATE ON loyalty_redemptions FOR EACH ROW EXECUTE FUNCTION loyalty_redemptions_terms()');
            DB::unprepared('CREATE TRIGGER loyalty_redemptions_no_delete BEFORE DELETE ON loyalty_redemptions FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()');
        }
    }

    public function down(): void
    {
        foreach (['loyalty_redemption_events', 'loyalty_ledger', 'loyalty_allocations', 'loyalty_redemptions', 'loyalty_rewards', 'loyalty_awards', 'loyalty_policies'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS loyalty_redemptions_terms()');
        }
    }
};
