<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MediaDataHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MediaDataHealthReportCommand extends Command
{
    protected $signature = 'mediahub:data-health {user_id}';

    protected $description = 'Generate the aggregate ReelAtlas canonical data health report.';

    public function handle(MediaDataHealthService $health): int
    {
        $user = User::find($this->argument('user_id'));
        if (! $user) {
            $this->error('Data health report failed: user was not found.');

            return self::FAILURE;
        }

        $report = $health->analyze($user);
        $path = dirname(base_path()).'/docs/mediahub/MEDIA_DATA_HEALTH_REPORT.md';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $health->markdown($report));

        $this->line('report: docs/mediahub/MEDIA_DATA_HEALTH_REPORT.md');
        $this->line('movies total: '.$report['coverage']['movies']['total']);
        $this->line('shows total: '.$report['coverage']['shows']['total']);
        $this->line('episodes total: '.$report['coverage']['episodes']['total']);
        $this->line('broken references: '.array_sum($report['broken_references']));

        return self::SUCCESS;
    }
}
