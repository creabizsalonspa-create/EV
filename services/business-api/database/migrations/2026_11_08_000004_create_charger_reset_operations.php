<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charger_reset_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('binding_id')->constrained('csms_bindings')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('idempotency_key', 100);
            $t->char('payload_hash', 64);
            $t->text('reason');
            $t->string('reset_type', 10);
            $t->string('status', 24)->default('requested');
            $t->timestamp('attempted_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('review_note')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['actor_id', 'idempotency_key']);
            $t->index(['binding_id', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('charger_reset_operations')->exists()) {
            throw new RuntimeException('Preserve operational reset history before rollback.');
        }
        Schema::dropIfExists('charger_reset_operations');
    }
};
