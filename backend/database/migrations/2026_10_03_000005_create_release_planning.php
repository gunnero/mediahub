<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('timezone', 64)->default('UTC'));
        Schema::create('experience_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('timezone', 64)->default('UTC');
            $table->string('quiet_start', 5)->nullable();
            $table->string('quiet_end', 5)->nullable();
            $table->boolean('weekly_digest')->default(false);
            $table->timestamp('last_digest_at')->nullable();
            $table->boolean('share_activity')->default(false);
            $table->timestamp('activity_since')->nullable();
            $table->string('calendar_token_hash', 64)->nullable()->unique();
            $table->timestamps();
        });
        Schema::create('release_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('media_type', 12);
            $table->unsignedBigInteger('media_id');
            $table->timestamp('remind_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'remind_at']);
        });
        Schema::create('web_push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint_hash', 64)->unique();
            $table->text('subscription');
            $table->unsignedBigInteger('last_alert_id')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('timezone'));
        Schema::dropIfExists('web_push_subscriptions');
        Schema::dropIfExists('release_reminders');
        Schema::dropIfExists('experience_settings');
    }
};
