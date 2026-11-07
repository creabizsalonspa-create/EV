<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_refund_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('finance_invoices');
            $t->foreignId('user_id')->constrained();
            $t->unsignedBigInteger('amount_minor');
            $t->string('reason', 500);
            $t->string('request_key', 100);
            $t->string('status')->default('pending');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->string('decision_note', 500)->nullable();
            $t->foreignId('refund_id')->nullable()->constrained('finance_refunds');
            $t->timestamps();
            $t->unique(['user_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_refund_requests');
    }
};
