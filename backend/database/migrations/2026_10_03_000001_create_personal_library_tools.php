<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('media_type', 12);
            $table->unsignedBigInteger('watch_id');
            $table->json('before');
            $table->json('after')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'media_type', 'watch_id']);
        });
        Schema::create('media_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('media_type', 12);
            $table->unsignedBigInteger('media_id');
            $table->boolean('pinned')->default(false);
            $table->string('status', 16)->default('active');
            $table->json('tags')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'media_type', 'media_id']);
        });
        Schema::create('saved_library_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('media_type', 12);
            $table->json('filters');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_library_views');
        Schema::dropIfExists('media_preferences');
        Schema::dropIfExists('watch_revisions');
    }
};
