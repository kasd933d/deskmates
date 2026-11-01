<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_emoji', 8)->default('🧑‍💻')->after('password');
            $table->string('avatar_color', 7)->default('#6366f1')->after('avatar_emoji');
            $table->string('timezone', 64)->default('UTC')->after('avatar_color');
            $table->string('role', 16)->default('user')->after('timezone');
            $table->boolean('is_suspended')->default(false)->after('role');
            $table->boolean('show_on_leaderboard')->default(true)->after('is_suspended');
            $table->unsignedInteger('current_streak')->default(0)->after('show_on_leaderboard');
            $table->unsignedInteger('longest_streak')->default(0)->after('current_streak');
            $table->date('last_focus_date')->nullable()->after('longest_streak');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'avatar_emoji', 'avatar_color', 'timezone', 'role', 'is_suspended',
                'show_on_leaderboard', 'current_streak', 'longest_streak', 'last_focus_date',
            ]);
        });
    }
};
