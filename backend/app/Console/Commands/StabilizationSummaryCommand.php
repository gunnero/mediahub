<?php

namespace App\Console\Commands;

use App\Services\StabilizationSummaryService;
use Illuminate\Console\Command;

class StabilizationSummaryCommand extends Command
{
    protected $signature = 'mediahub:stabilization-summary {--days=7 : Summary window in days}';

    protected $description = 'Summarize privacy-safe ReelAtlas operational monitoring events.';

    public function handle(StabilizationSummaryService $summaries): int
    {
        $days = max(1, min(90, (int) $this->option('days')));
        $summary = $summaries->forDays($days);

        $this->line('window_days: '.$days);
        foreach ($summary as $key => $value) {
            $this->line($key.': '.$value);
        }

        return self::SUCCESS;
    }
}
