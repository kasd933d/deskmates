<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD 8 focus_sessions — one credited focus block per (user, phase). FKs are
 * null-on-delete so crediting history survives a room or phase row being
 * removed (stats must not regress). `outcome` holds the break-start check-in
 * answer (PRD 4.5); `local_date` is the phase-end date in the user's own
 * timezone and buckets every daily rollup and streak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('focus_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('phase_id')->nullable()->constrained('room_phases')->nullOnDelete();
            $table->unsignedSmallInteger('minutes');
            $table->string('intention', 80)->nullable();
            $table->string('outcome', 8)->nullable(); // done | partly | no
            $table->date('local_date');
            $table->timestamps();

            $table->unique(['user_id', 'phase_id']);
            $table->index(['user_id', 'local_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('focus_sessions');
    }
};
