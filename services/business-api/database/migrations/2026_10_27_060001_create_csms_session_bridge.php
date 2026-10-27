<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csms_session_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('charging_session_id')->unique()->constrained();
            $t->foreignId('binding_id')->constrained('csms_bindings');
            $t->foreignId('start_command_id')->constrained('charger_commands');
            $t->foreignId('stop_command_id')->nullable()->constrained('charger_commands');
            $t->string('transaction_id', 36)->nullable();
            $t->string('protocol');
            $t->string('identifier');
            $t->bigInteger('last_sequence')->default(-1);
            $t->unsignedBigInteger('meter_start_wh')->nullable();
            $t->unsignedBigInteger('last_meter_wh')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->timestamp('last_event_at')->nullable();
            $t->timestamps();
            $t->unique(['binding_id', 'transaction_id']);
        });
        Schema::create('csms_inbox_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('link_id')->constrained('csms_session_links');
            $t->string('event_id', 100)->unique();
            $t->string('fingerprint', 64);
            $t->string('type');
            $t->unsignedBigInteger('sequence');
            $t->unsignedBigInteger('meter_wh');
            $t->timestamp('occurred_at');
            $t->string('outcome');
            $t->timestamp('created_at');
            $t->unique(['link_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csms_inbox_events');
        Schema::dropIfExists('csms_session_links');
    }
};
