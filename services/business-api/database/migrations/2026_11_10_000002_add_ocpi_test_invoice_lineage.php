<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Partner sessions use canonical charging/finance records without inventing
        // a local reservation, station, capacity claim or second financial ledger.
        $this->preserveSqliteDefinitions(fn () => Schema::table('charging_sessions', function (Blueprint $t) {
            $t->foreignId('reservation_id')->nullable()->change();
            $t->foreignId('roaming_order_id')->nullable()->unique()->constrained('ocpi_roaming_orders')->restrictOnDelete();
        }));
        Schema::create('ocpi_test_invoice_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->unique()->constrained('ocpi_roaming_orders')->restrictOnDelete();
            $t->foreignId('charging_session_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('invoice_id')->unique()->constrained('finance_invoices')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users');
            $t->uuid('request_key');
            $t->string('intent_hash', 64);
            $t->string('source_session_hash', 64);
            $t->string('source_cdr_hash', 64);
            $t->foreignId('source_session_record_id')->constrained('ocpi_records')->restrictOnDelete();
            $t->foreignId('source_cdr_record_id')->constrained('ocpi_records')->restrictOnDelete();
            $t->string('policy_hash', 64);
            $t->text('snapshot_ciphertext');
            $t->timestamp('created_at');
            $t->unique(['actor_id', 'request_key']);
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::statement('CREATE TRIGGER charging_source_'.strtolower($operation)." BEFORE $operation ON charging_sessions WHEN (NEW.reservation_id IS NULL) = (NEW.roaming_order_id IS NULL) BEGIN SELECT RAISE(ABORT, 'Exactly one charging origin is required'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE charging_sessions ADD CONSTRAINT charging_source_exclusive CHECK ((reservation_id IS NULL) <> (roaming_order_id IS NULL))');
        }
    }

    public function down(): void
    {
        if (! app()->environment('testing') && (DB::table('ocpi_test_invoice_receipts')->exists() || DB::table('charging_sessions')->whereNotNull('roaming_order_id')->exists())) {
            throw new RuntimeException('Consented invoice authority and ledger history require a retention decision.');
        }
        Schema::dropIfExists('ocpi_test_invoice_receipts');
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS charging_source_insert');
            DB::statement('DROP TRIGGER IF EXISTS charging_source_update');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE charging_sessions DROP CONSTRAINT IF EXISTS charging_source_exclusive');
        }
        Schema::table('charging_sessions', fn (Blueprint $t) => $t->dropUnique(['roaming_order_id']));
        $this->preserveSqliteDefinitions(fn () => Schema::table('charging_sessions', fn (Blueprint $t) => $t->dropConstrainedForeignId('roaming_order_id')));
        // Used roaming sessions must be retained; no downgrade fabricates reservations.
        if (! DB::table('charging_sessions')->whereNull('reservation_id')->exists()) {
            $this->preserveSqliteDefinitions(fn () => Schema::table('charging_sessions', fn (Blueprint $t) => $t->foreignId('reservation_id')->nullable(false)->change()));
        }
    }

    /** SQLite rebuilds must retain partial uniqueness and existing triggers exactly. */
    private function preserveSqliteDefinitions(callable $change): void
    {
        $definitions = DB::getDriverName() === 'sqlite'
            ? DB::select("SELECT name, type, sql FROM sqlite_master WHERE tbl_name = 'charging_sessions' AND type IN ('index','trigger') AND sql IS NOT NULL")
            : [];
        $change();
        foreach ($definitions as $definition) {
            $name = '"'.str_replace('"', '""', $definition->name).'"';
            DB::statement('DROP '.strtoupper($definition->type).' IF EXISTS '.$name);
            DB::statement($definition->sql);
        }
    }
};
