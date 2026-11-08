<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD 8 push_subscriptions — a browser's Web Push endpoint plus its
 * elliptic-curve keys. `endpoint` is globally unique (one row per browser
 * registration): re-subscribing under another account moves the row, and
 * a re-subscription with fresh keys updates it in place.
 *
 * 750 chars keeps the unique index inside MySQL's 3072-byte utf8mb4 limit
 * (750 × 4 = 3000). Dead endpoints are pruned when the push service
 * answers 404/410 (PRD 4.10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('endpoint', 750)->unique();
            $table->string('p256dh', 255);
            $table->string('auth', 255);
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
