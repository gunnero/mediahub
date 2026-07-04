<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playback_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playback_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playback_source_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_link_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('playing')->index();
            $table->timestamp('started_at')->index();
            $table->timestamp('ended_at')->nullable()->index();
            $table->unsignedInteger('last_position_seconds')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'playback_source_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playback_sessions');
    }
};
