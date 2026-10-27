<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_company_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('vendor_companies');
            $t->foreignId('reviewer_id')->constrained('users');
            $t->string('from_status');
            $t->string('to_status');
            $t->text('reason');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_company_reviews');
    }
};
