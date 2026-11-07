<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_request_expirations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reservation_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('charging_session_id')->unique()->constrained()->restrictOnDelete();
            $t->string('reason', 80);
            $t->string('initiator', 40);
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charging_request_expirations');
    }
};
