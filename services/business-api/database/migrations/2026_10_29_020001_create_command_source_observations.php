<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_source_observations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('command_id')->unique()->constrained('charger_commands')->restrictOnDelete();
            $t->foreignId('inbox_event_id')->constrained('csms_inbox_events')->restrictOnDelete();
            $t->string('prior_status');
            $t->string('prior_failure_code')->nullable();
            $t->string('observed_state');
            $t->boolean('command_acceptance_verified')->default(false);
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('command_source_observations')->exists()) {
            throw new RuntimeException('Preserve command source evidence; coordinate code/schema rollback.');
        }
        Schema::dropIfExists('command_source_observations');
    }
};
