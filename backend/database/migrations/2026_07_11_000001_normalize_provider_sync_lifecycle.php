<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playback_sources', function (Blueprint $table): void {
            $table->string('sync_status', 32)->default('never_synced')->change();
        });

        DB::table('playback_sources')->where('sync_status', 'idle')->update(['sync_status' => 'never_synced']);
        DB::table('playback_sources')->where('sync_status', 'ready')->update(['sync_status' => 'completed']);
    }

    public function down(): void
    {
        DB::table('playback_sources')->where('sync_status', 'never_synced')->update(['sync_status' => 'idle']);
        DB::table('playback_sources')->whereIn('sync_status', ['completed', 'completed_with_warnings'])->update(['sync_status' => 'ready']);

        Schema::table('playback_sources', function (Blueprint $table): void {
            $table->string('sync_status', 32)->default('idle')->change();
        });
    }
};
