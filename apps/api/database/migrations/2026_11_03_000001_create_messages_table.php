<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD 4.6/8: room chat — text only, max 300 characters, last 200 per room
 * kept (the nightly `messages:prune` command enforces the cap). `deleted_at`
 * is a tombstone: host/author/admin deletes soft-delete the row and broadcast
 * MessageDeleted so every client drops it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('body', 300);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['room_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
