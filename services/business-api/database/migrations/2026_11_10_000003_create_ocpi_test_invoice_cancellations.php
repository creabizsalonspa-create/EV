<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocpi_test_invoice_cancellations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('ocpi_roaming_orders')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users');
            $t->uuid('request_key');
            $t->string('intent_hash', 64);
            $t->timestamp('created_at');
            $t->unique(['actor_id', 'request_key']);
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('ocpi_test_invoice_cancellations')->exists()) {
            throw new RuntimeException('Cancelled financial consent identities must be retained.');
        }
        Schema::dropIfExists('ocpi_test_invoice_cancellations');
    }
};
