<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('station_information', fn (Blueprint $t) => $t->boolean('enforce_operating_hours')->default(false));
        Schema::table('reservations', fn (Blueprint $t) => $t->text('operating_calendar_snapshot')->nullable());
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER reservation_calendar_snapshot_immutable BEFORE UPDATE OF operating_calendar_snapshot ON reservations WHEN OLD.operating_calendar_snapshot IS NOT NEW.operating_calendar_snapshot BEGIN SELECT RAISE(ABORT, 'Reservation calendar snapshot is immutable'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_calendar_snapshot_change() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF NEW.operating_calendar_snapshot IS DISTINCT FROM OLD.operating_calendar_snapshot THEN
                        RAISE EXCEPTION 'Reservation calendar snapshot is immutable';
                    END IF;
                    RETURN NEW;
                END;
                $$;
                CREATE TRIGGER reservation_calendar_snapshot_immutable BEFORE UPDATE ON reservations
                FOR EACH ROW EXECUTE FUNCTION reject_calendar_snapshot_change();
                SQL);
        }
    }

    public function down(): void
    {
        $disposable = app()->environment('testing') && ((DB::getDriverName() === 'sqlite' && DB::connection()->getDatabaseName() === ':memory:') || (DB::getDriverName() === 'pgsql' && str_ends_with(DB::connection()->getDatabaseName(), '_review')));
        if (! $disposable && (DB::table('reservations')->whereNotNull('operating_calendar_snapshot')->exists() || DB::table('station_information')->where('enforce_operating_hours', true)->exists())) {
            throw new RuntimeException('Preserve operating calendar policy and booking evidence before rollback.');
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS reservation_calendar_snapshot_immutable');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS reservation_calendar_snapshot_immutable ON reservations; DROP FUNCTION IF EXISTS reject_calendar_snapshot_change()');
        }
        Schema::table('reservations', fn (Blueprint $t) => $t->dropColumn('operating_calendar_snapshot'));
        Schema::table('station_information', fn (Blueprint $t) => $t->dropColumn('enforce_operating_hours'));
    }
};
