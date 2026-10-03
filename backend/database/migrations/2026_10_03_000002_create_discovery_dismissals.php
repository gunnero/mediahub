<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_dismissals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('media_type', 12);
            $table->unsignedBigInteger('tmdb_id');
            $table->timestamps();
            $table->unique(['user_id', 'media_type', 'tmdb_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_dismissals');
    }
};
