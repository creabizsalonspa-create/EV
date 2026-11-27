<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'pgsql'], true)) {
            throw new RuntimeException('Connector identity history requires SQLite or PostgreSQL.');
        }

        // No foreign key: identity history survives deletion and ID reuse.
        Schema::create('direct_connector_identity_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('connector_id');
            $table->index(['connector_id', 'id'], 'direct_connector_identity_history_index');
        });

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER direct_connector_identity_insert AFTER INSERT ON vendor_connectors
                BEGIN
                    INSERT INTO direct_connector_identity_changes (connector_id) VALUES (NEW.id);
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER direct_connector_identity_update AFTER UPDATE ON vendor_connectors
                WHEN OLD.id IS NOT NEW.id OR OLD.charger_id IS NOT NEW.charger_id
                    OR OLD.connector_number IS NOT NEW.connector_number
                    OR OLD.connector_type IS NOT NEW.connector_type
                    OR OLD.max_power_kw IS NOT NEW.max_power_kw
                BEGIN
                    INSERT INTO direct_connector_identity_changes (connector_id) VALUES (OLD.id);
                    INSERT INTO direct_connector_identity_changes (connector_id)
                        SELECT NEW.id WHERE OLD.id IS NOT NEW.id;
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER direct_connector_identity_delete AFTER DELETE ON vendor_connectors
                BEGIN
                    INSERT INTO direct_connector_identity_changes (connector_id) VALUES (OLD.id);
                END
                SQL);
        } else {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION record_direct_connector_identity_change() RETURNS trigger
                LANGUAGE plpgsql AS $$
                BEGIN
                    IF TG_OP = 'INSERT' THEN
                        INSERT INTO direct_connector_identity_changes (connector_id) VALUES (NEW.id);
                    ELSIF TG_OP = 'DELETE' THEN
                        INSERT INTO direct_connector_identity_changes (connector_id) VALUES (OLD.id);
                    ELSIF (OLD.id, OLD.charger_id, OLD.connector_number, OLD.connector_type, OLD.max_power_kw)
                        IS DISTINCT FROM (NEW.id, NEW.charger_id, NEW.connector_number, NEW.connector_type, NEW.max_power_kw) THEN
                        INSERT INTO direct_connector_identity_changes (connector_id) VALUES (OLD.id);
                        IF OLD.id IS DISTINCT FROM NEW.id THEN
                            INSERT INTO direct_connector_identity_changes (connector_id) VALUES (NEW.id);
                        END IF;
                    END IF;
                    RETURN NULL;
                END;
                $$;
                CREATE TRIGGER direct_connector_identity_change
                    AFTER INSERT OR UPDATE OR DELETE ON vendor_connectors
                    FOR EACH ROW EXECUTE FUNCTION record_direct_connector_identity_change();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::table('direct_connector_identity_changes')->exists()
            || DB::table('direct_connector_review_receipts')->exists()) {
            throw new RuntimeException('Connector identity history must be retained.');
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS direct_connector_identity_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS direct_connector_identity_update');
            DB::unprepared('DROP TRIGGER IF EXISTS direct_connector_identity_delete');
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS direct_connector_identity_change ON vendor_connectors');
            DB::unprepared('DROP FUNCTION IF EXISTS record_direct_connector_identity_change()');
        }
        Schema::dropIfExists('direct_connector_identity_changes');
    }
};
