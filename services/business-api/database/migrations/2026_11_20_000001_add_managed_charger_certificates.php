<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charger_certificates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('profile_id')->constrained('charger_admission_profiles');
            $t->char('fingerprint', 64)->unique();
            $t->char('issuer_fingerprint', 64);
            $t->string('identity', 100);
            $t->string('serial', 40);
            $t->unique(['issuer_fingerprint', 'serial']);
            $t->string('key_type', 20);
            $t->unsignedInteger('key_bits');
            $t->timestamp('not_before');
            $t->timestamp('not_after');
            $t->text('public_certificate');
            $t->timestamp('created_at');
        });
        Schema::create('charger_certificate_actions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('profile_id')->constrained('charger_admission_profiles');
            $t->foreignId('certificate_id')->nullable()->constrained('charger_certificates');
            $t->foreignId('actor_id')->constrained('users');
            $t->uuid('request_key');
            $t->string('action', 20);
            $t->string('state', 20);
            $t->char('payload_hash', 64)->nullable();
            $t->text('reason')->nullable();
            $t->unsignedInteger('result_version');
            $t->timestamp('created_at');
            $t->unique(['actor_id', 'request_key']);
            $t->index(['certificate_id', 'action', 'state']);
        });
        Schema::table('charger_admission_profiles', fn (Blueprint $t) => $t->foreignId('managed_certificate_id')->nullable()->constrained('charger_certificates'));
        foreach (['charger_certificates', 'charger_certificate_actions'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($event)." BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'Certificate history is immutable'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION charger_admission_reject_mutation()");
            }
        }
    }

    public function down(): void
    {
        $disposable = app()->environment('testing') && (DB::connection()->getDatabaseName() === ':memory:' || preg_match('/_(review|test|testing)$/D', DB::connection()->getDatabaseName()));
        if (! $disposable && (DB::table('charger_certificates')->exists() || DB::table('charger_certificate_actions')->exists())) {
            throw new RuntimeException('Preserve immutable certificate history before downgrade.');
        }
        Schema::table('charger_admission_profiles', fn (Blueprint $t) => $t->dropConstrainedForeignId('managed_certificate_id'));
        Schema::dropIfExists('charger_certificate_actions');
        Schema::dropIfExists('charger_certificates');
    }
};
