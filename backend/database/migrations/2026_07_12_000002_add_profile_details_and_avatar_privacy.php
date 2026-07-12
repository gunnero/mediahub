<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('full_name', 120)->nullable()->after('display_name');
            $table->json('avatar_variants')->nullable()->after('avatar_path');
            $table->boolean('show_avatar')->default(false)->after('public_profile_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['full_name', 'avatar_variants', 'show_avatar']);
        });
    }
};
