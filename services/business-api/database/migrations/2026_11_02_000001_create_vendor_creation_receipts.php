<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_company_staff', function (Blueprint $t) {
            $t->unsignedInteger('invitation_version')->default(1);
            $t->timestamp('accepted_at')->nullable();
        });
        Schema::create('vendor_creation_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('request_key', 64)->unique();
            $t->string('scope', 100);
            $t->string('fingerprint', 64);
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->timestamp('created_at');
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER vendor_creation_no_update BEFORE UPDATE ON vendor_creation_receipts WHEN OLD.entity_id IS NOT NULL BEGIN SELECT RAISE(ABORT,'Vendor creation history is immutable'); END");
            DB::unprepared("CREATE TRIGGER vendor_creation_no_delete BEFORE DELETE ON vendor_creation_receipts BEGIN SELECT RAISE(ABORT,'Vendor creation history is immutable'); END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION vendor_creation_reject_mutation() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP='DELETE' OR OLD.entity_id IS NOT NULL THEN RAISE EXCEPTION 'Vendor creation history is immutable'; END IF; RETURN NEW; END; \$\$");
            DB::unprepared('CREATE TRIGGER vendor_creation_immutable BEFORE UPDATE OR DELETE ON vendor_creation_receipts FOR EACH ROW EXECUTE FUNCTION vendor_creation_reject_mutation()');
        }
    }

    public function down(): void
    {
        if (! app()->environment('testing') && (DB::table('vendor_creation_receipts')->exists() || DB::table('vendor_company_staff')->where('invitation_version', '>', 1)->orWhereNotNull('accepted_at')->exists())) {
            throw new RuntimeException('Preserve vendor creation receipts before downgrade.');
        }
        Schema::dropIfExists('vendor_creation_receipts');
        Schema::table('vendor_company_staff', fn (Blueprint $t) => $t->dropColumn(['invitation_version', 'accepted_at']));
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS vendor_creation_reject_mutation()');
        }
    }
};
