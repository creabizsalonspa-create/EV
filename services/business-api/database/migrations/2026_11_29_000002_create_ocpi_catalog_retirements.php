<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocpi_record_receipts', fn (Blueprint $t) => $t->string('method', 6)->change());
        Schema::create('ocpi_catalog_retirements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('record_id')->constrained('ocpi_records')->restrictOnDelete();
            $t->char('content_hash', 64);
            $t->unsignedInteger('credential_generation');
            $t->text('payload_encrypted');
            $t->timestamp('source_updated_at', 6);
            $t->timestamp('retired_at', 6);
            $t->unique(['record_id', 'content_hash']);
            $t->index(['record_id', 'id'], 'ocpi_catalog_retirement_history');
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared('CREATE TRIGGER ocpi_catalog_retirement_'.strtolower($operation)." BEFORE {$operation} ON ocpi_catalog_retirements BEGIN SELECT RAISE(ABORT, 'Catalog retirement receipts are immutable'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION reject_ocpi_catalog_retirement_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Catalog retirement receipts are immutable'; END $$; CREATE TRIGGER ocpi_catalog_retirement_immutable BEFORE UPDATE OR DELETE ON ocpi_catalog_retirements FOR EACH ROW EXECUTE FUNCTION reject_ocpi_catalog_retirement_mutation()");
        }
    }

    public function down(): void
    {
        if (DB::table('ocpi_catalog_retirements')->exists() || DB::table('ocpi_record_receipts')->where('method', 'DELETE')->exists()) {
            throw new RuntimeException('Preserve partner catalog withdrawal evidence.');
        }
        Schema::dropIfExists('ocpi_catalog_retirements');
        Schema::table('ocpi_record_receipts', fn (Blueprint $t) => $t->string('method', 5)->change());
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS reject_ocpi_catalog_retirement_mutation()');
        }
    }
};
