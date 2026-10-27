<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('subject', 150);
            $t->string('status')->default('open');
            $t->timestamps();
        });
        Schema::create('ticket_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('support_ticket_id')->constrained();
            $t->foreignId('author_id')->constrained('users');
            $t->text('body');
            $t->boolean('internal')->default(false);
            $t->timestamps();
        });
        Schema::create('inbox_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('kind');
            $t->foreignId('support_ticket_id')->constrained();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['inbox_notifications', 'ticket_messages', 'support_tickets'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
