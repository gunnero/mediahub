<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movie_nights', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->timestamps();
        });
        Schema::create('movie_night_members', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('movie_night_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->boolean('accepted')->default(false);
            $t->timestamps();
            $t->unique(['movie_night_id', 'user_id']);
        });
        Schema::create('movie_night_titles', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('movie_night_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('media_type', 12);
            $t->unsignedBigInteger('tmdb_id')->nullable();
            $t->string('title');
            $t->string('year', 4)->nullable();
            $t->text('poster')->nullable();
            $t->timestamps();
        });
        Schema::create('movie_night_votes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('movie_night_title_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['movie_night_title_id', 'user_id']);
        });
        Schema::create('friend_recommendations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $t->string('title');
            $t->string('media_type', 12);
            $t->unsignedBigInteger('tmdb_id')->nullable();
            $t->text('message')->nullable();
            $t->boolean('spoiler')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['friend_recommendations', 'movie_night_votes', 'movie_night_titles', 'movie_night_members', 'movie_nights'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
