<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playback_source_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playback_source_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('kind', 32)->index();
            $table->string('title');
            $table->string('status', 32)->default('available')->index();
            $table->text('stream_url')->nullable();
            $table->string('stream_url_hash', 64)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['user_id', 'playback_source_id', 'external_id']);
            $table->index(['user_id', 'kind']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playback_source_items');
    }
};
