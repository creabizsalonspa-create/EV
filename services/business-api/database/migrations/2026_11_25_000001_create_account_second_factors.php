<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_second_factors', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->text('secret')->nullable();
            $table->text('recovery_hashes')->nullable();
            $table->bigInteger('last_counter')->nullable();
            $table->text('pending_secret')->nullable();
            $table->timestamp('pending_until')->nullable();
            $table->unsignedBigInteger('pending_epoch')->nullable();
            $table->timestamps();
            $table->index('pending_until');
        });
    }

    public function down(): void
    {
        if (DB::table('account_second_factors')->exists()) {
            throw new RuntimeException('Remove authenticator records through the verified account workflow before rollback.');
        }
        Schema::dropIfExists('account_second_factors');
    }
};
