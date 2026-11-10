<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_authorization_events', function (Blueprint $table) {
            $table->index(['token_id', 'id'], 'rider_events_token_history');
            $table->index(['grant_id', 'id'], 'rider_events_grant_history');
        });
        Schema::table('csms_session_links', function (Blueprint $table) {
            $table->index(['rider_token_id', 'id'], 'rider_sessions_token_history');
            $table->index(['rider_grant_id', 'id'], 'rider_sessions_grant_history');
        });
    }

    public function down(): void
    {
        Schema::table('csms_session_links', function (Blueprint $table) {
            $table->dropIndex('rider_sessions_token_history');
            $table->dropIndex('rider_sessions_grant_history');
        });
        Schema::table('rider_authorization_events', function (Blueprint $table) {
            $table->dropIndex('rider_events_token_history');
            $table->dropIndex('rider_events_grant_history');
        });
    }
};
