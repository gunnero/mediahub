<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AlertService;
use App\Services\ReleasePlanningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DeliverReleaseReminders extends Command
{
    protected $signature = 'mediahub:deliver-reminders';

    protected $description = 'Deliver due reminders, weekly digests, and opted-in browser notifications.';

    public function handle(ReleasePlanningService $planning, AlertService $alerts): int
    {
        $failed = 0;
        User::where('status', 'active')->orderBy('id')->each(function ($user) use ($planning, $alerts, &$failed): void {
            try {
                $alerts->syncForUser($user);
                $planning->deliver($user);
            } catch (\Throwable $error) {
                $failed++;
                Log::warning('Reminder delivery failed', ['user_id' => $user->id, 'error' => $error::class]);
            }
        });
        $this->info($failed ? 'Some deliveries failed; check the private application log.' : 'Reminder delivery completed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
