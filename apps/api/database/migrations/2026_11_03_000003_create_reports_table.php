<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD 4.12/8: reports are filed against a user (optionally a specific
 * message) inside a room and sit in a simple admin queue
 * (open → reviewed | dismissed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users');
            $table->foreignId('target_user_id')->constrained('users');
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 500);
            $table->string('status', 16)->default('open');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
