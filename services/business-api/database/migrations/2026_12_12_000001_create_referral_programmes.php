<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_referral_programmes', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150);
            $t->string('name_ar', 150);
            $t->text('terms');
            $t->text('terms_ar');
            $t->string('terms_version', 64);
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            $t->unsignedBigInteger('minimum_minor');
            $t->foreignId('referrer_reward_id')->constrained('loyalty_rewards');
            $t->foreignId('member_reward_id')->constrained('loyalty_rewards');
            $t->unsignedInteger('claim_limit');
            $t->unsignedInteger('per_referrer_limit');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->foreignId('created_by')->constrained('users');
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->timestamp('created_at');
        });
        Schema::create('loyalty_referral_programme_revocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('programme_id')->unique()->constrained('loyalty_referral_programmes');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('reason', 500);
            $t->timestamp('created_at');
        });
        Schema::create('loyalty_referral_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('programme_id')->constrained('loyalty_referral_programmes');
            $t->foreignId('user_id')->constrained('users');
            $t->string('code', 24)->unique();
            $t->string('request_key', 64)->unique();
            $t->timestamp('created_at');
            $t->unique(['programme_id', 'user_id']);
        });
        Schema::create('loyalty_referrals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('programme_id')->constrained('loyalty_referral_programmes');
            $t->foreignId('user_id')->unique()->constrained('users');
            $t->foreignId('referrer_id')->constrained('users');
            $t->foreignId('code_id')->constrained('loyalty_referral_codes');
            $t->string('terms_version', 64);
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->timestamp('created_at');
        });
        Schema::create('loyalty_referral_withdrawals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('referral_id')->unique()->constrained('loyalty_referrals');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('reason', 500);
            $t->timestamp('created_at');
        });
        Schema::create('loyalty_referral_qualifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('referral_id')->unique()->constrained('loyalty_referrals');
            $t->foreignId('invoice_id')->unique()->constrained('finance_invoices');
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->timestamp('created_at');
        });
        Schema::create('loyalty_referral_rewards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('qualification_id')->constrained('loyalty_referral_qualifications');
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('redemption_id')->unique()->constrained('loyalty_redemptions');
            $t->string('role', 10);
            $t->timestamp('created_at');
            $t->unique(['qualification_id', 'role']);
        });
        foreach ($this->tables() as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($event)." BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT,'Referral evidence is immutable'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()");
            } else {
                throw new RuntimeException('Referral evidence requires PostgreSQL or SQLite.');
            }
        }
    }

    private function tables(): array
    {
        return ['loyalty_referral_programmes', 'loyalty_referral_programme_revocations', 'loyalty_referral_codes', 'loyalty_referrals', 'loyalty_referral_withdrawals', 'loyalty_referral_qualifications', 'loyalty_referral_rewards'];
    }

    public function down(): void
    {
        if (! app()->environment('testing')) {
            foreach ($this->tables() as $table) {
                if (DB::table($table)->exists()) {
                    throw new RuntimeException('Preserve immutable referral commitments before downgrade.');
                }
            }
        }
        foreach (array_reverse($this->tables()) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
