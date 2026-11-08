<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD 8 scheduled_sessions — a host books a session (title, start time,
 * timer config) for a public or private room; at start time the command
 * applies `config` to the room and starts its timer (PRD 4.11/7.3).
 *
 * `config` is the four timer keys as JSON (PRD 8). `reminded_at` is a M6
 * extension beyond the PRD table list: it is the idempotency marker for
 * the 10-minute push reminder so the every-minute sweep sends exactly
 * once (decision logged in docs/decisions.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            // The scheduling host may delete their account; the booking
            // survives for everyone who RSVP'd (cancel needs the room host).
            $table->foreignId('host_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 80);
            $table->dateTime('starts_at');
            $table->json('config');
            $table->string('status', 16)->default('scheduled'); // scheduled | started | cancelled
            $table->dateTime('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_sessions');
    }
};
