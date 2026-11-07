<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charging_reviews', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('station_id')->constrained();
            $t->foreignId('vendor_id')->constrained('users');
            $t->foreignId('charging_session_id')->unique()->constrained();
            $t->string('evidence');
            $t->string('source_fingerprint', 64);
            $t->string('state')->default('pending');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->unique(['user_id', 'station_id']);
            $t->index(['station_id', 'state']);
        });
        Schema::create('charging_review_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('review_id')->constrained('charging_reviews');
            $t->foreignId('actor_id')->constrained('users');
            $t->uuid('request_key');
            $t->string('intent_hash', 64);
            $t->unsignedInteger('version');
            $t->unsignedInteger('revision');
            $t->string('action');
            $t->unsignedTinyInteger('rating')->nullable();
            $t->text('body_ciphertext')->nullable();
            $t->text('reason_ciphertext')->nullable();
            $t->timestamp('created_at');
            $t->unique(['actor_id', 'request_key']);
            $t->unique(['review_id', 'version']);
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('charging_reviews')->exists()) {
            throw new RuntimeException('Review history requires a retention decision.');
        }
        Schema::dropIfExists('charging_review_events');
        Schema::dropIfExists('charging_reviews');
    }
};
