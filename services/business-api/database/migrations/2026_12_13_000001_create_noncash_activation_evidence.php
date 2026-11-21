<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->resources() as $table => [$foreign, $parent]) {
            Schema::create($table, function (Blueprint $t) use ($foreign, $parent) {
                $t->id();
                $t->foreignId($foreign)->unique()->constrained($parent);
                $t->string('activation_mode', 30);
                $t->string('approval_reference', 120)->nullable();
                $t->string('terms_sha256', 64)->nullable();
                $t->timestamp('created_at');
            });
            if (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($event)." BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT,'Noncash activation evidence is immutable'); END");
                }
            } elseif (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION finance_reject_mutation()");
            } else {
                throw new RuntimeException('Noncash activation evidence requires PostgreSQL or SQLite.');
            }
        }
    }

    private function resources(): array
    {
        return ['loyalty_coupon_activation_evidence' => ['coupon_id', 'loyalty_coupons'],
            'loyalty_referral_activation_evidence' => ['programme_id', 'loyalty_referral_programmes']];
    }

    public function down(): void
    {
        foreach (array_keys($this->resources()) as $table) {
            if (! app()->environment('testing') && DB::table($table)->exists()) {
                throw new RuntimeException('Preserve noncash activation evidence before downgrade.');
            }
        }
        foreach (array_reverse(array_keys($this->resources())) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
