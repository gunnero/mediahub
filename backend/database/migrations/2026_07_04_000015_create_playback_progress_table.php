<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playback_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playback_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('playback_source_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('movie_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('episode_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position_seconds')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('completed')->default(false)->index();
            $table->timestamps();

            $table->unique(['user_id', 'playback_source_item_id']);
            $table->index(['user_id', 'completed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playback_progress');
    }
};
