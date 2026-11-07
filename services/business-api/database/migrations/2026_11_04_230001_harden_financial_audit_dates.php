<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $terms = [
            'commercial_settlements' => ['vendor_id', 'currency', 'minor_units', 'amount_minor', 'number', 'request_key', 'created_by', 'created_at', 'evidence'],
            'commercial_payout_accounts' => ['vendor_id', 'provider', 'currency', 'minor_units', 'account_reference', 'bank_reference', 'fingerprint', 'request_key', 'configured_by', 'created_at'],
            'commercial_payouts' => ['reference', 'settlement_id', 'account_id', 'vendor_id', 'provider', 'currency', 'minor_units', 'amount_minor', 'request_key', 'evidence', 'created_at'],
            'commercial_payout_attempts' => ['payout_id', 'number', 'reference', 'retry_until', 'created_at'],
            'loyalty_redemptions' => ['user_id', 'reward_id', 'points_cost', 'request_key', 'created_at'],
        ];
        // Existing malformed audit history needs explicit source reconciliation.
        // Never manufacture a historical creation date during schema migration.
        foreach ($terms as $table => $columns) {
            if (DB::table($table)->whereNull('created_at')->exists()) {
                throw new RuntimeException('Reconcile NULL immutable audit dates in '.$table.' before hardening; historical dates must not be invented.');
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION financial_require_audit_date() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.created_at IS NULL THEN RAISE EXCEPTION 'Financial audit creation date is required'; END IF; RETURN NEW; END; \$\$");
        }
        foreach ($terms as $table => $columns) {
            if (DB::getDriverName() === 'sqlite') {
                $changed = implode(' OR ', array_map(fn ($c) => "NEW.{$c} IS NOT OLD.{$c}", $columns));
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_audit_date_required");
                DB::unprepared("CREATE TRIGGER {$table}_audit_date_required BEFORE INSERT ON {$table} WHEN NEW.created_at IS NULL BEGIN SELECT RAISE(ABORT, 'Financial audit creation date is required'); END");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_terms");
                DB::unprepared("CREATE TRIGGER {$table}_terms BEFORE UPDATE ON {$table} WHEN {$changed} BEGIN SELECT RAISE(ABORT, 'Financial economic terms and audit dates are immutable'); END");
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_audit_date_required ON {$table}");
                DB::unprepared("CREATE TRIGGER {$table}_audit_date_required BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION financial_require_audit_date()");
                $next = implode(', ', array_map(fn ($c) => 'NEW.'.$c, $columns));
                $prior = implode(', ', array_map(fn ($c) => 'OLD.'.$c, $columns));
                $function = $table === 'commercial_settlements' ? 'commercial_settlement_terms' : $table.'_terms';
                DB::unprepared("CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF ROW({$next}) IS DISTINCT FROM ROW({$prior}) THEN RAISE EXCEPTION 'Financial economic terms and audit dates are immutable'; END IF; RETURN NEW; END; \$\$");
            } else {
                throw new RuntimeException('Financial integrity hardening requires SQLite or PostgreSQL.');
            }
        }
    }

    public function down(): void
    {
        // Null-safe guards remain compatible with the preceding application and
        // schema. A rollback must not reintroduce an immutable-history bypass.
        // The original migrations can remove tables only after preservation.
    }
};
