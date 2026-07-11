<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playback_sources', function (Blueprint $table): void {
            $table->string('sync_status', 32)->default('never_synced')->index();
            $table->string('last_sync_error', 64)->nullable();
        });

        Schema::table('playback_source_items', function (Blueprint $table): void {
            $table->string('category')->nullable()->index();
            $table->text('poster_url')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedSmallInteger('release_year')->nullable()->index();
            $table->string('match_status', 32)->default('needs_review')->index();
            $table->boolean('favorite')->default(false)->index();
            $table->timestamp('catalog_synced_at')->nullable()->index();

            $table->index(['user_id', 'kind', 'category']);
            $table->index(['user_id', 'playback_source_id', 'match_status']);
        });
    }

    public function down(): void
    {
        Schema::table('playback_source_items', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'kind', 'category']);
            $table->dropIndex(['user_id', 'playback_source_id', 'match_status']);
            $table->dropColumn([
                'category',
                'poster_url',
                'duration_seconds',
                'release_year',
                'match_status',
                'favorite',
                'catalog_synced_at',
            ]);
        });

        Schema::table('playback_sources', function (Blueprint $table): void {
            $table->dropColumn(['sync_status', 'last_sync_error']);
        });
    }
};
