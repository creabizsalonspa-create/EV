<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_notifications', function (Blueprint $table) {
            $table->index(['user_id', 'read_at', 'id'], 'inbox_notification_owner_unread_cursor');
            $table->index(['user_id', 'kind', 'id'], 'inbox_notification_owner_category_cursor');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_notifications', function (Blueprint $table) {
            $table->dropIndex('inbox_notification_owner_category_cursor');
            $table->dropIndex('inbox_notification_owner_unread_cursor');
        });
    }
};
