<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_agreement_channels', function (Blueprint $t) {
            $t->char('country', 2)->primary();
            $t->uuid('version_id')->nullable();
            $t->unsignedInteger('revision')->default(0);
        });
        Schema::create('vendor_agreement_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->char('country', 2);
            $t->unsignedInteger('revision');
            $t->string('title_en', 200);
            $t->string('title_ar', 200);
            $t->text('body_en');
            $t->text('body_ar');
            $t->string('publication_reference', 100);
            $t->char('sha256', 64);
            $t->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['country', 'revision']);
        });
        Schema::create('vendor_agreement_acceptances', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->constrained('vendor_companies')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->uuid('version_id');
            $t->foreign('version_id')->references('id')->on('vendor_agreement_versions')->restrictOnDelete();
            $t->char('version_sha256', 64);
            $t->text('company_snapshot_encrypted');
            $t->timestamp('created_at');
            $t->unique(['company_id', 'actor_id', 'version_id'], 'vendor_agreement_acceptance_once');
            $t->index(['company_id', 'created_at', 'id'], 'vendor_agreement_acceptance_history');
        });
        foreach (['vendor_agreement_versions', 'vendor_agreement_acceptances'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $operation) {
                    DB::unprepared("CREATE TRIGGER {$table}_".strtolower($operation)." BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Agreement records are immutable'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE OR REPLACE FUNCTION reject_vendor_agreement_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Agreement records are immutable'; END $$; CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_vendor_agreement_mutation()");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('vendor_agreement_versions')->exists() || DB::table('vendor_agreement_acceptances')->exists()) {
            throw new RuntimeException('Preserve published agreement versions and acceptance records.');
        }
        Schema::dropIfExists('vendor_agreement_acceptances');
        Schema::dropIfExists('vendor_agreement_versions');
        Schema::dropIfExists('vendor_agreement_channels');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS reject_vendor_agreement_mutation()');
        }
    }
};
