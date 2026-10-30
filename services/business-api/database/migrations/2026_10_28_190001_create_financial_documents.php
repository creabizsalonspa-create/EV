<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_profile_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_id')->nullable()->constrained('users');
            $t->string('scope_key', 80);
            $t->unsignedInteger('version');
            $t->string('country', 2);
            $t->string('currency', 3);
            $t->unsignedTinyInteger('minor_units');
            $t->string('legal_name', 160);
            $t->string('legal_name_ar', 160);
            $t->string('address', 500);
            $t->string('address_ar', 500);
            $t->string('tax_registration', 80)->nullable();
            $t->timestamp('effective_from');
            $t->foreignId('created_by')->constrained('users');
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->timestamp('created_at');
            $t->unique(['scope_key', 'version']);
        });
        Schema::create('financial_document_sequences', function (Blueprint $t) {
            $t->string('sequence_key', 100)->primary();
            $t->unsignedBigInteger('next_number');
        });
        Schema::create('financial_documents', function (Blueprint $t) {
            $t->id();
            $t->string('number', 100)->unique();
            $t->string('source_kind', 32);
            $t->unsignedBigInteger('source_id');
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('vendor_id')->nullable()->constrained('users');
            $t->foreignId('issuer_profile_id')->constrained('financial_profile_versions');
            $t->string('locale', 2);
            $t->string('template_version', 40);
            $t->longText('encrypted_snapshot');
            $t->string('snapshot_sha256', 64);
            $t->string('pdf_sha256', 64);
            $t->string('csv_sha256', 64);
            $t->string('pdf_path');
            $t->string('csv_path');
            $t->foreignId('generated_by')->constrained('users');
            $t->timestamp('created_at');
            $t->unique(['source_kind', 'source_id', 'locale', 'template_version'], 'financial_document_source_version');
        });
        foreach (['financial_profile_versions', 'financial_documents'] as $table) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($event)." BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'Financial document snapshots are immutable'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()");
            } else {
                throw new RuntimeException('Financial documents require SQLite or PostgreSQL.');
            }
        }
    }

    public function down(): void
    {
        if (! app()->environment('testing') && (DB::table('financial_documents')->exists() || DB::table('financial_profile_versions')->exists())) {
            throw new RuntimeException('Preserve immutable financial document and issuer history before downgrade.');
        }
        Schema::dropIfExists('financial_documents');
        Schema::dropIfExists('financial_document_sequences');
        Schema::dropIfExists('financial_profile_versions');
    }
};
