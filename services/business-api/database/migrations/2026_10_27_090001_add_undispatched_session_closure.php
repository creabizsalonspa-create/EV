<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('csms_session_links', function (Blueprint $t) {
            $t->timestamp('closed_without_dispatch_at')->nullable();
            $t->string('closure_reason', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('csms_session_links', fn (Blueprint $t) => $t->dropColumn(['closed_without_dispatch_at', 'closure_reason']));
    }
};
