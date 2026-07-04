<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('media_type', 32)->index();
            $table->unsignedBigInteger('media_id')->index();
            $table->unsignedTinyInteger('rating');
            $table->timestamps();

            $table->unique(['user_id', 'media_type', 'media_id']);
            $table->index(['user_id', 'media_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
