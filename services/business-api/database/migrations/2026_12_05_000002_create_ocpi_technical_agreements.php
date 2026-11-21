<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocpi_technical_agreements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('provider_id')->constrained('ocpi_providers')->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->unsignedBigInteger('provider_owner_id');
            $t->char('configuration_hash', 64);
            $t->string('request_key', 100);
            $t->char('request_hash', 64);
            $t->text('payload_ciphertext');
            $t->char('payload_hash', 64);
            $t->json('allowed_modules');
            $t->timestampTz('valid_from', 6);
            $t->timestampTz('valid_until', 6);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at', 6);
            $t->unique(['provider_id', 'revision']);
            $t->unique(['provider_id', 'request_key']);
            $t->unique(['id', 'provider_id']);
        });
        Schema::create('ocpi_technical_agreement_heads', function (Blueprint $t) {
            $t->unsignedBigInteger('provider_id')->primary();
            $t->foreign('provider_id')->references('id')->on('ocpi_providers')->restrictOnDelete();
            $t->unsignedInteger('revision')->default(0);
            $t->unsignedBigInteger('active_agreement_id')->nullable();
            $t->foreign(['active_agreement_id', 'provider_id'])->references(['id', 'provider_id'])->on('ocpi_technical_agreements')->restrictOnDelete();
        });
        Schema::create('ocpi_technical_agreement_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('provider_id')->constrained('ocpi_providers')->restrictOnDelete();
            $t->foreignId('agreement_id')->constrained('ocpi_technical_agreements')->restrictOnDelete();
            $t->string('phase', 20);
            $t->string('action', 20);
            $t->string('request_key', 100);
            $t->char('request_hash', 64);
            $t->text('reason_ciphertext');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at', 6);
            $t->unique(['agreement_id', 'phase']);
            $t->unique(['provider_id', 'request_key']);
        });
        foreach (['ocpi_technical_agreements', 'ocpi_technical_agreement_events'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($operation)." BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'OCPI agreement history is immutable'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE FUNCTION {$table}_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'OCPI agreement history is immutable'; END $$");
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION {$table}_immutable()");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ocpi_technical_agreement_events');
        Schema::dropIfExists('ocpi_technical_agreement_heads');
        Schema::dropIfExists('ocpi_technical_agreements');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS ocpi_technical_agreements_immutable(); DROP FUNCTION IF EXISTS ocpi_technical_agreement_events_immutable()');
        }
    }
};
