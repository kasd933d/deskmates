<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 60);
            $table->string('type', 16); // always_on | public | private
            $table->foreignId('host_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invite_code', 12)->nullable()->unique();
            $table->string('theme', 32)->default('default');
            $table->unsignedSmallInteger('max_members')->default(25);
            $table->unsignedSmallInteger('focus_minutes')->default(25);
            $table->unsignedSmallInteger('short_break_minutes')->default(5);
            $table->unsignedSmallInteger('long_break_minutes')->default(15);
            $table->unsignedTinyInteger('long_break_every')->default(4);
            $table->boolean('silent_focus')->default(true);
            $table->string('phase', 16)->default('idle'); // idle | focus | short_break | long_break
            $table->timestamp('phase_started_at')->nullable();
            $table->timestamp('phase_ends_at')->nullable();
            $table->unsignedInteger('cycle_index')->default(0);
            $table->unsignedBigInteger('phase_version')->default(0);
            $table->unsignedBigInteger('anchor_epoch')->nullable(); // always-on only
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'archived_at']);
            $table->index('phase_ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
