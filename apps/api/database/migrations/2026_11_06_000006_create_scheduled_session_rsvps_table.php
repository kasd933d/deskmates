<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD 8 scheduled_session_rsvps — one row per (session, user); the unique
 * pair makes RSVP idempotent at the database level. An RSVP is what puts a
 * user on the 10-minute reminder fan-out (PRD 4.10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_session_rsvps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_session_id')->constrained('scheduled_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['scheduled_session_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_session_rsvps');
    }
};
