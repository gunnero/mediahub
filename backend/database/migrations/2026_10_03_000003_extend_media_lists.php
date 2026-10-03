<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_lists', function (Blueprint $table): void {
            $table->json('rules')->nullable();
            $table->string('cover_style', 20)->default('gold');
            $table->string('share_token_hash', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('media_lists', fn (Blueprint $table) => $table->dropColumn(['rules', 'cover_style', 'share_token_hash']));
    }
};
