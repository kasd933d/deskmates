<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD 8 phase_attendance — seconds a member was provably present during a
 * focus phase, accrued per heartbeat (each beat adds the elapsed time since
 * the previous beat, capped at 45 s). Converted to credited minutes when the
 * phase ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phase_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('phase_id')->constrained('room_phases')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('seconds_present')->default(0);
            $table->timestamps();

            $table->unique(['phase_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phase_attendance');
    }
};
