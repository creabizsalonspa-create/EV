<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_payout_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_id')->constrained('users');
            $t->string('provider');
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            $t->text('account_reference');
            $t->text('bank_reference');
            $t->string('fingerprint', 64);
            $t->string('request_key', 64)->unique();
            $t->string('status');
            $t->foreignId('configured_by')->constrained('users');
            $t->foreignId('consented_by')->nullable()->constrained('users');
            $t->string('consent_version')->nullable();
            $t->timestamp('consented_at')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('commercial_payouts', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->foreignId('settlement_id')->unique()->constrained('commercial_settlements');
            $t->foreignId('account_id')->constrained('commercial_payout_accounts');
            $t->foreignId('vendor_id')->constrained('users');
            $t->string('provider');
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            $t->unsignedBigInteger('amount_minor');
            $t->string('request_key', 64)->unique();
            $t->string('status');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamp('approved_at')->nullable();
            $t->unsignedInteger('attempt_number')->default(0);
            $t->string('evidence');
            $t->timestamps();
        });
        Schema::create('commercial_payout_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payout_id')->constrained('commercial_payouts');
            $t->unsignedInteger('number');
            $t->string('reference')->unique();
            $t->string('status');
            $t->string('provider_reference')->nullable()->index();
            $t->string('lease', 64)->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->timestamp('retry_until');
            $t->boolean('request_sent')->default(false);
            $t->string('failure_code')->nullable();
            $t->timestamp('observed_at')->nullable();
            $t->timestamps();
            $t->unique(['payout_id', 'number']);
        });
        Schema::create('commercial_payout_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payout_id')->nullable()->constrained('commercial_payouts');
            $t->foreignId('account_id')->nullable()->constrained('commercial_payout_accounts');
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('action');
            $t->string('provider_reference')->nullable();
            $t->string('failure_code')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('commercial_payout_webhooks', function (Blueprint $t) {
            $t->id();
            $t->string('provider_event')->unique();
            $t->string('fingerprint', 64);
            $t->foreignId('attempt_id')->constrained('commercial_payout_attempts');
            $t->string('status');
            $t->timestamps();
        });
        $terms = [
            'commercial_payout_accounts' => ['vendor_id', 'provider', 'currency', 'minor_units', 'account_reference', 'bank_reference', 'fingerprint', 'request_key', 'configured_by', 'created_at'],
            'commercial_payouts' => ['reference', 'settlement_id', 'account_id', 'vendor_id', 'provider', 'currency', 'minor_units', 'amount_minor', 'request_key', 'evidence', 'created_at'],
            'commercial_payout_attempts' => ['payout_id', 'number', 'reference', 'retry_until', 'created_at'],
        ];
        foreach ($terms as $table => $columns) {
            $same = implode(' AND ', array_map(fn ($c) => "NEW.{$c} = OLD.{$c}", $columns));
            if (DB::getDriverName() === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$table}_terms BEFORE UPDATE ON {$table} WHEN NOT ({$same}) BEGIN SELECT RAISE(ABORT, 'Payout economic terms are immutable'); END");
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Payout records are immutable'); END");
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE OR REPLACE FUNCTION {$table}_terms() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NOT ({$same}) THEN RAISE EXCEPTION 'Payout economic terms are immutable'; END IF; RETURN NEW; END; \$\$");
                DB::unprepared("CREATE TRIGGER {$table}_terms BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_terms()");
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()");
            }
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared('CREATE TRIGGER commercial_payout_events_no_'.strtolower($event)." BEFORE {$event} ON commercial_payout_events BEGIN SELECT RAISE(ABORT, 'Payout events are immutable'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER commercial_payout_events_immutable BEFORE UPDATE OR DELETE ON commercial_payout_events FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()');
        }
    }

    public function down(): void
    {
        foreach (['commercial_payout_webhooks', 'commercial_payout_events', 'commercial_payout_attempts', 'commercial_payouts', 'commercial_payout_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            foreach (['commercial_payout_accounts', 'commercial_payouts', 'commercial_payout_attempts'] as $table) {
                DB::unprepared("DROP FUNCTION IF EXISTS {$table}_terms()");
            }
        }
    }
};
