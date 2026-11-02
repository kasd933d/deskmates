<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            // PRD 4.4: "Away" appears when the tab has been hidden for over
            // 2 minutes, reported by the heartbeat's `visible` flag. The
            // column records WHEN hiding started so the away threshold can
            // be evaluated server-side at any later moment.
            $table->timestamp('hidden_since')->nullable()->after('last_seen_at');

            // The reaper (PRD 7.3, every 30 s) scans by heartbeat age.
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('hidden_since');
        });
    }
};
