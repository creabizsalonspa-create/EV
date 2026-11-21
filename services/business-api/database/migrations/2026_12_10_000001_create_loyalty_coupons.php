<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reward_id')->constrained('loyalty_rewards');
            $table->foreignId('created_by')->constrained('users');
            $table->char('code_hash', 64)->unique();
            $table->char('publication_key', 64)->unique();
            $table->char('fingerprint', 64);
            $table->unsignedInteger('claim_limit');
            $table->unsignedInteger('per_user_limit');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('loyalty_coupon_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('loyalty_coupons');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('redemption_id')->unique()->constrained('loyalty_redemptions');
            $table->char('request_key', 64)->unique();
            $table->timestamp('created_at');
            $table->index(['coupon_id', 'user_id']);
            $table->index(['user_id', 'id']);
        });
        Schema::create('loyalty_coupon_revocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->unique()->constrained('loyalty_coupons');
            $table->foreignId('actor_id')->constrained('users');
            $table->string('reason', 500);
            $table->timestamp('created_at');
        });
        foreach (['loyalty_coupon_claims', 'loyalty_coupon_revocations'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($event)." BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT,'Coupon evidence is immutable'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()");
            }
        }
        $terms = 'NEW.reward_id = OLD.reward_id AND NEW.created_by = OLD.created_by AND NEW.code_hash = OLD.code_hash AND NEW.publication_key = OLD.publication_key AND NEW.fingerprint = OLD.fingerprint AND NEW.claim_limit = OLD.claim_limit AND NEW.per_user_limit = OLD.per_user_limit AND NEW.starts_at = OLD.starts_at AND NEW.ends_at = OLD.ends_at AND NEW.created_at = OLD.created_at';
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER loyalty_coupons_terms BEFORE UPDATE ON loyalty_coupons WHEN NOT ({$terms}) OR (OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS NOT OLD.revoked_at) BEGIN SELECT RAISE(ABORT,'Coupon commitments are immutable'); END");
            DB::unprepared("CREATE TRIGGER loyalty_coupons_no_delete BEFORE DELETE ON loyalty_coupons BEGIN SELECT RAISE(ABORT,'Coupon commitments cannot be deleted'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION loyalty_coupons_terms() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NOT ({$terms}) OR (OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS DISTINCT FROM OLD.revoked_at) THEN RAISE EXCEPTION 'Coupon commitments are immutable'; END IF; RETURN NEW; END; \$\$");
            DB::unprepared('CREATE TRIGGER loyalty_coupons_terms BEFORE UPDATE ON loyalty_coupons FOR EACH ROW EXECUTE FUNCTION loyalty_coupons_terms()');
            DB::unprepared('CREATE TRIGGER loyalty_coupons_no_delete BEFORE DELETE ON loyalty_coupons FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()');
        } else {
            throw new RuntimeException('Coupon commitments require PostgreSQL or SQLite.');
        }
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('loyalty_coupons')->exists()) {
            throw new RuntimeException('Preserve coupon commitments before rollback.');
        }
        Schema::dropIfExists('loyalty_coupon_revocations');
        Schema::dropIfExists('loyalty_coupon_claims');
        Schema::dropIfExists('loyalty_coupons');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS loyalty_coupons_terms()');
        }
    }
};
