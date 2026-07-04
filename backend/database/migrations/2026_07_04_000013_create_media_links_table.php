<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playback_source_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('movie_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('show_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('episode_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('linked_at')->index();
            $table->timestamps();

            $table->unique(['user_id', 'playback_source_item_id']);
            $table->index(['user_id', 'movie_id']);
            $table->index(['user_id', 'show_id']);
            $table->index(['user_id', 'episode_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_links');
    }
};
