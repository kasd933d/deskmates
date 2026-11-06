<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD 8 room_phases — phase history. Rows are created eagerly on every
 * user-room transition and lazily (focus windows only) by heartbeats and
 * always-on flip detection. `credited_at` marks the end of the credit
 * pipeline for that phase (PRD 4.8: breaks are recorded but never credited).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->string('type', 16); // focus | short_break | long_break
            $table->dateTime('started_at');
            $table->dateTime('ends_at');
            $table->unsignedInteger('cycle_index')->default(0);
            $table->dateTime('credited_at')->nullable();
            $table->timestamps();

            // One row per (room, window start, type): heartbeats and flip
            // detection race to create the same window. Supersedes the
            // PRD's (room_id, started_at) index — a unique prefix serves
            // lookups too.
            $table->unique(['room_id', 'started_at', 'type']);
            // Credit query: credited_at IS NULL AND ends_at <= now.
            $table->index(['credited_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_phases');
    }
};
