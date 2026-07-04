<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playback_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('provider_type')->index();
            $table->string('status', 32)->default('active')->index();
            $table->json('metadata')->nullable();
            $table->text('settings')->nullable();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'provider_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playback_sources');
    }
};
