<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared('CREATE TRIGGER charger_admission_events_no_'.strtolower($event)." BEFORE {$event} ON charger_admission_events BEGIN SELECT RAISE(ABORT,'Charger admission history is immutable'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION charger_admission_reject_mutation() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Charger admission history is immutable'; END; \$\$");
            DB::unprepared('CREATE TRIGGER charger_admission_events_immutable BEFORE UPDATE OR DELETE ON charger_admission_events FOR EACH ROW EXECUTE FUNCTION charger_admission_reject_mutation()');
        }
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('charger_admission_events')->exists()) {
            throw new RuntimeException('Preserve charger admission audit safeguards before downgrade.');
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach (['update', 'delete'] as $event) {
                DB::unprepared('DROP TRIGGER IF EXISTS charger_admission_events_no_'.$event);
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS charger_admission_events_immutable ON charger_admission_events');
            DB::unprepared('DROP FUNCTION IF EXISTS charger_admission_reject_mutation()');
        }
    }
};
