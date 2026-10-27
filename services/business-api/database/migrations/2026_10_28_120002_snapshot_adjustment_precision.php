<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Validate every legacy row before changing schema or removing its guard.
        $backfill = [];
        foreach (DB::table('commercial_adjustments')->get() as $row) {
            $configured = DB::table('regions')->where('currency', $row->currency)->pluck('minor_units');
            $units = $configured->merge(DB::table('finance_invoices')->where('vendor_id', $row->vendor_id)->where('currency', $row->currency)->pluck('minor_units'))
                ->merge(DB::table('commercial_settlements')->where('vendor_id', $row->vendor_id)->where('currency', $row->currency)->pluck('minor_units'))->map(fn ($v) => (int) $v)->unique();
            if ($configured->isEmpty() || $units->count() !== 1 || $units->first() < 0 || $units->first() > 3) {
                throw new RuntimeException('Reconcile historical adjustment currency precision before applying migration 120002.');
            }
            $backfill[(int) $row->id] = $units->first();
        }
        Schema::table('commercial_adjustments', fn (Blueprint $t) => $t->unsignedTinyInteger('minor_units')->nullable());
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER commercial_adjustments_no_update');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER commercial_adjustments_immutable ON commercial_adjustments');
        }
        foreach ($backfill as $id => $units) {
            DB::table('commercial_adjustments')->where('id', $id)->update(['minor_units' => $units]);
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER commercial_adjustments_no_update BEFORE UPDATE ON commercial_adjustments BEGIN SELECT RAISE(ABORT, 'Settlement records are immutable'); END");
            DB::unprepared("CREATE TRIGGER commercial_adjustments_precision BEFORE INSERT ON commercial_adjustments WHEN NEW.minor_units IS NULL OR NEW.minor_units < 0 OR NEW.minor_units > 3 BEGIN SELECT RAISE(ABORT, 'Adjustment precision is required'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE commercial_adjustments ALTER COLUMN minor_units SET NOT NULL');
            DB::unprepared('ALTER TABLE commercial_adjustments ADD CONSTRAINT commercial_adjustments_precision CHECK (minor_units BETWEEN 0 AND 3)');
            DB::unprepared('CREATE TRIGGER commercial_adjustments_immutable BEFORE UPDATE OR DELETE ON commercial_adjustments FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS commercial_adjustments_precision');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE commercial_adjustments DROP CONSTRAINT IF EXISTS commercial_adjustments_precision');
        }
        Schema::table('commercial_adjustments', fn (Blueprint $t) => $t->dropColumn('minor_units'));
    }
};
