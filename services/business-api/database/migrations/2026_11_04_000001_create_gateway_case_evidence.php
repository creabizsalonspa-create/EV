<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_case_evidence', function (Blueprint $t) {
            $t->id();
            $t->foreignId('case_id')->constrained('gateway_cases');
            $t->foreignId('uploaded_by')->constrained('users');
            $t->string('kind', 32);
            $t->string('mime_type', 40);
            $t->unsignedInteger('size_bytes');
            $t->string('sha256', 64);
            $t->string('storage_path')->unique();
            $t->string('request_key', 64)->unique();
            $t->string('fingerprint', 64);
            $t->timestamp('created_at');
            $t->index(['case_id', 'id']);
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $event) {
                DB::unprepared('CREATE TRIGGER gateway_case_evidence_no_'.strtolower($event)." BEFORE {$event} ON gateway_case_evidence BEGIN SELECT RAISE(ABORT, 'Case evidence is immutable'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER gateway_case_evidence_immutable BEFORE UPDATE OR DELETE ON gateway_case_evidence FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('gateway_case_evidence') && DB::table('gateway_case_evidence')->exists()) {
            throw new RuntimeException('Preserve private case evidence and its audit history before downgrade.');
        }
        Schema::dropIfExists('gateway_case_evidence');
    }
};
