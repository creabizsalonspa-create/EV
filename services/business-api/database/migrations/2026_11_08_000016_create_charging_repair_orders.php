<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_repair_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('alert_id')->unique()->constrained('charging_fault_alerts')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('state', 24)->default('open');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('charging_repair_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('charging_repair_orders')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key');
            $t->char('payload_hash', 64);
            $t->string('state', 24);
            $t->unsignedInteger('version');
            $t->text('note_ciphertext');
            $t->timestamp('created_at');
            $t->unique(['actor_id', 'request_key']);
            $t->unique(['order_id', 'version']);
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('charging_repair_orders')->exists()) {
            throw new RuntimeException('Preserve repair history with a forward migration.');
        }
        Schema::dropIfExists('charging_repair_events');
        Schema::dropIfExists('charging_repair_orders');
    }
};
