<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', fn (Blueprint $table) => $table->text('reservation_terms_snapshot')->nullable());
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER reservation_terms_immutable BEFORE UPDATE OF reservation_terms_snapshot ON reservations WHEN OLD.reservation_terms_snapshot IS NOT NEW.reservation_terms_snapshot BEGIN SELECT RAISE(ABORT, 'Reservation terms are immutable'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_reservation_terms_change() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF NEW.reservation_terms_snapshot IS DISTINCT FROM OLD.reservation_terms_snapshot THEN
                        RAISE EXCEPTION 'Reservation terms are immutable';
                    END IF;
                    RETURN NEW;
                END;
                $$;
                CREATE TRIGGER reservation_terms_immutable BEFORE UPDATE ON reservations
                FOR EACH ROW EXECUTE FUNCTION reject_reservation_terms_change();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::table('reservations')->whereNotNull('reservation_terms_snapshot')->exists()) {
            throw new RuntimeException('Preserve reservation terms evidence before rollback.');
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS reservation_terms_immutable');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS reservation_terms_immutable ON reservations; DROP FUNCTION IF EXISTS reject_reservation_terms_change()');
        }
        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn('reservation_terms_snapshot'));
    }
};
