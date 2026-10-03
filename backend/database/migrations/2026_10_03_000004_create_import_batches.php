<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('preview');
            $table->longText('payload');
            $table->longText('backup')->nullable();
            $table->json('created_rows')->nullable();
            $table->json('summary');
            $table->string('before_hash', 64);
            $table->string('after_hash', 64)->nullable();
            $table->string('backup_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
