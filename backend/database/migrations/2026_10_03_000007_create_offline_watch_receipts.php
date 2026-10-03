<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_watch_receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->uuid('client_id');
            $t->string('request_hash', 64);
            $t->string('media_type', 12);
            $t->unsignedBigInteger('watch_id');
            $t->timestamps();
            $t->unique(['user_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_watch_receipts');
    }
};
