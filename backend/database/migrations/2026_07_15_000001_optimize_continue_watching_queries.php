<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episode_watches', function (Blueprint $table): void {
            $table->index(['user_id', 'episode_id'], 'episode_watches_user_episode_idx');
            $table->index(['user_id', 'show_id', 'watched_at'], 'episode_watches_user_show_watched_idx');
        });

        Schema::table('episodes', function (Blueprint $table): void {
            $table->index(['user_id', 'show_id', 'season_number', 'episode_number'], 'episodes_user_show_order_idx');
        });
    }

    public function down(): void
    {
        Schema::table('episode_watches', function (Blueprint $table): void {
            $table->dropIndex('episode_watches_user_episode_idx');
            $table->dropIndex('episode_watches_user_show_watched_idx');
        });

        Schema::table('episodes', function (Blueprint $table): void {
            $table->dropIndex('episodes_user_show_order_idx');
        });
    }
};
