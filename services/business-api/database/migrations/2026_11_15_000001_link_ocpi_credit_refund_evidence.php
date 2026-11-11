<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Native ADD avoids SQLite table rebuilds and preserves existing indexes.
        DB::statement('ALTER TABLE finance_refund_requests ADD COLUMN ocpi_credit_record_id BIGINT NULL REFERENCES ocpi_records(id) ON DELETE RESTRICT');
        DB::statement('CREATE UNIQUE INDEX refund_credit_source_unique ON finance_refund_requests (ocpi_credit_record_id)');
    }

    public function down(): void
    {
        if (DB::table('finance_refund_requests')->whereNotNull('ocpi_credit_record_id')->exists()) {
            throw new RuntimeException('Retain linked credit review history.');
        }
        DB::statement('DROP INDEX refund_credit_source_unique');
        DB::statement('ALTER TABLE finance_refund_requests DROP COLUMN ocpi_credit_record_id');
    }
};
