<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('intention', 80)->nullable();
            $table->string('status', 16)->default('break'); // focusing | break | away
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_muted')->default(false);
            $table->timestamps();

            $table->unique('user_id'); // one active room per user
            $table->index(['room_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_members');
    }
};
