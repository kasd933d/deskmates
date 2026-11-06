<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRD 8 user_daily_focus — per-user daily rollup of credited focus, written
 * atomically alongside each focus_session and rebuilt nightly by
 * RollupDailyFocus. Drives today/week/all-time totals, the heatmap and
 * streaks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_daily_focus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('minutes')->default(0);
            $table->unsignedInteger('sessions')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_daily_focus');
    }
};
