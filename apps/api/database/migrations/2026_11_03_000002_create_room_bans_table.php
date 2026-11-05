<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD 4.12/8: a ban is per room and permanent — the user is kicked (their
 * membership row is removed) and may not rejoin. Checked on join, heartbeat
 * self-heal and channel authorization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_bans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('banned_by')->constrained('users');
            $table->timestamps();

            $table->unique(['room_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_bans');
    }
};
