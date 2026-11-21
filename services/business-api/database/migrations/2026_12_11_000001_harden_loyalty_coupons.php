<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_coupon_claim_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->char('request_key', 64)->unique();
            $table->char('code_hash', 64);
            $table->timestamp('created_at');
        });
        Schema::create('loyalty_coupon_revocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->unique()->constrained('loyalty_coupons');
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->boolean('legacy_observation')->default(false);
            $table->string('reason', 500);
            $table->timestamp('created_at');
        });
        // Preserve already-applied PR194 data without inventing a reviewer.
        foreach (DB::table('loyalty_coupons')->whereNotNull('revoked_at')->orderBy('id')->cursor() as $coupon) {
            DB::table('loyalty_coupon_revocations')->insert([
                'coupon_id' => $coupon->id, 'actor_id' => null, 'legacy_observation' => true,
                'reason' => 'Legacy revocation imported without actor or decision-reason evidence.',
                'created_at' => now(),
            ]);
        }
        foreach (['loyalty_coupon_claims', 'loyalty_coupon_revocations', 'loyalty_coupon_claim_intents'] as $table) {
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
        if (! app()->environment('testing')) {
            foreach (['loyalty_coupons', 'loyalty_coupon_claims', 'loyalty_coupon_revocations', 'loyalty_coupon_claim_intents'] as $table) {
                if (DB::table($table)->exists()) {
                    throw new RuntimeException('Preserve coupon commitments before downgrade.');
                }
            }
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach (['loyalty_coupons_terms', 'loyalty_coupons_no_delete', 'loyalty_coupon_claims_no_update', 'loyalty_coupon_claims_no_delete'] as $trigger) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS loyalty_coupons_terms ON loyalty_coupons');
            DB::unprepared('DROP TRIGGER IF EXISTS loyalty_coupons_no_delete ON loyalty_coupons');
            DB::unprepared('DROP TRIGGER IF EXISTS loyalty_coupon_claims_immutable ON loyalty_coupon_claims');
            DB::unprepared('DROP FUNCTION IF EXISTS loyalty_coupons_terms()');
        }
        Schema::dropIfExists('loyalty_coupon_revocations');
        Schema::dropIfExists('loyalty_coupon_claim_intents');
    }
};
